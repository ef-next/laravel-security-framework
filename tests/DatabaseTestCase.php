<?php

declare(strict_types=1);

namespace Efnext\Security\Tests;

/**
 * Test case with a freshly migrated database for every test.
 *
 * Deliberately not RefreshDatabase: several tests need real commits
 * (advisory locks, triggers, concurrency) that a wrapping transaction would hide.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--database' => 'testing'])->run();
    }
}
