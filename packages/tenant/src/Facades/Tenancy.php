<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Facades;

use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Efnext\Security\Tenant\Context\TenantContext|null current()
 * @method static \Efnext\Security\Tenant\Context\TenantId|null currentId()
 * @method static bool has()
 * @method static \Efnext\Security\Tenant\Context\TenantContext require()
 * @method static \Efnext\Security\Tenant\Context\TenantId requireId()
 * @method static bool is(\Efnext\Security\Tenant\Context\TenantId|string $tenantId)
 * @method static void activate(\Efnext\Security\Tenant\Context\TenantContext $context)
 * @method static void clear()
 * @method static mixed run(\Efnext\Security\Tenant\Context\TenantContext|\Efnext\Security\Tenant\Context\TenantId|string $tenant, \Closure $callback, \Efnext\Security\Tenant\Resolution\Source $source = \Efnext\Security\Tenant\Resolution\Source::Manual)
 * @method static mixed withoutTenant(string $reason, \Closure $callback)
 * @method static bool isBypassed()
 *
 * @see TenantContextManager
 */
final class Tenancy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantContextManager::class;
    }
}
