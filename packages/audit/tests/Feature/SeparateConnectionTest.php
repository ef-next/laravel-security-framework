<?php

declare(strict_types=1);

use Efnext\Security\Audit\AuditManager;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Contracts\AuditWriter;
use Efnext\Security\Audit\Contracts\ChainVerifier;
use Efnext\Security\Audit\Doctor\CheckStatus;
use Efnext\Security\Audit\Doctor\SecurityDoctor;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Audit\Verification\VerificationRuns;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * AUDIT_DB_CONNECTION: the audit tables live on their own connection (own
 * database, own user with minimal grants). Everything audit-related has to
 * follow that connection, and the loss of shared transactions must be visible.
 */

beforeEach(function (): void {
    $this->skipUnlessDriver('sqlite'); // a second in-memory database is the cheapest truly separate database

    Tenancy::clear();

    config()->set('database.connections.audit', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    config()->set('audit.connection', 'audit');

    foreach ([AuditWriter::class, ChainVerifier::class, AnchorStore::class, AuditManager::class, VerificationRuns::class, SecurityDoctor::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
    Audit::clearResolvedInstance(AuditManager::class);

    // The audit migration follows audit.connection; everything else stays on "testing".
    $this->artisan('migrate:fresh', ['--database' => 'testing'])->run();
});

it('keeps every audit table and write on the audit connection', function (): void {
    expect(Schema::connection('audit')->hasTable('audit_events'))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('audit_events'))->toBeFalse()
        ->and(Schema::connection('testing')->hasTable('tenants'))->toBeTrue();

    [$first, $second] = Chains::write(Chains::TENANT_A, 2);

    expect(DB::connection('audit')->table('audit_events')->count())->toBe(2)
        ->and(DB::connection('audit')->table('audit_chain_heads')->value('last_sequence'))->toBe(2)
        ->and($first->getConnectionName())->toBe('audit')
        ->and($second->fresh()?->sequence)->toBe(2);

    $this->artisan('audit:anchor')->assertSuccessful();
    $this->artisan('audit:verify')->assertSuccessful();

    expect(DB::connection('audit')->table('audit_anchors')->count())->toBe(1)
        ->and(DB::connection('audit')->table('audit_verification_runs')->count())->toBe(1)
        ->and(Audit::verifyTenant(Chains::TENANT_A)->isIntact())->toBeTrue()
        ->and(fn () => DB::connection('audit')->table('audit_events')->delete())->toThrow(Illuminate\Database\QueryException::class);
});

it('cannot share a transaction with the application connection, and says so', function (): void {
    $levels = [];

    Audit::transaction(function () use (&$levels): void {
        $levels = ['audit' => DB::connection('audit')->transactionLevel(), 'app' => DB::connection('testing')->transactionLevel()];
        Chains::write(Chains::TENANT_A, 1);
    });

    expect($levels)->toBe(['audit' => 1, 'app' => 0])
        ->and(DB::connection('audit')->table('audit_events')->count())->toBe(1);

    // A rolled-back application transaction leaves the audit record behind ...
    try {
        DB::connection('testing')->transaction(function (): void {
            Chains::write(Chains::TENANT_A, 1);
            throw new RuntimeException('business failure after the audit write');
        });
    } catch (RuntimeException) {
    }

    expect(DB::connection('audit')->table('audit_events')->count())->toBe(2);

    // ... which is exactly what the doctor warns about.
    $check = app(SecurityDoctor::class)->run(verifyTails: false)->find('separate_connection');

    expect($check?->status)->toBe(CheckStatus::Warn)
        ->and($check?->detail)->toContain('"audit"');
});
