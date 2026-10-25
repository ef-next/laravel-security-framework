<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Exceptions;

use LogicException;

/**
 * Thrown whenever tenant-owned data is touched without an active tenant.
 *
 * This is a programming error, not a request error: the code path lacks
 * tenant middleware, a Tenancy::run() wrapper or an explicit
 * Tenancy::withoutTenant() block. It is never rendered as a 4xx.
 */
final class TenantContextMissing extends LogicException implements TenantException
{
    private const string HINT = ' Resolve a tenant first (tenant middleware), wrap the code in Tenancy::run($tenantId, fn () => ...), or declare a deliberate cross-tenant operation with Tenancy::withoutTenant("reason", fn () => ...).';

    public static function forQuery(string $model): self
    {
        return new self(sprintf('No tenant context: cannot query %s without an active tenant.', $model).self::HINT);
    }

    public static function forCreate(string $model, string $column): self
    {
        return new self(sprintf(
            'No tenant context: cannot create %s without an active tenant. Inside Tenancy::withoutTenant() the %s attribute must be set explicitly.',
            $model,
            $column
        ).self::HINT);
    }

    /**
     * @param  'update'|'delete'|'write'  $operation
     */
    public static function forWrite(string $operation, string $model): self
    {
        return new self(sprintf('No tenant context: cannot %s %s without an active tenant.', $operation, $model).self::HINT);
    }

    public static function required(): self
    {
        return new self('No tenant context is active.'.self::HINT);
    }
}
