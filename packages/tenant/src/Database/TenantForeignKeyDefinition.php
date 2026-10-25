<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Database;

use Illuminate\Database\Schema\ForeignKeyDefinition;
use LogicException;

/**
 * The foreign key definition returned by tenantForeignId() / tenantForeignUuid()
 * / tenantForeignUlid(). It refuses the one referential action a composite
 * tenant key cannot have: SET NULL (and SET DEFAULT).
 *
 * The key is (tenant_id, invoice_id) -> invoices(tenant_id, id). "SET NULL"
 * would have to clear both columns, including the NOT NULL tenant column.
 * MySQL and MariaDB refuse to create such a constraint (errno 150, "Foreign
 * key constraint is incorrectly formed"); PostgreSQL and SQLite create it and
 * then refuse the parent delete with a NOT NULL violation. Either way the
 * migration passes on SQLite in tests and fails somewhere else later, so it is
 * refused here, at migration authoring time, on every driver.
 *
 * An optional relation is `tenantForeignId('invoice_id', 'invoices', nullable: true)`
 * with cascadeOnDelete() or restrictOnDelete(); detaching happens in application
 * code by setting the column to NULL.
 */
final class TenantForeignKeyDefinition extends ForeignKeyDefinition
{
    /**
     * @return $this
     */
    public function onDelete(string $action): static
    {
        return $this->action('onDelete', $action);
    }

    /**
     * @return $this
     */
    public function onUpdate(string $action): static
    {
        return $this->action('onUpdate', $action);
    }

    /**
     * @return $this
     */
    private function action(string $attribute, string $action): static
    {
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $action)));

        if (in_array($normalized, ['set null', 'set default'], true)) {
            throw new LogicException(sprintf(
                'A composite tenant foreign key cannot use %s %s: the tenant column would have to be cleared as well '
                .'(MySQL/MariaDB refuse to create the constraint, PostgreSQL/SQLite refuse the parent row change). '
                .'Use cascadeOn%s() or restrictOn%s(); to detach an optional relation, set the column to NULL in application code.',
                $attribute === 'onDelete' ? 'ON DELETE' : 'ON UPDATE',
                strtoupper($normalized),
                $attribute === 'onDelete' ? 'Delete' : 'Update',
                $attribute === 'onDelete' ? 'Delete' : 'Update',
            ));
        }

        $this->attributes[$attribute] = $action;

        return $this;
    }
}
