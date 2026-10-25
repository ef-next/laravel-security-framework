<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Eloquent;

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Exceptions\TenantColumnImmutable;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Exceptions\TenantMismatch;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marks a model as owned by exactly one tenant. Fail-closed:
 *
 *  - every query is filtered to the active tenant; without a tenant it throws
 *  - on create the tenant column is set from the context; a conflicting value throws
 *  - the tenant column can never change on a persisted record (model save,
 *    query-level update/increment/decrement/upsert alike)
 *  - query-level insert/insertOrIgnore/insertGetId/upsert stamp the active tenant
 *    and refuse conflicting or missing tenants (insertUsing() is not covered)
 *  - updating or deleting a record that belongs to another tenant throws, even
 *    if the model instance was loaded earlier in a different context (route model
 *    binding before tenant resolution, serialized job payloads, restore())
 *  - every UPDATE/DELETE issued for a model instance is additionally constrained
 *    to the active tenant at SQL level, so event-less writes (saveQuietly(),
 *    withoutEvents()) cannot reach another tenant's row either
 *  - the tenant column is excluded from mass assignment
 *
 * Inside Tenancy::withoutTenant() queries are unfiltered and creates need an
 * explicit tenant column. Inside Tenancy::run($tenant) everything is scoped to
 * that tenant.
 *
 * Migration: $table->tenantId() adds the column with a foreign key to tenants.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (Model $model): void {
            /** @var Model&self $model */
            $model->assignTenantOnCreate();
        });

        static::updating(static function (Model $model): void {
            /** @var Model&self $model */
            if ($model->isDirty($model->tenantColumn())) {
                throw TenantColumnImmutable::forModel($model::class, $model->tenantColumn());
            }

            $model->assertOwnedByActiveTenant('update');
        });

        static::deleting(static function (Model $model): void {
            /** @var Model&self $model */
            $model->assertOwnedByActiveTenant('delete');
        });
    }

    /**
     * Every UPDATE/DELETE Eloquent issues for a model instance (save, update,
     * increment, decrement, touch, restore, delete, soft delete, and their
     * *Quietly() variants) passes through here. Re-check ownership and constrain
     * the statement to the tenant, so a model loaded in another context can never
     * write another tenant's row, with or without model events.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        parent::setKeysForSaveQuery($query);

        if ($this->isDirty($this->tenantColumn())) {
            throw TenantColumnImmutable::forModel(static::class, $this->tenantColumn());
        }

        $this->assertOwnedByActiveTenant('write');

        $tenantId = $this->expectedTenantForWrite();

        if ($tenantId !== null) {
            $query->where($this->qualifyColumn($this->tenantColumn()), '=', $tenantId);
        }

        return $query;
    }

    /**
     * Keep the tenant column out of mass assignment unless the model opted in explicitly.
     */
    public function initializeBelongsToTenant(): void
    {
        $column = $this->tenantColumn();

        if ($this->guarded === ['*'] || in_array($column, $this->fillable, true) || in_array($column, $this->guarded, true)) {
            return;
        }

        $this->guarded[] = $column;
    }

    /**
     * Use a builder that guards the tenant column at query level. A custom
     * builder (#[UseEloquentBuilder], static $builder) is honoured as long as it
     * carries the guard; an unguarded custom builder is refused.
     *
     * Models that also use Laravel's HasBuilder trait must resolve the conflict
     * with `use BelongsToTenant, HasBuilder { BelongsToTenant::newEloquentBuilder insteadof HasBuilder; }`.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Builder<*>
     */
    public function newEloquentBuilder($query)
    {
        $builder = parent::newEloquentBuilder($query);

        if ($builder::class === Builder::class) {
            return new TenantAwareBuilder($query);
        }

        if (in_array(GuardsTenantColumn::class, class_uses_recursive($builder), true)) {
            return $builder;
        }

        throw new LogicException(sprintf(
            '%s uses the Eloquent builder %s, which does not guard the tenant column. Extend %s or use the %s trait.',
            static::class,
            $builder::class,
            TenantAwareBuilder::class,
            GuardsTenantColumn::class,
        ));
    }

    public function tenantColumn(): string
    {
        if (property_exists($this, 'tenantColumn') && is_string($this->tenantColumn) && $this->tenantColumn !== '') {
            return $this->tenantColumn;
        }

        $configured = config('tenant.column', 'tenant_id');

        return is_string($configured) && $configured !== '' ? $configured : 'tenant_id';
    }

    public function tenantId(): ?TenantId
    {
        return TenantId::tryFromString($this->stringAttribute($this->tenantColumn()));
    }

    public function belongsToTenant(TenantId|string $tenantId): bool
    {
        $own = $this->tenantId();
        $other = $tenantId instanceof TenantId ? $tenantId : TenantId::tryFromString($tenantId);

        return $own !== null && $own->equals($other);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        /** @var class-string<Tenant> $tenantModel */
        $tenantModel = config('tenant.model', Tenant::class);

        return $this->belongsTo($tenantModel, $this->tenantColumn());
    }

    /**
     * Explicit filter for use inside Tenancy::withoutTenant() blocks, e.g. for
     * cross-tenant reports that iterate tenants one at a time.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, TenantId|string $tenantId): Builder
    {
        $id = $tenantId instanceof TenantId ? $tenantId : TenantId::fromString($tenantId);

        return $query->where($this->qualifyColumn($this->tenantColumn()), $id->toString());
    }

    protected function assignTenantOnCreate(): void
    {
        /** @var TenantContextManager $manager */
        $manager = app(TenantContextManager::class);
        $column = $this->tenantColumn();
        $given = $this->stringAttribute($column);

        if ($manager->isBypassed()) {
            if ($given === null) {
                throw TenantContextMissing::forCreate(static::class, $column);
            }

            return;
        }

        $context = $manager->current() ?? throw TenantContextMissing::forCreate(static::class, $column);

        if ($given === null) {
            $this->setAttribute($column, $context->tenantId->toString());

            return;
        }

        if (! $context->is($given)) {
            throw TenantMismatch::onCreate(static::class, $column, $context->tenantId->toString(), $given);
        }
    }

    /**
     * The persisted record must belong to the active tenant. Ownership is read
     * from the original attributes (what was loaded from the database), so a
     * manipulated in-memory value cannot fake it. If the tenant column was not
     * selected, ownership is unknown here; the SQL constraint added in
     * setKeysForSaveQuery() then makes a foreign write affect zero rows.
     *
     * @param  'update'|'delete'|'write'  $operation
     */
    protected function assertOwnedByActiveTenant(string $operation): void
    {
        /** @var TenantContextManager $manager */
        $manager = app(TenantContextManager::class);

        if ($manager->isBypassed()) {
            return;
        }

        $context = $manager->current() ?? throw TenantContextMissing::forWrite($operation, static::class);
        $owner = $this->persistedTenantId();

        if ($owner !== null && ! $context->is($owner)) {
            throw TenantMismatch::onWrite($operation, static::class, $context->tenantId->toString(), $owner);
        }
    }

    /**
     * The tenant an UPDATE/DELETE for this instance must be constrained to: the
     * active tenant, or inside a bypass the tenant the record was loaded with.
     */
    protected function expectedTenantForWrite(): ?string
    {
        /** @var TenantContextManager $manager */
        $manager = app(TenantContextManager::class);

        if (! $manager->isBypassed() && ($context = $manager->current()) !== null) {
            return $context->tenantId->toString();
        }

        return $this->persistedTenantId();
    }

    /**
     * The tenant the record was loaded with (falls back to the current attribute
     * for instances that were never hydrated from the database).
     */
    protected function persistedTenantId(): ?string
    {
        $column = $this->tenantColumn();

        return $this->normaliseTenantValue($this->getRawOriginal($column))
            ?? $this->normaliseTenantValue($this->getAttribute($column));
    }

    private function stringAttribute(string $column): ?string
    {
        return $this->normaliseTenantValue($this->getAttribute($column));
    }

    private function normaliseTenantValue(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
