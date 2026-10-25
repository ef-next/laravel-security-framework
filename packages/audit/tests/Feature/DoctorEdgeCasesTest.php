<?php

declare(strict_types=1);

use Efnext\Security\Audit\Doctor\Check;
use Efnext\Security\Audit\Doctor\CheckStatus;
use Efnext\Security\Audit\Doctor\SecurityDoctor;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Tests\Fixtures\AppendOnly;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Audit\Verification\VerificationRuns;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Http\Middleware\ResolveTenant;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;

/*
 * The doctor's verdicts at their exact thresholds and in the branches the
 * mutation run showed to be unpinned. An operator acts on these texts, so the
 * numbers in them are asserted too.
 */

beforeEach(function (): void {
    Tenancy::clear();
});

function doctorCheck(string $id): Check
{
    $check = app(SecurityDoctor::class)->run()->find($id);

    expect($check)->not->toBeNull($id.' missing from report');

    return $check;
}

it('judges environment, application key, driver and cache settings as an operator would', function (): void {
    // Environment: production with debug pages, then with the boot guard disabled, then a sane one.
    app()->instance('env', 'production');
    config()->set('app.debug', true);

    $debug = doctorCheck('environment');

    expect($debug->status)->toBe(CheckStatus::Warn)->and($debug->detail)->toContain('APP_DEBUG=true');

    config()->set('app.debug', false);
    config()->set('audit.production.fail_fast', false);

    $unguarded = doctorCheck('environment');

    expect($unguarded->status)->toBe(CheckStatus::Warn)->and($unguarded->detail)->toContain('AUDIT_FAIL_FAST=false');

    // The tenant cache uses the array driver in this test app: harmless in testing, a warning in production.
    $cache = doctorCheck('tenant_cache');

    expect($cache->status)->toBe(CheckStatus::Warn)->and($cache->detail)->toContain('array driver in production');

    app()->instance('env', 'testing');
    config()->set('audit.production.fail_fast', true);

    expect(doctorCheck('environment'))->toMatchObject(['status' => CheckStatus::Ok, 'detail' => 'APP_ENV=testing, debug off, fail-fast on.']);

    // Application key: the pseudonymisation pepper.
    config()->set('app.key', '');

    expect(doctorCheck('app_key')->status)->toBe(CheckStatus::Fail);

    // Cache: an explicit store and TTLs are reported; disabled is fine but said so.
    config()->set('tenant.cache.store', 'file');
    config()->set('tenant.cache.ttl', 120);
    config()->set('tenant.cache.negative_ttl', 7);

    expect(doctorCheck('tenant_cache'))->toMatchObject(['status' => CheckStatus::Ok, 'detail' => 'Store "file" (file), TTL 120s, negative TTL 7s.']);

    config()->set('tenant.cache.enabled', false);

    expect(doctorCheck('tenant_cache')->detail)->toStartWith('Disabled');

    // Driver: the real one is described; SQLite and unsupported drivers fail with a reason.
    $connection = config('database.default');
    $driver = doctorCheck('database_driver');

    if ($this->driver() === 'sqlite') {
        expect($driver->status)->toBe(CheckStatus::Fail)->and($driver->detail)->toContain('SQLite');
    } else {
        expect($driver->status)->toBe(CheckStatus::Ok)->and($driver->detail)->toContain('"'.$connection.'"', $this->driver());
    }

    config()->set('database.connections.'.$connection.'.driver', 'sqlsrv');

    $unsupported = doctorCheck('database_driver');

    expect($unsupported->status)->toBe(CheckStatus::Fail)->and($unsupported->detail)->toContain('unsupported driver "sqlsrv"');
});

it('measures anchor freshness against the configured window, exactly', function (): void {
    config()->set('audit.doctor.max_anchor_age_minutes', 10);
    Chains::write(Chains::TENANT_A, 2);
    $head = fn () => DB::table('audit_chain_heads')->where('chain_key', 'tenant:'.Chains::TENANT_A);

    // Exactly at the window: still pending.
    $head()->update(['updated_at' => now()->subMinutes(10)]);

    expect(doctorCheck('anchor_freshness'))->toMatchObject(['status' => CheckStatus::Ok, 'detail' => '0 chain(s) anchored at their head, 1 with recent unanchored events (within 10 min).']);

    // One minute more: stale, with the sequence and the age in the text.
    $head()->update(['updated_at' => now()->subMinutes(11)]);
    $stale = doctorCheck('anchor_freshness');

    expect($stale->status)->toBe(CheckStatus::Fail)
        ->and($stale->detail)->toContain('1 chain(s) with events older than 10 min', 'tenant:'.Chains::TENANT_A.' (#2 unanchored for 11 min)')
        ->and($stale->hint)->toContain('audit:anchor');

    // A head without a timestamp counts as never anchored.
    $head()->update(['updated_at' => null]);

    expect(doctorCheck('anchor_freshness')->detail)->toContain('unanchored for ever');

    // Anchored at the head: fine, whatever the age.
    $this->artisan('audit:anchor')->assertSuccessful();

    expect(doctorCheck('anchor_freshness'))->toMatchObject(['status' => CheckStatus::Ok, 'detail' => '1 chain(s) anchored at their head, 0 with recent unanchored events (within 10 min).']);

    // An anchor behind the head does not count.
    Chains::write(Chains::TENANT_A, 1);
    $head()->update(['updated_at' => now()->subMinutes(30)]);

    expect(doctorCheck('anchor_freshness')->detail)->toContain('(#3 unanchored for 30 min)');
});

it('rates full verifications by outcome first, then by age', function (): void {
    config()->set('audit.doctor.max_verification_age_hours', 2);
    config()->set('audit.doctor.tail_events', 1);   // keep the doctor's own tail runs partial (a tail from sequence 1 is a full run)
    Chains::write(Chains::TENANT_A, 2);
    Audit::prepareChain(Chains::TENANT_B);

    $this->artisan('audit:verify')->assertSuccessful();

    expect(doctorCheck('verification_recency'))->toMatchObject(['status' => CheckStatus::Ok, 'detail' => 'All 2 chain(s) fully verified and intact within the last 2 h.']);

    // Age it past the window: a warning naming the chains and the last run.
    DB::table('audit_verification_runs')->where('source', VerificationRuns::SOURCE_VERIFY)->update(['finished_at' => now()->subHours(3)]);
    $old = doctorCheck('verification_recency');

    expect($old->status)->toBe(CheckStatus::Warn)
        ->and($old->detail)->toContain('2 of 2 chain(s) without an intact full verification in the last 2 h', 'tenant:'.Chains::TENANT_A.' (last ')
        ->and($old->hint)->toContain('audit:verify');

    // A tampered chain: the next full run is not intact and outranks everything else.
    AppendOnly::bypass(fn () => DB::table('audit_events')->where('sequence', 2)->update(['action' => 'tampered.action']));
    $this->artisan('audit:verify')->assertFailed();
    $broken = doctorCheck('verification_recency');

    expect($broken->status)->toBe(CheckStatus::Fail)
        ->and($broken->detail)->toContain('found violations', 'tenant:'.Chains::TENANT_A)
        ->and($broken->detail)->not->toContain('tenant:'.Chains::TENANT_B);
});

it('verifies only the configured tail, skips empty chains and records what it did', function (): void {
    config()->set('audit.doctor.tail_events', 3);
    Chains::write(Chains::TENANT_A, 5);
    Audit::prepareChain(Chains::TENANT_B);

    expect(doctorCheck('tail_verification'))->toMatchObject(['status' => CheckStatus::Ok, 'detail' => 'Last 3 event(s) of every chain verified now: 3 events, all intact.']);

    $runs = DB::table('audit_verification_runs')->where('source', VerificationRuns::SOURCE_DOCTOR)->get();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->chain_key)->toBe('tenant:'.Chains::TENANT_A)
        ->and((int) $runs[0]->from_sequence)->toBe(3)
        ->and((int) $runs[0]->events_checked)->toBe(3);

    // Tampering outside the tail is invisible to the doctor (that is what full verification is for)...
    AppendOnly::bypass(fn () => DB::table('audit_events')->where('sequence', 1)->update(['action' => 'tampered.action']));

    expect(doctorCheck('tail_verification')->status)->toBe(CheckStatus::Ok);

    // ...tampering inside it is reported with a count.
    AppendOnly::bypass(fn () => DB::table('audit_events')->where('sequence', 5)->update(['action' => 'tampered.action']));
    $broken = doctorCheck('tail_verification');

    expect($broken->status)->toBe(CheckStatus::Fail)
        ->and($broken->detail)->toBe('Violations in: tenant:'.Chains::TENANT_A.' (1 violation(s)).')
        ->and($broken->hint)->toContain('audit:verify --chain');
});

it('fails when the tenant middleware priority was overwritten', function (): void {
    $router = app(Router::class);
    $original = $router->middlewarePriority;

    expect(doctorCheck('middleware_priority')->status)->toBe(CheckStatus::Ok);

    $router->middlewarePriority = [SubstituteBindings::class, ResolveTenant::class];

    expect(doctorCheck('middleware_priority')->status)->toBe(CheckStatus::Fail);

    $router->middlewarePriority = [SubstituteBindings::class];

    expect(doctorCheck('middleware_priority')->status)->toBe(CheckStatus::Fail);

    $router->middlewarePriority = [ResolveTenant::class];

    expect(doctorCheck('middleware_priority')->status)->toBe(CheckStatus::Fail);

    $router->middlewarePriority = $original;

    expect(doctorCheck('middleware_priority')->status)->toBe(CheckStatus::Ok);
});
