<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Http\Middleware;

use Closure;
use DateTimeImmutable;
use Efnext\Security\Tenant\Context\TenantContext;
use Efnext\Security\Tenant\Contracts\TenantLocator;
use Efnext\Security\Tenant\Contracts\TenantResolver;
use Efnext\Security\Tenant\Exceptions\TenantIdentifierMissing;
use Efnext\Security\Tenant\Exceptions\TenantNotFound;
use Efnext\Security\Tenant\Exceptions\TenantSuspended;
use Efnext\Security\Tenant\Resolution\ResolverChain;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for a request and activates it.
 *
 *   Route::middleware(['auth', 'tenant'])            resolve + membership
 *   Route::middleware(['tenant.resolve'])            resolve only (tenant is required)
 *   Route::middleware(['tenant.resolve:optional'])   resolve if present (e.g. tenant picker)
 *
 * Outcomes: 400 when no identifier is present, 404 when it matches no tenant,
 * 403 when the tenant is not active. The context is cleared when the response
 * has been sent.
 */
final class ResolveTenant
{
    public const string REQUIRED = 'required';

    public const string OPTIONAL = 'optional';

    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantLocator $locator,
        private readonly TenantContextManager $manager,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = self::REQUIRED): Response
    {
        $this->manager->clear();

        $candidate = $this->resolver->resolve($request);

        if ($candidate === null) {
            if ($mode === self::OPTIONAL) {
                return $next($request);
            }

            throw TenantIdentifierMissing::inRequest($this->sources());
        }

        $record = $this->locator->locate($candidate->identifier)
            ?? throw TenantNotFound::forIdentifier($candidate->identifier);

        if (! $record->status->isActive()) {
            throw TenantSuspended::because($record->id, $record->status);
        }

        $this->manager->activate(new TenantContext(
            tenantId: $record->id,
            source: $candidate->source,
            publicId: $record->publicId,
            resolvedAt: new DateTimeImmutable,
        ));

        return $next($request);
    }

    public function terminate(): void
    {
        $this->manager->clear();
    }

    /**
     * @return list<string>
     */
    private function sources(): array
    {
        return $this->resolver instanceof ResolverChain ? $this->resolver->descriptions() : [$this->resolver->describe()];
    }
}
