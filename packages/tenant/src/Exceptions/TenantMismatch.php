<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Exceptions;

use LogicException;

/**
 * A model carries a tenant that differs from the active tenant context.
 */
final class TenantMismatch extends LogicException implements TenantException
{
    public static function onCreate(string $model, string $column, string $active, string $given): self
    {
        return new self(sprintf(
            'Refusing to create %s for tenant "%s" while tenant "%s" is active (%s must match the context or be left empty).',
            $model,
            $given,
            $active,
            $column
        ));
    }

    /**
     * @param  'update'|'delete'|'write'  $operation
     */
    public static function onWrite(string $operation, string $model, string $active, string $owner): self
    {
        return new self(sprintf(
            'Refusing to %s %s owned by tenant "%s" while tenant "%s" is active. A model loaded in one tenant context cannot be written in another; reload it inside the active context or declare a deliberate cross-tenant operation with Tenancy::withoutTenant("reason", fn () => ...).',
            $operation,
            $model,
            $owner,
            $active
        ));
    }
}
