<?php

declare(strict_types=1);

use Efnext\Security\Audit\Anchoring\Anchorer;
use Efnext\Security\Audit\Anchoring\CompositeAnchorStore;
use Efnext\Security\Audit\Anchoring\FilesystemAnchorStore;
use Efnext\Security\Audit\Anchoring\NullAnchorStore;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Contracts\AuditActor;
use Efnext\Security\Audit\Doctor\CheckStatus;
use Efnext\Security\Audit\Doctor\ProductionGuard;
use Efnext\Security\Audit\Doctor\SecurityDoctor;
use Efnext\Security\Audit\Enums\ViolationReason;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Records\Actor;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Tests\Fixtures\AppendOnly;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
 * Branches that only defensive code reaches: empty chains, missing rows,
 * malformed anchor files, unreachable connections, users that describe
 * themselves. The mutation report listed them as never executed; each is
 * behaviour an operator or integrator relies on, so it is pinned here.
 */

beforeEach(function (): void {
    Tenancy::clear();
});

it('anchors nothing for an empty chain and falls through composite stores in order', function (): void {
    Audit::prepareChain(Chains::TENANT_B);
    $chainB = ChainKey::fromString('tenant:'.Chains::TENANT_B);

    expect(app(Anchorer::class)->anchor($chainB))->toBeNull()
        ->and(app(Anchorer::class)->anchor(ChainKey::fromString('tenant:never-written')))->toBeNull();

    // The first store that has an anchor wins; an empty first store is skipped, not fatal.
    Storage::fake('anchors');
    $filesystem = new FilesystemAnchorStore(Storage::disk('anchors'), 'anchors');
    $database = app(AnchorStore::class);
    $composite = new CompositeAnchorStore($filesystem, $database);

    [$event] = Chains::write(Chains::TENANT_A, 1);
    $chainA = ChainKey::fromString('tenant:'.Chains::TENANT_A);
    $database->store(new Anchor($chainA, 1, $event->event_hash, null, new DateTimeImmutable('2026-09-23T08:00:00Z')));

    expect($composite->latest($chainA)?->sequence)->toBe(1)
        ->and($composite->latest($chainB))->toBeNull()
        ->and($composite->stores())->toBe([$filesystem, $database])
        ->and($composite->name())->toBe('filesystem+database')
        ->and((new NullAnchorStore)->latest($chainA))->toBeNull();
});

it('ignores foreign files in the anchor directory and answers null without anchors', function (): void {
    Storage::fake('anchors');
    $disk = Storage::disk('anchors');
    $store = new FilesystemAnchorStore($disk, 'anchors');
    $chain = ChainKey::fromString('tenant:'.Chains::TENANT_A);

    expect($store->latest($chain))->toBeNull();

    $store->store(new Anchor($chain, 3, str_repeat('a', 64), null, new DateTimeImmutable('2026-09-23T08:00:00Z')));
    $store->store(new Anchor($chain, 7, str_repeat('b', 64), null, new DateTimeImmutable('2026-09-23T08:05:00Z')));

    $directory = dirname((string) collect($disk->allFiles('anchors'))->first());
    $disk->put($directory.'/README.txt', 'not an anchor');
    $disk->put($directory.'/notes-000000000000000099.json.bak', '{}');

    expect($store->latest($chain)?->sequence)->toBe(7)
        ->and($store->latest($chain, 5)?->sequence)->toBe(3)
        ->and($store->latest($chain, 2))->toBeNull();
});

it('flags events whose hash version it does not know instead of guessing', function (): void {
    $events = Chains::write(Chains::TENANT_A, 3);

    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $events[1]->id)->update(['hash_version' => 99]));

    $report = Audit::verifyTenant(Chains::TENANT_A);
    $violation = collect($report->violations)->first(fn ($v) => $v->reason === ViolationReason::UnsupportedHashVersion);

    expect($report->isIntact())->toBeFalse()
        ->and($violation?->sequence)->toBe(2)
        ->and($violation?->eventId)->toBe($events[1]->id)
        ->and($violation?->details)->toBe(['hash_version' => 99])
        ->and($violation?->toArray())->toBe([
            'reason' => 'unsupported_hash_version',
            'sequence' => 2,
            'event_id' => $events[1]->id,
            'hash_version' => 99,
        ])
        ->and(collect($report->violations)->filter(fn ($v) => $v->reason === ViolationReason::EventHashMismatch))->toHaveCount(0);
});

it('lets a user model describe itself as an actor', function (): void {
    Chains::tenant(Chains::TENANT_A, 'a');

    $selfDescribing = new class(['id' => 42]) extends GenericUser implements AuditActor
    {
        public function toAuditActor(): Actor
        {
            return Actor::service('billing-bot', 'Billing bot (user 42)');
        }
    };

    Route::middleware('tenant.resolve')->post('/self', fn () => Audit::action('thing.done')->on('thing', 't1')->record()->only(['actor_type', 'actor_id', 'actor_label']));

    $this->actingAs($selfDescribing)->postJson('/self', [], ['X-Tenant-Id' => Chains::TENANT_A])
        ->assertOk()
        ->assertJson(['actor_type' => 'service', 'actor_id' => 'billing-bot', 'actor_label' => 'Billing bot (user 42)']);
});

it('reports an unreachable audit connection in every database-backed check instead of crashing', function (): void {
    config()->set('audit.connection', 'no-such-connection');
    app()->forgetInstance(SecurityDoctor::class);

    $report = app(SecurityDoctor::class)->run();
    $detail = fn (string $id): string => (string) $report->find($id)?->detail;

    expect($report->find('database_driver')?->status)->toBe(CheckStatus::Fail)
        ->and($detail('database_driver'))->toContain('unsupported driver "unknown"')
        ->and($report->find('append_only_triggers')?->status)->toBe(CheckStatus::Fail)
        ->and($detail('append_only_triggers'))->toContain('Could not inspect triggers')
        ->and($report->find('anchor_freshness')?->status)->toBe(CheckStatus::Fail)
        ->and($detail('anchor_freshness'))->toContain('Could not read chain heads')
        ->and($report->find('verification_recency')?->status)->toBe(CheckStatus::Fail)
        ->and($detail('verification_recency'))->toContain('Could not read chain heads')
        ->and($report->find('tail_verification')?->status)->toBe(CheckStatus::Fail)
        ->and($detail('tail_verification'))->toContain('Could not verify')
        ->and($report->find('separate_connection')?->status)->toBe(CheckStatus::Warn)
        ->and($report->hasFailures())->toBeTrue()
        ->and($report->hasWarnings())->toBeTrue();
});

it('reports a malformed signing key configuration as such', function (): void {
    config()->set('audit.signing.keys', 'k1:hex:deadbeef');

    $check = app(SecurityDoctor::class)->run()->find('signing_key');
    $problems = implode("\n", app(ProductionGuard::class)->problems());

    expect($check?->status)->toBe(CheckStatus::Fail)
        ->and($check?->detail)->toStartWith('Invalid: ')
        ->and($check?->detail)->toContain('<id>:base64:<key>')
        ->and($problems)->toContain('signing key configuration is invalid');
});

it('falls back to the configuration when no filesystem factory is available to the guard', function (): void {
    config()->set('audit.production.require_external_anchor', true);
    config()->set('audit.anchoring.stores', ['filesystem', 'database']);
    config()->set('audit.anchoring.filesystem.disk', 'archive');

    $guard = new ProductionGuard(config());

    expect($guard->externalAnchorProblems())->toHaveCount(1)
        ->and($guard->externalAnchorProblems()[0])->toContain('"archive"', 'config/filesystems.php');

    config()->set('filesystems.disks.archive', ['driver' => 'local', 'root' => storage_path('archive')]);

    expect($guard->externalAnchorProblems())->toBe([]);

    config()->set('audit.anchoring.filesystem.disk', '');

    expect($guard->externalAnchorProblems()[0])->toContain('no disk configured');
});
