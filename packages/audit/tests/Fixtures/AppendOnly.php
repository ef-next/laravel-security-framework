<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Tests\Fixtures;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Test-only: simulate an attacker who removed the append-only triggers,
 * run a mutation, and put the triggers back.
 */
final class AppendOnly
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function bypass(Closure $callback, string $table = 'audit_events'): mixed
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $wrapped = $connection->getQueryGrammar()->wrapTable($table);
        $name = static fn (string $op): string => 'efnext_'.$table.'_no_'.$op;
        // Connection::unprepared() asks for a literal-string. These statements are
        // built from this fixture's own constants and the table name of the test.
        // @phpstan-ignore argument.type
        $unprepared = static fn (string $sql): bool => $connection->unprepared($sql);

        foreach (['update', 'delete'] as $op) {
            match ($driver) {
                'pgsql' => $unprepared(sprintf('DROP TRIGGER IF EXISTS %s ON %s', $name($op), $wrapped)),
                default => $unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $name($op))),
            };
        }

        try {
            return $callback();
        } finally {
            foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $op => $statement) {
                match ($driver) {
                    'pgsql' => $unprepared(sprintf(
                        'CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW EXECUTE FUNCTION efnext_audit_prevent_mutation()',
                        $name($op), $statement, $wrapped
                    )),
                    'mysql', 'mariadb' => $unprepared(sprintf(
                        "CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit records are append-only'",
                        $name($op), $statement, $wrapped
                    )),
                    default => $unprepared(sprintf(
                        "CREATE TRIGGER %s BEFORE %s ON %s BEGIN SELECT RAISE(ABORT, 'audit records are append-only'); END",
                        $name($op), $statement, $wrapped
                    )),
                };
            }
        }
    }
}
