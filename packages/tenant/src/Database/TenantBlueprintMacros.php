<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use LogicException;
use ReflectionProperty;

/**
 * Schema helpers for tenant-owned tables.
 *
 *   $table->tenantId();                                  // tenant column + FK to tenants + index + UNIQUE(tenant_id, id)
 *   $table->tenantForeignId('invoice_id', 'invoices');   // FK (tenant_id, invoice_id) -> invoices(tenant_id, id)
 *   $table->tenantForeignUuid('customer_id', 'customers');
 *   $table->tenantForeignUlid('order_id', 'orders');
 *
 * The composite foreign keys are defence in depth below Eloquent: a row can
 * only reference a parent of the same tenant, whatever code path wrote it
 * (raw SQL, DB::table(), a removed global scope, a bug). The database rejects
 * cross-tenant relations, on PostgreSQL, MySQL, MariaDB and SQLite alike.
 * The UNIQUE(tenant_id, id) index that tenantId() adds is what makes the
 * parent referenceable this way.
 */
final class TenantBlueprintMacros
{
    public static function register(): void
    {
        Blueprint::macro('tenantId', function (?string $column = null, bool $constrained = true, bool $compositeKey = true, string $primaryKey = 'id'): ForeignIdColumnDefinition {
            /** @var Blueprint $this */
            $column ??= TenantBlueprintMacros::tenantColumn();
            $definition = $this->foreignUuid($column);
            $definition->index();

            if ($constrained) {
                $definition->constrained(TenantBlueprintMacros::tenantsTable())->cascadeOnUpdate()->restrictOnDelete();
            }

            if ($compositeKey) {
                $this->unique([$column, $primaryKey]);
            }

            return $definition;
        });

        Blueprint::macro('tenantForeignId', function (string $column, string $on, string $references = 'id', bool $nullable = false, ?string $tenantColumn = null): TenantForeignKeyDefinition {
            /** @var Blueprint $this */
            return TenantBlueprintMacros::compositeForeign($this, $this->foreignId($column), $column, $on, $references, $nullable, $tenantColumn);
        });

        Blueprint::macro('tenantForeignUuid', function (string $column, string $on, string $references = 'id', bool $nullable = false, ?string $tenantColumn = null): TenantForeignKeyDefinition {
            /** @var Blueprint $this */
            return TenantBlueprintMacros::compositeForeign($this, $this->foreignUuid($column), $column, $on, $references, $nullable, $tenantColumn);
        });

        Blueprint::macro('tenantForeignUlid', function (string $column, string $on, string $references = 'id', bool $nullable = false, ?string $tenantColumn = null): TenantForeignKeyDefinition {
            /** @var Blueprint $this */
            return TenantBlueprintMacros::compositeForeign($this, $this->foreignUlid($column), $column, $on, $references, $nullable, $tenantColumn);
        });
    }

    /**
     * Composite FK (tenant, column) -> on(tenant, references). Returns the
     * foreign key definition so callers can chain ->cascadeOnDelete() etc.
     * nullOnDelete()/nullOnUpdate() are refused (see TenantForeignKeyDefinition).
     */
    public static function compositeForeign(Blueprint $table, ForeignIdColumnDefinition $definition, string $column, string $on, string $references, bool $nullable, ?string $tenantColumn): TenantForeignKeyDefinition
    {
        $tenantColumn ??= self::tenantColumn();

        if ($nullable) {
            $definition->nullable();
        }

        // Explicit index first: PostgreSQL does not index FK columns by itself and
        // MySQL reuses an existing index instead of creating a second one.
        $table->index([$tenantColumn, $column]);

        $foreign = new TenantForeignKeyDefinition($table->foreign([$tenantColumn, $column])->getAttributes());
        self::replaceLastCommand($table, $foreign);
        $foreign->references([$tenantColumn, $references])->on($on);

        return $foreign;
    }

    /**
     * Blueprint::foreign() has just appended its own ForeignKeyDefinition as the
     * last command and offers no way to substitute it; swap it for the guarded
     * one so the grammar compiles ours. The property is read and written
     * through reflection because the blueprint keeps its command list protected.
     */
    private static function replaceLastCommand(Blueprint $table, ForeignKeyDefinition $command): void
    {
        $property = new ReflectionProperty(Blueprint::class, 'commands');
        $commands = $property->getValue($table);
        $last = is_array($commands) ? array_key_last($commands) : null;
        $current = $last === null ? null : $commands[$last];

        if (! $current instanceof ForeignKeyDefinition || $current->get('name') !== 'foreign' || $current->getAttributes() !== $command->getAttributes()) {
            throw new LogicException('Expected the last blueprint command to be the composite tenant foreign key that was just added.');
        }

        $commands[$last] = $command;
        $property->setValue($table, $commands);
    }

    public static function tenantColumn(): string
    {
        $column = config('tenant.column', 'tenant_id');

        return is_string($column) && $column !== '' ? $column : 'tenant_id';
    }

    public static function tenantsTable(): string
    {
        $table = config('tenant.table', 'tenants');

        return is_string($table) && $table !== '' ? $table : 'tenants';
    }
}
