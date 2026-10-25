<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Exceptions;

use LogicException;

/**
 * Records never move between tenants through Eloquent.
 */
final class TenantColumnImmutable extends LogicException implements TenantException
{
    public static function forModel(string $model, string $column): self
    {
        return new self(sprintf(
            '%s: %s of a persisted record must not change. Moving data between tenants is not supported by the framework.',
            $model,
            $column
        ));
    }
}
