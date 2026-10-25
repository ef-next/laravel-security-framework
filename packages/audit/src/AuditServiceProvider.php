<?php

declare(strict_types=1);

namespace Efnext\Security\Audit;

use Efnext\Security\Audit\Anchoring\Anchorer;
use Efnext\Security\Audit\Anchoring\CompositeAnchorStore;
use Efnext\Security\Audit\Anchoring\DatabaseAnchorStore;
use Efnext\Security\Audit\Anchoring\FilesystemAnchorStore;
use Efnext\Security\Audit\Anchoring\LogAnchorStore;
use Efnext\Security\Audit\Anchoring\NullAnchorStore;
use Efnext\Security\Audit\Console\AuditAnchorCommand;
use Efnext\Security\Audit\Console\AuditKeyGenerateCommand;
use Efnext\Security\Audit\Console\AuditVerifyCommand;
use Efnext\Security\Audit\Console\SecurityDoctorCommand;
use Efnext\Security\Audit\Contracts\ActorResolver;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Contracts\AuditWriter;
use Efnext\Security\Audit\Contracts\ChainVerifier;
use Efnext\Security\Audit\Contracts\MetadataSanitizer;
use Efnext\Security\Audit\Contracts\Signer;
use Efnext\Security\Audit\Doctor\ProductionGuard;
use Efnext\Security\Audit\Doctor\SecurityDoctor;
use Efnext\Security\Audit\Exceptions\InsecureConfiguration;
use Efnext\Security\Audit\Hashing\EventHasher;
use Efnext\Security\Audit\Listeners\RecordTenantSecurityEvents;
use Efnext\Security\Audit\Sanitizing\DefaultMetadataSanitizer;
use Efnext\Security\Audit\Sanitizing\SanitizerConfig;
use Efnext\Security\Audit\Signing\HmacSigner;
use Efnext\Security\Audit\Signing\Keyring;
use Efnext\Security\Audit\Signing\NullSigner;
use Efnext\Security\Audit\Verification\HashChainVerifier;
use Efnext\Security\Audit\Verification\VerificationRuns;
use Efnext\Security\Audit\Writing\DatabaseAuditWriter;
use Efnext\Security\Audit\Writing\RequestActorResolver;
use Efnext\Security\Audit\Writing\RequestContextResolver;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit.php', 'audit');

        $this->app->singleton(EventHasher::class);

        $this->app->singleton(Keyring::class, fn (): Keyring => Keyring::fromString(
            (string) $this->config('signing.keys', ''),
            (string) $this->config('signing.active_key', ''),
        ));

        $this->app->singleton(Signer::class, function (Container $app): Signer {
            /** @var Keyring $keyring */
            $keyring = $app->make(Keyring::class);

            return $keyring->isEmpty() ? new NullSigner : new HmacSigner($keyring);
        });

        $this->app->singleton(SanitizerConfig::class, function (): SanitizerConfig {
            /** @var array<string, mixed> $config */
            $config = $this->config('sanitizer', []);

            return SanitizerConfig::fromArray($config, $this->derivedPseudonymKey());
        });

        $this->app->singleton(MetadataSanitizer::class, fn (Container $app): MetadataSanitizer => new DefaultMetadataSanitizer($app->make(SanitizerConfig::class)));

        $this->app->singleton(RequestContextResolver::class, fn (Container $app): RequestContextResolver => new RequestContextResolver(
            container: $app,
            enabled: (bool) $this->config('request.enrich', true),
            requestIdHeader: (string) $this->config('request.request_id_header', 'X-Request-Id'),
            correlationIdHeader: (string) $this->config('request.correlation_id_header', 'X-Correlation-Id'),
        ));

        $this->app->singleton(ActorResolver::class, fn (Container $app): ActorResolver => new RequestActorResolver(
            auth: $app->make(AuthFactory::class),
            requests: $app->make(RequestContextResolver::class),
        ));

        $this->app->singleton(AnchorStore::class, fn (Container $app): AnchorStore => $this->buildAnchorStore($app));

        $this->app->singleton(Anchorer::class, fn (Container $app): Anchorer => new Anchorer(
            connections: $app->make(ConnectionResolverInterface::class),
            store: $app->make(AnchorStore::class),
            signer: $app->make(Signer::class),
            connection: $this->connectionName(),
            headsTable: (string) $this->config('heads_table', 'audit_chain_heads'),
        ));

        $this->app->singleton(AuditWriter::class, fn (Container $app): AuditWriter => new DatabaseAuditWriter(
            connections: $app->make(ConnectionResolverInterface::class),
            tenancy: $app->make(TenantContextManager::class),
            actors: $app->make(ActorResolver::class),
            requests: $app->make(RequestContextResolver::class),
            sanitizer: $app->make(MetadataSanitizer::class),
            hasher: $app->make(EventHasher::class),
            signer: $app->make(Signer::class),
            container: $app,
            connection: $this->connectionName(),
            headsTable: (string) $this->config('heads_table', 'audit_chain_heads'),
            tenantPolicy: (string) $this->config('tenant_policy', 'required'),
            writeAttempts: max(1, (int) $this->config('write.attempts', 5)),
        ));

        $this->app->singleton(ChainVerifier::class, fn (Container $app): ChainVerifier => new HashChainVerifier(
            connections: $app->make(ConnectionResolverInterface::class),
            hasher: $app->make(EventHasher::class),
            signer: $app->make(Signer::class),
            anchors: $app->make(AnchorStore::class),
            container: $app,
            connection: $this->connectionName(),
            headsTable: (string) $this->config('heads_table', 'audit_chain_heads'),
            chunkSize: max(1, (int) $this->config('verification.chunk_size', 1000)),
        ));

        $this->app->singleton(VerificationRuns::class, fn (Container $app): VerificationRuns => new VerificationRuns(
            connections: $app->make(ConnectionResolverInterface::class),
            connection: $this->connectionName(),
            table: (string) $this->config('verification_runs_table', 'audit_verification_runs'),
        ));

        $this->app->singleton(ProductionGuard::class, fn (Container $app): ProductionGuard => new ProductionGuard($app->make(ConfigRepository::class), $app->make(FilesystemFactory::class)));

        $this->app->singleton(SecurityDoctor::class, fn (Container $app): SecurityDoctor => new SecurityDoctor(
            container: $app,
            config: $app->make(ConfigRepository::class),
            connections: $app->make(ConnectionResolverInterface::class),
            guard: $app->make(ProductionGuard::class),
            runs: $app->make(VerificationRuns::class),
            connection: $this->connectionName(),
        ));

        $this->app->singleton(AuditManager::class, fn (Container $app): AuditManager => new AuditManager(
            writer: $app->make(AuditWriter::class),
            verifier: $app->make(ChainVerifier::class),
            connections: $app->make(ConnectionResolverInterface::class),
            connection: $this->connectionName(),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/audit.php' => $this->app->configPath('audit.php'),
        ], 'security-audit-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'security-audit-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->enforceProductionConfiguration();

        if ($this->app->runningInConsole()) {
            $this->commands([AuditVerifyCommand::class, AuditAnchorCommand::class, AuditKeyGenerateCommand::class, SecurityDoctorCommand::class]);
            $this->rememberConsoleRequest();
        }

        if ((bool) $this->config('record_tenant_events', true)) {
            /** @var Dispatcher $events */
            $events = $this->app->make(Dispatcher::class);
            $subscriber = new RecordTenantSecurityEvents(writer: $this->app->make(AuditWriter::class));
            $subscriber->subscribe($events);
        }
    }

    /**
     * Fail fast in production: a configuration without signing key, on SQLite,
     * without append-only enforcement or without a readable anchor store gives
     * none of the guarantees this package is installed for. Refusing to boot is
     * better than an audit log that only looks like one.
     */
    private function enforceProductionConfiguration(): void
    {
        /** @var Application $app */
        $app = $this->app;

        if (! $app->environment('production')) {
            return;
        }

        if (! filter_var($this->config('production.fail_fast', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        /** @var ProductionGuard $guard */
        $guard = $this->app->make(ProductionGuard::class);
        $guard->enforce();

        if ($guard->requiresExternalAnchor()) {
            // Configuration looked right; now the stores must actually come up (unknown disk, broken custom store).
            try {
                $this->app->make(AnchorStore::class);
            } catch (Throwable $e) {
                throw InsecureConfiguration::because([sprintf('The configured anchor stores (%s) cannot be instantiated: %s', implode(', ', $guard->configuredAnchorStores()), $e->getMessage())]);
            }
        }
    }

    /**
     * In the console Laravel binds a placeholder request before providers boot;
     * hand it to the resolver so console/queue writes are not attributed to it.
     */
    private function rememberConsoleRequest(): void
    {
        if (! $this->app->bound('request')) {
            return;
        }

        $request = $this->app->make('request');

        if ($request instanceof Request) {
            $this->app->make(RequestContextResolver::class)->rememberConsoleRequest($request);
        }
    }

    private function buildAnchorStore(Container $app): AnchorStore
    {
        /** @var list<string> $names */
        $names = (array) $this->config('anchoring.stores', ['database']);
        $stores = [];

        foreach ($names as $name) {
            $stores[] = match ($name) {
                'database' => new DatabaseAnchorStore(
                    $app->make(ConnectionResolverInterface::class),
                    $this->connectionName(),
                    (string) $this->config('anchors_table', 'audit_anchors'),
                ),
                'filesystem' => new FilesystemAnchorStore(
                    $app->make(FilesystemFactory::class)->disk((string) $this->config('anchoring.filesystem.disk', 'local')),
                    (string) $this->config('anchoring.filesystem.path', 'audit-anchors'),
                ),
                'log' => new LogAnchorStore($this->logger($app)),
                default => $this->customAnchorStore($app, $name),
            };
        }

        return match (count($stores)) {
            0 => new NullAnchorStore,
            1 => $stores[0],
            default => new CompositeAnchorStore(...$stores),
        };
    }

    private function customAnchorStore(Container $app, string $class): AnchorStore
    {
        if (! is_a($class, AnchorStore::class, true)) {
            throw new InvalidArgumentException(sprintf('audit.anchoring.stores entry "%s" is neither database, filesystem, log nor a class implementing %s.', $class, AnchorStore::class));
        }

        /** @var AnchorStore $store */
        $store = $app->make($class);

        return $store;
    }

    private function logger(Container $app): LoggerInterface
    {
        $channel = $this->config('anchoring.log.channel');
        $logger = $app->make(LoggerInterface::class);

        if (is_string($channel) && $channel !== '' && $logger instanceof LogManager) {
            return $logger->channel($channel);
        }

        return $logger;
    }

    /**
     * A pepper for pseudonymisation derived from APP_KEY, so hashed identifiers
     * are not dictionary-attackable without the application key.
     */
    private function derivedPseudonymKey(): ?string
    {
        $appKey = $this->app->make(ConfigRepository::class)->get('app.key');

        if (! is_string($appKey) || $appKey === '') {
            return null;
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? $appKey : $decoded;
        }

        return hash_hkdf('sha256', $appKey, 32, 'efnext-audit-pseudonym');
    }

    private function connectionName(): ?string
    {
        $value = $this->config('connection');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make(ConfigRepository::class)->get('audit.'.$key, $default);
    }
}
