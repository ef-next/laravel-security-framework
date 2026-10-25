<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Eloquent;

use Efnext\Security\Tenant\Exceptions\TenantColumnImmutable;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Exceptions\TenantMismatch;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query-level guards for the tenant column, for use in Eloquent builders of
 * tenant-owned models. Closes the paths that bypass model events:
 *
 *  - update()/increment()/decrement()/upsert(): the tenant column can never be written
 *  - insert()/insertOrIgnore()/insertGetId()/upsert(): rows get the active tenant, a
 *    conflicting tenant throws, and without a context (or an explicit column inside a
 *    bypass) the write is refused
 *
 * insertUsing()/insertOrIgnoreUsing() take a sub-query and cannot be inspected;
 * they stay unguarded and are documented as such.
 *
 * @mixin Builder<\Illuminate\Database\Eloquent\Model>
 */
trait GuardsTenantColumn
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $this->rejectTenantColumn(array_keys($values));

        return parent::update($values);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->rejectTenantColumn([$column, ...array_keys($extra)]);

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->rejectTenantColumn([$column, ...array_keys($extra)]);

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * Upserts stamp the tenant on every row and never write the tenant column on
     * conflict. Note that conflict detection itself matches any unique key: make
     * unique indexes on tenant-owned tables tenant-qualified ([tenant_id, ...]) or
     * pass a tenant-qualified $uniqueBy; otherwise a conflicting row of another
     * tenant is left untouched only for the tenant column.
     *
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        if ($values === []) {
            return 0;
        }

        if ($update !== null) {
            $columns = [];

            foreach ($update as $key => $value) {
                $columns[] = is_string($key) ? $key : (string) $value;
            }

            $this->rejectTenantColumn($columns);
        }

        $rows = $this->withTenantColumn($values);

        if ($update === null) {
            $first = reset($rows);
            $update = array_values(array_diff(array_keys(is_array($first) ? $first : []), [$this->guardedTenantColumn()]));
        }

        return parent::upsert($rows, $uniqueBy, $update);
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        return $this->toBase()->insert($this->withTenantColumn($values));
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        return $this->toBase()->insertOrIgnore($this->withTenantColumn($values));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function insertGetId(array $values, ?string $sequence = null): int
    {
        $rows = $this->withTenantColumn($values);

        return $this->toBase()->insertGetId(reset($rows) ?: [], $sequence);
    }

    /**
     * @param  array<int, mixed>  $columns
     */
    protected function rejectTenantColumn(array $columns): void
    {
        $tenantColumn = $this->guardedTenantColumn();

        foreach ($columns as $column) {
            if (! is_string($column)) {
                continue;
            }

            if ($column === $tenantColumn || str_ends_with($column, '.'.$tenantColumn)) {
                throw TenantColumnImmutable::forModel($this->getModel()::class, $tenantColumn);
            }
        }
    }

    /**
     * Normalise to a list of rows and stamp/verify the tenant column on each.
     *
     * @param  array<int|string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    protected function withTenantColumn(array $values): array
    {
        if ($values === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_is_list($values) && is_array(reset($values)) ? $values : [$values];

        /** @var TenantContextManager $manager */
        $manager = app(TenantContextManager::class);
        $column = $this->guardedTenantColumn();
        $model = $this->getModel()::class;

        foreach ($rows as $index => $row) {
            $given = isset($row[$column]) && (is_string($row[$column]) || is_int($row[$column])) ? (string) $row[$column] : null;

            if ($manager->isBypassed()) {
                if ($given === null) {
                    throw TenantContextMissing::forCreate($model, $column);
                }

                continue;
            }

            $context = $manager->current() ?? throw TenantContextMissing::forCreate($model, $column);

            if ($given === null) {
                $rows[$index][$column] = $context->tenantId->toString();

                continue;
            }

            if (! $context->is($given)) {
                throw TenantMismatch::onCreate($model, $column, $context->tenantId->toString(), $given);
            }
        }

        return $rows;
    }

    protected function guardedTenantColumn(): string
    {
        return TenantScope::columnOf($this->getModel());
    }
}
