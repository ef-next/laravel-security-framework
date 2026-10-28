<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Eloquent;

use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LogicException;

/**
 * Global scope applied by BelongsToTenant.
 *
 * - active tenant  -> WHERE <table>.<tenant column> = <tenant id>
 * - bypass block   -> no filter (Tenancy::withoutTenant("reason", ...))
 * - no context     -> TenantContextMissing. Never an unfiltered query.
 *
 * Eloquent's withoutGlobalScope(TenantScope::class) still exists; treat any
 * use of it as a code-review finding. The supported way to read across
 * tenants is Tenancy::withoutTenant(), which is evented and auditable.
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContextManager $manager */
        $manager = app(TenantContextManager::class);

        if ($manager->isBypassed()) {
            return;
        }

        $context = $manager->current();

        if ($context === null) {
            throw TenantContextMissing::forQuery($model::class);
        }

        $builder->where($model->qualifyColumn(self::columnOf($model)), $context->tenantId->toString());
    }

    /**
     * The tenant column of a model that uses BelongsToTenant. The scope and the
     * guarded builder are only ever attached by that trait, so anything else is
     * a wiring error worth failing loudly on.
     */
    public static function columnOf(Model $model): string
    {
        $column = method_exists($model, 'tenantColumn') ? $model->tenantColumn() : null;

        if (! is_string($column) || $column === '') {
            throw new LogicException(sprintf('%s must use the %s trait to be tenant-scoped.', $model::class, BelongsToTenant::class));
        }

        return $column;
    }
}
