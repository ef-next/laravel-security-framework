<?php

declare(strict_types=1);

use Efnext\Security\Audit\AuditServiceProvider;
use Efnext\Security\Audit\Doctor\CheckStatus;
use Efnext\Security\Audit\Doctor\DoctorReport;
use Efnext\Security\Audit\Doctor\SecurityDoctor;
use Efnext\Security\Audit\Exceptions\InsecureConfiguration;
use Efnext\Security\Audit\Tests\Fixtures\AppendOnly;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Audit\Verification\VerificationRuns;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Tenancy::clear();
});

function doctor(): DoctorReport
{
    return app(SecurityDoctor::class)->run();
}

function status(DoctorReport $report, string $id): CheckStatus
{
    $check = $report->find($id);

    expect($check)->not->toBeNull($id.' missing from report');

    return $check->status;
}

it('describes a fresh installation truthfully', function (): void {
    $report = doctor();

    expect(status($report, 'signing_key'))->toBe(CheckStatus::Ok)
        ->and(status($report, 'app_key'))->toBe(CheckStatus::Ok)
        ->and(status($report, 'append_only_config'))->toBe(CheckStatus::Ok)
        ->and(status($report, 'append_only_triggers'))->toBe(CheckStatus::Ok)
        ->and(status($report, 'anchor_stores'))->toBe(CheckStatus::Warn)   // database only: not independent
        ->and(status($report, 'separate_connection'))->toBe(CheckStatus::Ok)
        ->and(status($report, 'anchor_freshness'))->toBe(CheckStatus::Skip)
        ->and(status($report, 'verification_recency'))->toBe(CheckStatus::Skip)
        ->and(status($report, 'tail_verification'))->toBe(CheckStatus::Skip)
        ->and(status($report, 'middleware_priority'))->toBe(CheckStatus::Ok)
        ->and(status($report, 'tenant_cache'))->toBe(CheckStatus::Ok)
        ->and(status($report, 'database_driver'))->toBe($this->driver() === 'sqlite' ? CheckStatus::Fail : CheckStatus::Ok)
        ->and($report->hasFailures())->toBe($this->driver() === 'sqlite');
});

it('fails without a signing key and with append-only enforcement disabled', function (): void {
    config()->set('audit.signing.keys', '');
    config()->set('audit.signing.active_key', '');
    config()->set('audit.enforce_append_only', false);

    $report = doctor();

    expect(status($report, 'signing_key'))->toBe(CheckStatus::Fail)
        ->and($report->find('signing_key')?->hint)->toContain('audit:key:generate')
        ->and(status($report, 'append_only_config'))->toBe(CheckStatus::Fail);
});

it('detects dropped append-only triggers', function (): void {
    $connection = DB::connection();
    $trigger = 'efnext_audit_events_no_delete';

    match ($connection->getDriverName()) {
        'pgsql' => $connection->unprepared(sprintf('DROP TRIGGER %s ON audit_events', $trigger)),
        default => $connection->unprepared(sprintf('DROP TRIGGER %s', $trigger)),
    };

    try {
        $check = doctor()->find('append_only_triggers');

        expect($check?->status)->toBe(CheckStatus::Fail)
            ->and($check?->detail)->toContain($trigger)
            ->and($check?->detail)->not->toContain('efnext_audit_events_no_update');
    } finally {
        // AppendOnly::bypass() recreates every trigger for the table on the way out.
        AppendOnly::bypass(fn () => null);
    }

    expect(doctor()->find('append_only_triggers')?->status)->toBe(CheckStatus::Ok);
});

it('flags write-only or missing anchor stores and accepts an external one', function (): void {
    config()->set('audit.anchoring.stores', ['log']);
    app()->forgetInstance(Efnext\Security\Audit\Contracts\AnchorStore::class);

    expect(doctor()->find('anchor_stores')?->status)->toBe(CheckStatus::Fail);

    config()->set('audit.anchoring.stores', []);
    app()->forgetInstance(Efnext\Security\Audit\Contracts\AnchorStore::class);

    expect(doctor()->find('anchor_stores')?->status)->toBe(CheckStatus::Fail);

    Illuminate\Support\Facades\Storage::fake('anchors');
    config()->set('audit.anchoring.stores', ['filesystem', 'database']);
    config()->set('audit.anchoring.filesystem.disk', 'anchors');
    app()->forgetInstance(Efnext\Security\Audit\Contracts\AnchorStore::class);

    expect(doctor()->find('anchor_stores')?->status)->toBe(CheckStatus::Ok);
});

it('flags chains whose newest events stay unanchored for too long', function (): void {
    Chains::write(Chains::TENANT_A, 2);

    // Fresh events inside the window: pending, not stale.
    expect(doctor()->find('anchor_freshness')?->status)->toBe(CheckStatus::Ok);

    DB::table('audit_chain_heads')->update(['updated_at' => now()->subHours(3)]);

    $check = doctor()->find('anchor_freshness');

    expect($check?->status)->toBe(CheckStatus::Fail)
        ->and($check?->detail)->toContain('unanchored')
        ->and($check?->hint)->toContain('audit:anchor');

    $this->artisan('audit:anchor')->assertSuccessful();

    expect(doctor()->find('anchor_freshness')?->status)->toBe(CheckStatus::Ok);
});

it('tracks full verifications and their outcome', function (): void {
    Chains::write(Chains::TENANT_A, 3);

    // Doctor runs a tail verification itself, but that is not a full one.
    $first = doctor();

    expect(status($first, 'tail_verification'))->toBe(CheckStatus::Ok)
        ->and(status($first, 'verification_recency'))->toBe(CheckStatus::Warn)
        ->and(DB::table('audit_verification_runs')->where('source', VerificationRuns::SOURCE_DOCTOR)->count())->toBe(1);

    $this->artisan('audit:verify')->assertSuccessful();

    expect(doctor()->find('verification_recency')?->status)->toBe(CheckStatus::Ok)
        ->and(DB::table('audit_verification_runs')->where('source', VerificationRuns::SOURCE_VERIFY)->where('intact', true)->count())->toBe(1);

    // An old verification no longer counts.
    DB::table('audit_verification_runs')->update(['finished_at' => now()->subDays(3)]);

    expect(doctor()->find('verification_recency')?->status)->toBe(CheckStatus::Warn);

    // A failed verification is the loudest signal of all.
    [$first] = Chains::write(Chains::TENANT_B, 1);
    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $first->id)->update(['actor_id' => 'x']));
    $this->artisan('audit:verify')->assertFailed();

    $report = doctor();

    expect(status($report, 'verification_recency'))->toBe(CheckStatus::Fail)
        ->and(status($report, 'tail_verification'))->toBe(CheckStatus::Fail)
        ->and($report->find('tail_verification')?->detail)->toContain('tenant:'.Chains::TENANT_B);
});

it('warns when the audit tables live on a separate connection', function (): void {
    // Pin the audit tables to "testing" and let the application default point elsewhere.
    config()->set('audit.connection', 'testing');
    config()->set('database.default', 'other');
    config()->set('database.connections.other', config('database.connections.testing'));

    $check = doctor()->find('separate_connection');

    expect($check?->status)->toBe(CheckStatus::Warn)
        ->and($check?->hint)->toContain('atomicity');
});

it('prints a machine-readable report and honours --strict', function (): void {
    $this->artisan('security:doctor', ['--json' => true, '--no-verify' => true])
        ->expectsOutputToContain('"checks"')
        ->assertExitCode($this->driver() === 'sqlite' ? 1 : 0);

    // Database-only anchors are a warning; --strict turns it into a failure and names the missing profile switch.
    $this->artisan('security:doctor', ['--strict' => true, '--no-verify' => true])->assertFailed();

    Illuminate\Support\Facades\Artisan::call('security:doctor', ['--strict' => true, '--no-verify' => true]);

    expect(Illuminate\Support\Facades\Artisan::output())->toContain('[FAIL] Anchor stores', 'AUDIT_REQUIRE_EXTERNAL_ANCHOR is off');

    $this->artisan('security:doctor', ['--no-verify' => true])
        ->expectsOutputToContain('[SKIP] Tail verification')
        ->expectsOutputToContain('OK / WARN / FAIL / SKIP');
});

it('treats --strict as the deployment gate of the high-security profile', function (): void {
    // An external store that is merely configured passes the plain doctor …
    Illuminate\Support\Facades\Storage::fake('anchors');
    config()->set('audit.anchoring.stores', ['filesystem', 'database']);
    config()->set('audit.anchoring.filesystem.disk', 'anchors');
    app()->forgetInstance(Efnext\Security\Audit\Contracts\AnchorStore::class);

    $relaxed = app(SecurityDoctor::class)->run(verifyTails: false);
    $strict = app(SecurityDoctor::class)->run(verifyTails: false, strict: true);

    expect($relaxed->find('anchor_stores')?->status)->toBe(CheckStatus::Ok)
        // … but not the strict one: nothing would stop a later deployment from dropping the store again.
        ->and($strict->find('anchor_stores')?->status)->toBe(CheckStatus::Fail)
        ->and($strict->find('anchor_stores')?->detail)->toContain('AUDIT_REQUIRE_EXTERNAL_ANCHOR is off', 'filesystem')
        ->and($strict->find('anchor_stores')?->hint)->toContain('refuse to boot');

    // With the requirement enforced, strict is satisfied by the same configuration.
    config()->set('audit.production.require_external_anchor', true);

    $enforced = app(SecurityDoctor::class)->run(verifyTails: false, strict: true);

    expect($enforced->find('anchor_stores')?->status)->toBe(CheckStatus::Ok)
        ->and($enforced->find('anchor_stores')?->detail)->toContain('required');

    // The command hands the flag through, so the JSON a pipeline gates on carries the same verdict.
    Illuminate\Support\Facades\Artisan::call('security:doctor', ['--strict' => true, '--json' => true, '--no-verify' => true]);
    $json = json_decode(Illuminate\Support\Facades\Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $anchorStores = collect($json['checks'])->firstWhere('id', 'anchor_stores');

    expect($anchorStores['status'])->toBe('ok')
        ->and($anchorStores['detail'])->toContain('external: filesystem, required');

    config()->set('audit.production.require_external_anchor', false);

    $this->artisan('security:doctor', ['--strict' => true, '--json' => true, '--no-verify' => true])->assertFailed();

    Illuminate\Support\Facades\Artisan::call('security:doctor', ['--strict' => true, '--json' => true, '--no-verify' => true]);
    $json = json_decode(Illuminate\Support\Facades\Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect(collect($json['checks'])->firstWhere('id', 'anchor_stores')['status'])->toBe('fail')
        ->and(collect($json['checks'])->firstWhere('id', 'anchor_stores')['detail'])->toContain('AUDIT_REQUIRE_EXTERNAL_ANCHOR is off')
        ->and($json['healthy'])->toBeFalse()
        ->and($json['strict'])->toBeTrue();
});

it('reports healthy in JSON only under the mode the run was judged by', function (): void {
    $report = new DoctorReport([
        Efnext\Security\Audit\Doctor\Check::ok('a', 'A', 'fine'),
        Efnext\Security\Audit\Doctor\Check::warn('b', 'B', 'hm', 'do something'),
    ]);

    expect($report->toArray()['healthy'])->toBeTrue()
        ->and($report->toArray()['strict'])->toBeFalse()
        ->and($report->toArray(strict: true)['healthy'])->toBeFalse()
        ->and($report->toArray(strict: true)['strict'])->toBeTrue();
});

it('can require an anchor store outside the database, in the doctor and at boot', function (): void {
    $forget = fn () => app()->forgetInstance(Efnext\Security\Audit\Contracts\AnchorStore::class);
    $guard = app(Efnext\Security\Audit\Doctor\ProductionGuard::class);

    // Off by default: database-only anchoring is a warning and the guard has no objection.
    expect($guard->requiresExternalAnchor())->toBeFalse()
        ->and($guard->externalAnchorStores())->toBe([])
        ->and(implode("\n", $guard->problems()))->not->toContain('EXTERNAL_ANCHOR')
        ->and(doctor()->find('anchor_stores')?->status)->toBe(CheckStatus::Warn);

    config()->set('audit.production.require_external_anchor', true);

    $databaseOnly = doctor()->find('anchor_stores');

    expect($databaseOnly?->status)->toBe(CheckStatus::Fail)
        ->and($databaseOnly?->detail)->toContain('no readable store outside the database')
        ->and($databaseOnly?->hint)->toContain('AUDIT_REQUIRE_EXTERNAL_ANCHOR')
        ->and(implode("\n", $guard->problems()))->toContain('AUDIT_REQUIRE_EXTERNAL_ANCHOR is on');

    // A log store is external but write-only: not enough.
    config()->set('audit.anchoring.stores', ['log', 'database']);
    $forget();

    expect(doctor()->find('anchor_stores')?->status)->toBe(CheckStatus::Fail);

    // A filesystem store on a disk that does not exist is caught before anything is read.
    config()->set('audit.anchoring.stores', ['filesystem', 'database']);
    config()->set('audit.anchoring.filesystem.disk', 'no-such-disk');
    $forget();

    expect($guard->externalAnchorStores())->toBe(['filesystem'])
        ->and($guard->externalAnchorProblems())->toHaveCount(1)
        ->and($guard->externalAnchorProblems()[0])->toContain('no-such-disk')
        ->and(doctor()->find('anchor_stores')?->status)->toBe(CheckStatus::Fail);

    // A custom store must be a class implementing AnchorStore.
    config()->set('audit.anchoring.stores', ['App\\Missing\\Store', 'database']);
    $forget();

    expect($guard->externalAnchorProblems()[0])->toContain('App\\Missing\\Store');

    // With a real external disk everything is satisfied.
    Illuminate\Support\Facades\Storage::fake('anchors');
    config()->set('audit.anchoring.stores', ['filesystem', 'database']);
    config()->set('audit.anchoring.filesystem.disk', 'anchors');
    $forget();

    $ok = doctor()->find('anchor_stores');

    expect($ok?->status)->toBe(CheckStatus::Ok)
        ->and($ok?->detail)->toContain('external: filesystem, required')
        ->and($guard->externalAnchorProblems())->toBe([]);
});

it('never lets the null store or a database-derived store satisfy the external anchor requirement', function (): void {
    $forget = fn () => app()->forgetInstance(Efnext\Security\Audit\Contracts\AnchorStore::class);
    $guard = app(Efnext\Security\Audit\Doctor\ProductionGuard::class);
    config()->set('audit.production.require_external_anchor', true);

    // The null store discards everything: not readable, not external, and the boot guard says so.
    config()->set('audit.anchoring.stores', [Efnext\Security\Audit\Anchoring\NullAnchorStore::class]);
    $forget();

    expect($guard->readableAnchorStores())->toBe([])
        ->and($guard->externalAnchorStores())->toBe([])
        ->and(implode("\n", $guard->problems()))->toContain('No readable anchor store')
        ->and(doctor()->find('anchor_stores')?->status)->toBe(CheckStatus::Fail);

    // Alongside the database store it still does not count as external.
    config()->set('audit.anchoring.stores', [Efnext\Security\Audit\Anchoring\NullAnchorStore::class, 'database']);
    $forget();

    expect($guard->readableAnchorStores())->toBe(['database'])
        ->and($guard->externalAnchorStores())->toBe([])
        ->and(implode("\n", $guard->problems()))->toContain('AUDIT_REQUIRE_EXTERNAL_ANCHOR is on')
        ->and(doctor()->find('anchor_stores')?->status)->toBe(CheckStatus::Fail);

    // A subclass of the database store is a database store.
    config()->set('audit.anchoring.stores', [Efnext\Security\Audit\Anchoring\DatabaseAnchorStore::class]);

    expect($guard->externalAnchorStores())->toBe([])
        ->and(implode("\n", $guard->problems()))->toContain('AUDIT_REQUIRE_EXTERNAL_ANCHOR is on');

    // The log store is write-only: readable stays empty as before.
    config()->set('audit.anchoring.stores', ['log', Efnext\Security\Audit\Anchoring\LogAnchorStore::class]);

    expect($guard->readableAnchorStores())->toBe([]);
});

it('judges anchor freshness by the external store when one is required, not by the database copy', function (): void {
    // The anchorer holds the store it was built with; rebuild both when the configuration changes.
    $forget = function (): void {
        app()->forgetInstance(Efnext\Security\Audit\Contracts\AnchorStore::class);
        app()->forgetInstance(Efnext\Security\Audit\Anchoring\Anchorer::class);
    };
    config()->set('audit.production.require_external_anchor', true);
    config()->set('audit.doctor.max_anchor_age_minutes', 10);

    // A structurally valid custom store that silently drops anchors passes the boot guard ...
    config()->set('audit.anchoring.stores', [Efnext\Security\Audit\Tests\Fixtures\DroppingAnchorStore::class, 'database']);
    $forget();

    expect(app(Efnext\Security\Audit\Doctor\ProductionGuard::class)->externalAnchorProblems())->toBe([])
        ->and(doctor()->find('anchor_stores')?->status)->toBe(CheckStatus::Ok);

    Chains::write(Chains::TENANT_A, 2);
    DB::table('audit_chain_heads')->update(['updated_at' => now()->subMinutes(30)]);
    $this->artisan('audit:anchor')->assertSuccessful();

    // ... the database now holds the anchor, but the external store does not: the doctor says so.
    expect(DB::table('audit_anchors')->count())->toBe(1);

    $fresh = doctor()->find('anchor_freshness');

    expect($fresh?->status)->toBe(CheckStatus::Fail)
        ->and($fresh?->detail)->toContain('in the external store(s) dropping', 'unanchored for 30 min')
        ->and($fresh?->hint)->toContain('external store accepts writes');

    // A real external store makes the same situation healthy.
    Illuminate\Support\Facades\Storage::fake('anchors');
    config()->set('audit.anchoring.stores', ['filesystem', 'database']);
    config()->set('audit.anchoring.filesystem.disk', 'anchors');
    $forget();

    $this->artisan('audit:anchor')->assertSuccessful();   // unchanged head: the anchorer skips, database copy already there
    expect(doctor()->find('anchor_freshness')?->status)->toBe(CheckStatus::Fail);   // still nothing in the bucket

    $this->artisan('audit:anchor', ['--force' => true])->assertSuccessful();
    $healthy = doctor()->find('anchor_freshness');

    expect($healthy?->status)->toBe(CheckStatus::Ok)
        ->and($healthy?->detail)->toContain('1 chain(s) anchored at their head in the external store(s) filesystem');
});

it('refuses to boot in production without an external anchor when one is required', function (): void {
    $app = $this->app;
    $connection = (string) config('database.default');
    $driverKey = 'database.connections.'.$connection.'.driver';
    $realDriver = config($driverKey);
    $forget = fn () => app()->forgetInstance(Efnext\Security\Audit\Contracts\AnchorStore::class);
    $app->detectEnvironment(static fn (): string => 'production');

    try {
        config()->set($driverKey, 'pgsql');
        config()->set('audit.production.require_external_anchor', true);

        expect(fn () => (new AuditServiceProvider($app))->boot())->toThrow(InsecureConfiguration::class, 'AUDIT_REQUIRE_EXTERNAL_ANCHOR');

        // Configured but not instantiable: the boot resolves the stores and reports why.
        config()->set('audit.anchoring.stores', ['filesystem', 'database']);
        config()->set('audit.anchoring.filesystem.disk', 'no-such-disk');
        $forget();

        expect(fn () => (new AuditServiceProvider($app))->boot())->toThrow(InsecureConfiguration::class, 'no-such-disk');

        Illuminate\Support\Facades\Storage::fake('anchors');
        config()->set('audit.anchoring.filesystem.disk', 'anchors');
        $forget();

        (new AuditServiceProvider($app))->boot();
        expect(app(Efnext\Security\Audit\Contracts\AnchorStore::class))->toBeInstanceOf(Efnext\Security\Audit\Anchoring\CompositeAnchorStore::class);
    } finally {
        config()->set($driverKey, $realDriver);
        $app->detectEnvironment(static fn (): string => 'testing');
    }
});

it('refuses to boot in production with an insecure configuration', function (): void {
    $app = $this->app;
    $connection = (string) config('database.default');
    $driverKey = 'database.connections.'.$connection.'.driver';
    $realDriver = config($driverKey);
    $app->detectEnvironment(static fn (): string => 'production');

    try {
        // SQLite audit database: not acceptable in production. The guard is configuration-only,
        // so the configured driver is what counts, whatever database this suite runs on.
        config()->set($driverKey, 'sqlite');
        expect(fn () => (new AuditServiceProvider($app))->boot())->toThrow(InsecureConfiguration::class, 'SQLite');

        // Escape hatch for a deliberate exception.
        config()->set('audit.production.fail_fast', false);
        (new AuditServiceProvider($app))->boot();

        // With a supported driver configured it boots.
        config()->set('audit.production.fail_fast', true);
        config()->set($driverKey, 'pgsql');
        (new AuditServiceProvider($app))->boot();

        // ... but not without a signing key.
        config()->set('audit.signing.keys', '');
        expect(fn () => (new AuditServiceProvider($app))->boot())->toThrow(InsecureConfiguration::class, 'signing key');
    } finally {
        config()->set($driverKey, $realDriver);
        $app->detectEnvironment(static fn (): string => 'testing');
    }
});
