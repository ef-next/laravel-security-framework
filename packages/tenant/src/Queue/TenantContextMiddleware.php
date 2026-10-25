<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Queue;

use Closure;
use Efnext\Security\Tenant\Context\TenantContext;
use Efnext\Security\Tenant\Resolution\Source;
use Efnext\Security\Tenant\TenantContextManager;
use LogicException;

/**
 * Job middleware for TenantAware jobs:
 *
 *   public function middleware(): array
 *   {
 *       return [new TenantContextMiddleware];
 *   }
 */
final class TenantContextMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof TenantAware) {
            throw new LogicException(sprintf('%s must implement %s to use %s.', $job::class, TenantAware::class, self::class));
        }

        /** @var TenantContextManager $manager */
        $manager = app(TenantContextManager::class);

        return $manager->run(TenantContext::for($job->tenantId(), Source::Queue), static fn (): mixed => $next($job));
    }
}
