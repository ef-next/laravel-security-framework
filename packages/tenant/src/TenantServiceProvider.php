<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant;

use Efnext\Security\Tenant\Console\TenantRunCommand;
use Efnext\Security\Tenant\Contracts\MembershipChecker;
use Efnext\Security\Tenant\Contracts\TenantLocator;
use Efnext\Security\Tenant\Contracts\TenantResolver;
use Efnext\Security\Tenant\Database\TenantBlueprintMacros;
use Efnext\Security\Tenant\Http\Middleware\EnsureTenantMember;
use Efnext\Security\Tenant\Http\Middleware\EnsureTokenTenant;
use Efnext\Security\Tenant\Http\Middleware\ResolveTenant;
use Efnext\Security\Tenant\Http\SessionTenant;
use Efnext\Security\Tenant\Locating\CachedTenantLocator;
use Efnext\Security\Tenant\Locating\DatabaseTenantLocator;
use Efnext\Security\Tenant\Membership\DatabaseMembershipChecker;
use Efnext\Security\Tenant\Membership\Roles;
use Efnext\Security\Tenant\Queue\PropagatesTenantContext;
use Efnext\Security\Tenant\Resolution\HeaderResolver;
use Efnext\Security\Tenant\Resolution\ResolverChain;
use Efnext\Security\Tenant\Resolution\RouteParameterResolver;
use Efnext\Security\Tenant\Resolution\SessionResolver;
use Efnext\Security\Tenant\Resolution\SubdomainResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tenant.php', 'tenant');

        $this->app->singleton(TenantContextManager::class, fn (Container $app): TenantContextManager => new TenantContextManager($app));

        $this->app->singleton(Roles::class, function (Container $app): Roles {
            /** @var list<string> $roles */
            $roles = $this->config('memberships.roles', ['owner', 'admin', 'member', 'viewer']);

            return new Roles($roles, (bool) $this->config('memberships.hierarchical', true));
        });

        $this->app->singleton(TenantResolver::class, fn (Container $app): TenantResolver => $this->buildResolverChain($app));

        $this->app->singleton(DatabaseTenantLocator::class, function (Container $app): DatabaseTenantLocator {
            /** @var list<string> $lookupBy */
            $lookupBy = $this->config('lookup.by', ['id', 'public_id', 'global_object_id', 'slug']);

            return new DatabaseTenantLocator(
                connections: $app->make(ConnectionResolverInterface::class),
                table: (string) $this->config('table', 'tenants'),
                connection: $this->optionalString($this->config('connection')),
                lookupBy: $lookupBy,
                slugColumn: (string) $this->config('lookup.slug_column', 'slug'),
                statusColumn: (string) $this->config('lookup.status_column', 'status'),
                serviceName: static fn (): string => (string) config('addressing.service_name', 'app'),
            );
        });

        $this->app->singleton(CachedTenantLocator::class, function (Container $app): CachedTenantLocator {
            $store = $this->optionalString($this->config('cache.store'));

            return new CachedTenantLocator(
                inner: $app->make(DatabaseTenantLocator::class),
                cache: $app->make(CacheFactory::class)->store($store),
                ttlSeconds: (int) $this->config('cache.ttl', 60),
                negativeTtlSeconds: (int) $this->config('cache.negative_ttl', 5),
                prefix: (string) $this->config('cache.prefix', 'efnext:tenant:'),
            );
        });

        $this->app->bind(TenantLocator::class, function (Container $app): TenantLocator {
            return (bool) $this->config('cache.enabled', true)
                ? $app->make(CachedTenantLocator::class)
                : $app->make(DatabaseTenantLocator::class);
        });

        $this->app->singleton(MembershipChecker::class, fn (Container $app): MembershipChecker => new DatabaseMembershipChecker(
            connections: $app->make(ConnectionResolverInterface::class),
            table: (string) $this->config('memberships.table', 'tenant_memberships'),
            connection: $this->optionalString($this->config('connection')),
        ));

        $this->app->singleton(SessionTenant::class, fn (Container $app): SessionTenant => new SessionTenant(
            memberships: $app->make(MembershipChecker::class),
            container: $app,
            sessionKey: (string) $this->config('resolution.session_key', 'efnext.tenant'),
        ));

        $this->app->bind(EnsureTokenTenant::class, fn (Container $app): EnsureTokenTenant => new EnsureTokenTenant(
            manager: $app->make(TenantContextManager::class),
            events: $app->make(Dispatcher::class),
            requireBinding: (bool) $this->config('tokens.require_binding', false),
        ));

        $this->app->singleton(PropagatesTenantContext::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/tenant.php' => $this->app->configPath('tenant.php'),
        ], 'security-tenant-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'security-tenant-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerMiddleware();
        $this->registerBlueprintMacros();
        $this->registerLifecycleHooks();
        $this->registerQueuePropagation();

        if ($this->app->runningInConsole()) {
            $this->commands([TenantRunCommand::class]);
        }
    }

    private function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('tenant.resolve', ResolveTenant::class);
        $router->aliasMiddleware('tenant.member', EnsureTenantMember::class);
        $router->aliasMiddleware('tenant.role', EnsureTenantMember::class);
        $router->aliasMiddleware('tenant.token', EnsureTokenTenant::class);

        // Order matters: authentication (your 'auth' middleware) -> resolve -> membership.
        $router->middlewareGroup('tenant', [ResolveTenant::class, EnsureTenantMember::class]);

        $this->registerMiddlewarePriority($router);
    }

    /**
     * Laravel sorts a route's middleware by the kernel's priority list, and the
     * 'web'/'api' groups contain SubstituteBindings. Without an entry for the
     * tenant middleware, implicit route model binding would run before the
     * tenant is resolved and every bound tenant-owned model would throw
     * TenantContextMissing (a 500 instead of 404/403). Place the tenant
     * middleware after authentication and immediately before SubstituteBindings.
     */
    private function registerMiddlewarePriority(Router $router): void
    {
        $apply = function (object $kernel) use ($router): void {
            if ($kernel instanceof HttpKernel) {
                $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, ResolveTenant::class);
                $kernel->addToMiddlewarePriorityAfter(ResolveTenant::class, EnsureTenantMember::class);
                $kernel->addToMiddlewarePriorityAfter(EnsureTenantMember::class, EnsureTokenTenant::class);
                // If the application removed SubstituteBindings from its priority list, put it back behind us.
                $kernel->addToMiddlewarePriorityAfter(EnsureTokenTenant::class, SubstituteBindings::class);

                return;
            }

            $router->middlewarePriority = self::prioritise($router->middlewarePriority);
        };

        // The HTTP kernel syncs its own list to the router whenever it changes, so
        // the kernel is the source of truth when there is one. In HTTP requests it
        // is resolved before providers boot; in console and test processes it may
        // be resolved later, in which case its constructor overwrites the router's
        // list and the afterResolving hook re-applies ours (registered for the
        // concrete kernel too, so it runs after bootstrap/app.php's priority()).
        if ($this->app->resolved(HttpKernelContract::class)) {
            $apply($this->app->make(HttpKernelContract::class));
        } else {
            $router->middlewarePriority = self::prioritise($router->middlewarePriority);
        }

        $this->app->afterResolving(HttpKernelContract::class, $apply);
        $this->app->afterResolving(HttpKernel::class, $apply);
    }

    /**
     * Insert the tenant middleware before SubstituteBindings in a priority list
     * (appending both if SubstituteBindings is absent).
     *
     * @param  array<int, string>  $priority
     * @return list<string>
     */
    public static function prioritise(array $priority): array
    {
        $ours = [ResolveTenant::class, EnsureTenantMember::class, EnsureTokenTenant::class];
        $priority = array_values(array_diff($priority, $ours));
        $index = array_search(SubstituteBindings::class, $priority, true);

        if ($index === false) {
            return [...$priority, ...$ours, SubstituteBindings::class];
        }

        array_splice($priority, $index, 0, $ours);

        return $priority;
    }

    private function registerBlueprintMacros(): void
    {
        // $table->tenantId(), $table->tenantForeignId('invoice_id', 'invoices'), ...
        TenantBlueprintMacros::register();
    }

    private function registerLifecycleHooks(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);
        $clear = function (): void {
            $this->app->make(TenantContextManager::class)->clear();
        };

        // Never let a tenant leak into the next request of a long-running worker.
        $events->listen(RequestHandled::class, $clear);

        // Octane is optional; listening by event name is harmless when it is absent.
        $events->listen([
            'Laravel\Octane\Events\RequestTerminated',
            'Laravel\Octane\Events\TaskTerminated',
            'Laravel\Octane\Events\TickTerminated',
        ], $clear);
    }

    private function registerQueuePropagation(): void
    {
        if (! (bool) $this->config('queue.propagate', true)) {
            return;
        }

        /** @var PropagatesTenantContext $propagation */
        $propagation = $this->app->make(PropagatesTenantContext::class);

        Queue::createPayloadUsing(static fn (): array => $propagation->payload());

        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);
        $events->listen(JobProcessing::class, [$propagation, 'onJobProcessing']);
        $events->listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], [$propagation, 'onJobFinished']);
    }

    private function buildResolverChain(Container $app): ResolverChain
    {
        /** @var list<string> $chain */
        $chain = $this->config('resolution.chain', ['header', 'route', 'session']);
        $resolvers = [];

        foreach ($chain as $entry) {
            $resolvers[] = match ($entry) {
                'header' => new HeaderResolver($this->stringList($this->config('resolution.header', ['X-Tenant-Id']))),
                'route' => new RouteParameterResolver($this->stringList($this->config('resolution.route_parameters', ['tenant']))),
                'session' => new SessionResolver((string) $this->config('resolution.session_key', 'efnext.tenant')),
                'subdomain' => new SubdomainResolver($this->stringList($this->config('resolution.subdomain.base_domains', []))),
                default => $this->customResolver($app, $entry),
            };
        }

        if ($resolvers === []) {
            throw new InvalidArgumentException('tenant.resolution.chain must contain at least one resolver.');
        }

        return new ResolverChain(...$resolvers);
    }

    private function customResolver(Container $app, string $class): TenantResolver
    {
        if (! is_a($class, TenantResolver::class, true)) {
            throw new InvalidArgumentException(sprintf('tenant.resolution.chain entry "%s" is neither a built-in resolver nor a class implementing %s.', $class, TenantResolver::class));
        }

        /** @var TenantResolver $resolver */
        $resolver = $app->make($class);

        return $resolver;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make(ConfigRepository::class)->get('tenant.'.$key, $default);
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }
}
