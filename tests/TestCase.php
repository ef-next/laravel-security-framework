<?php

declare(strict_types=1);

namespace Efnext\Security\Tests;

use Efnext\Security\Addressing\AddressingServiceProvider;
use Efnext\Security\Audit\AuditServiceProvider;
use Efnext\Security\Tenant\TenantServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Shared test case for all packages.
 *
 * The database driver is selected through DB_CONNECTION (sqlite|pgsql|mysql|mariadb).
 * Connection details come from the environment; see compose.yaml and the CI workflow.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AddressingServiceProvider::class,
            TenantServiceProvider::class,
            AuditServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $driver = self::configuredDriver();

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::connectionConfig($driver));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    public static function configuredDriver(): string
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';

        return in_array($driver, ['sqlite', 'pgsql', 'mysql', 'mariadb'], true) ? $driver : 'sqlite';
    }

    /**
     * @return array<string, mixed>
     */
    private static function connectionConfig(string $driver): array
    {
        return match ($driver) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('PGSQL_PORT') ?: 5432),
                'database' => getenv('PGSQL_DATABASE') ?: 'security',
                'username' => getenv('PGSQL_USERNAME') ?: 'security',
                'password' => getenv('PGSQL_PASSWORD') ?: 'secret',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('MYSQL_PORT') ?: 3306),
                'database' => getenv('MYSQL_DATABASE') ?: 'security',
                'username' => getenv('MYSQL_USERNAME') ?: 'security',
                'password' => getenv('MYSQL_PASSWORD') ?: 'secret',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'mariadb' => [
                'driver' => 'mariadb',
                'host' => getenv('MARIADB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('MARIADB_PORT') ?: 3306),
                'database' => getenv('MARIADB_DATABASE') ?: 'security',
                'username' => getenv('MARIADB_USERNAME') ?: 'security',
                'password' => getenv('MARIADB_PASSWORD') ?: 'secret',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }

    protected function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    protected function skipUnlessDriver(string ...$drivers): void
    {
        if (! in_array($this->driver(), $drivers, true)) {
            $this->markTestSkipped('Requires one of: '.implode(', ', $drivers));
        }
    }
}
