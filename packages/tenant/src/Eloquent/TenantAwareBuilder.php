<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Default Eloquent builder for tenant-owned models. Extend it (or use the
 * GuardsTenantColumn trait in your own builder) when a model needs a custom
 * builder; BelongsToTenant refuses builders without the guard.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class TenantAwareBuilder extends Builder
{
    use GuardsTenantColumn;
}
