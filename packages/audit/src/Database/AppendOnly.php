<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Database;

use Efnext\Security\Audit\Exceptions\UnsupportedDatabaseDriver;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

/**
 * Names and detection of the append-only triggers the migration installs.
 * Shared by the migration and security:doctor so both agree on what
 * "protected" means.
 */
final class AppendOnly
{
    public const array OPERATIONS = ['update', 'delete'];

    public static function triggerName(string $table, string $operation): string
    {
        return 'efnext_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($table)).'_no_'.$operation;
    }

    /**
     * @return list<string>
     */
    public static function expectedTriggers(string $table): array
    {
        return array_map(static fn (string $operation): string => self::triggerName($table, $operation), self::OPERATIONS);
    }

    /**
     * Triggers that should protect the table but are not installed.
     *
     * @return list<string>
     *
     * @throws UnsupportedDatabaseDriver
     */
    public static function missingTriggers(Connection|ConnectionInterface $connection, string $table): array
    {
        $installed = self::installedTriggers($connection, $table);

        return array_values(array_diff(self::expectedTriggers($table), $installed));
    }

    /**
     * @return list<string>
     *
     * @throws UnsupportedDatabaseDriver
     */
    public static function installedTriggers(Connection|ConnectionInterface $connection, string $table): array
    {
        $driver = $connection instanceof Connection ? $connection->getDriverName() : 'unknown';

        $rows = match ($driver) {
            'pgsql' => $connection->select(
                'select t.tgname as name from pg_trigger t join pg_class c on c.oid = t.tgrelid where c.relname = ? and not t.tgisinternal',
                [$table]
            ),
            'mysql', 'mariadb' => $connection->select(
                'select TRIGGER_NAME as name from information_schema.TRIGGERS where TRIGGER_SCHEMA = database() and EVENT_OBJECT_TABLE = ?',
                [$table]
            ),
            'sqlite' => $connection->select(
                "select name from sqlite_master where type = 'trigger' and tbl_name = ?",
                [$table]
            ),
            default => throw UnsupportedDatabaseDriver::forAppendOnly($driver),
        };

        $names = [];

        foreach ($rows as $row) {
            $name = ((array) $row)['name'] ?? null;

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
