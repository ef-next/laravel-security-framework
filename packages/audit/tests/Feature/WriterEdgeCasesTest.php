<?php

declare(strict_types=1);

use Efnext\Security\Audit\Enums\Outcome;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Hashing\EventHasher;
use Efnext\Security\Audit\Records\Actor;
use Efnext\Security\Audit\Records\AuditEntry;
use Efnext\Security\Audit\Tests\Fixtures\AppendOnly;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Writer details the mutation run showed to be unpinned: explicit overrides of
 * the request context and timestamp, the actor label, head bookkeeping and how
 * a blank head hash is treated.
 */

beforeEach(function (): void {
    Tenancy::clear();
});

it('stores explicit request context, timestamp and actor label and hashes them', function (): void {
    $occurredAt = new DateTimeImmutable('2026-09-21 12:00:00.123456', new DateTimeZone('Europe/Berlin'));

    $event = Audit::record(new AuditEntry(
        action: 'export.finished',
        resourceType: 'export',
        resourceId: 'exp_1',
        outcome: Outcome::Failed,
        tenantId: TenantId::fromString(Chains::TENANT_A),
        actor: Actor::user(5, 'Jane Doe'),
        occurredAt: $occurredAt,
        requestId: 'req-explicit',
        correlationId: 'corr-explicit',
        ipAddress: '203.0.113.9',
        userAgent: 'worker/2.1',
    ));

    $stored = $event->fresh();

    expect($stored->request_id)->toBe('req-explicit')
        ->and($stored->correlation_id)->toBe('corr-explicit')
        ->and($stored->ip_address)->toBe('203.0.113.9')
        ->and($stored->user_agent)->toBe('worker/2.1')
        ->and($stored->actor_type)->toBe('user')
        ->and($stored->actor_id)->toBe('5')
        ->and($stored->actor_label)->toBe('Jane Doe')
        ->and($stored->outcome)->toBe('failed')
        ->and($stored->occurred_at->format('Y-m-d H:i:s.u'))->toBe('2026-09-21 10:00:00.123456')
        ->and($stored->occurred_at_us)->toBe((int) $occurredAt->format('Uu'))
        ->and($stored->recorded_at->getTimestamp())->toBeGreaterThan($occurredAt->getTimestamp())
        ->and(app(EventHasher::class)->hash($stored->hashableRecord()))->toBe($stored->event_hash);

    // The label is part of the hash: changing it is detected.
    AppendOnly::bypass(fn () => DB::table('audit_events')->where('id', $stored->id)->update(['actor_label' => 'Someone Else']));

    expect(Audit::verifyTenant(Chains::TENANT_A)->isIntact())->toBeFalse();
});

it('prefers explicit request context over the current HTTP request', function (): void {
    Chains::tenant(Chains::TENANT_A, 'a');
    Route::middleware('tenant.resolve')->post('/mixed', fn () => Audit::record(new AuditEntry(
        action: 'thing.done',
        resourceType: 'thing',
        resourceId: 't1',
        tenantId: TenantId::fromString(Chains::TENANT_A),
        requestId: 'req-explicit',
        userAgent: 'explicit-agent',
    ))->only(['request_id', 'correlation_id', 'ip_address', 'user_agent']));

    $this->postJson('/mixed', [], ['X-Tenant-Id' => Chains::TENANT_A, 'X-Request-Id' => 'req-header', 'X-Correlation-Id' => 'corr-header', 'User-Agent' => 'header-agent'])
        ->assertOk()
        ->assertJson(['request_id' => 'req-explicit', 'correlation_id' => 'corr-header', 'user_agent' => 'explicit-agent'])
        ->assertJsonPath('ip_address', fn (?string $ip): bool => $ip !== null);
});

it('advances the chain head with every write and treats a blank head hash as no hash', function (): void {
    [$first] = Chains::write(Chains::TENANT_A, 1);
    $head = fn () => DB::table('audit_chain_heads')->where('chain_key', 'tenant:'.Chains::TENANT_A)->first();

    $afterFirst = $head();

    expect((int) $afterFirst->last_sequence)->toBe(1)
        ->and($afterFirst->last_hash)->toBe($first->event_hash)
        ->and($afterFirst->updated_at)->not->toBeNull();

    usleep(2_000);
    [$second] = Chains::write(Chains::TENANT_A, 1);
    $afterSecond = $head();

    expect((int) $afterSecond->last_sequence)->toBe(2)
        ->and($afterSecond->last_hash)->toBe($second->event_hash)
        ->and(new DateTimeImmutable($afterSecond->updated_at) > new DateTimeImmutable($afterFirst->updated_at))->toBeTrue()
        ->and($second->previous_hash)->toBe($first->event_hash);

    // A head whose hash was blanked (bad migration, manual repair) or space-padded (PostgreSQL char(64))
    // yields a first-of-chain link, not a link to "" — for the writer and for the verifier alike.
    Audit::prepareChain(Chains::TENANT_B);
    $headB = fn () => DB::table('audit_chain_heads')->where('chain_key', 'tenant:'.Chains::TENANT_B);

    AppendOnly::bypass(fn () => $headB()->update(['last_hash' => '']));
    expect(Audit::verifyTenant(Chains::TENANT_B)->isIntact())->toBeTrue();

    AppendOnly::bypass(fn () => $headB()->update(['last_hash' => str_repeat(' ', 64)]));
    expect(Audit::verifyTenant(Chains::TENANT_B)->isIntact())->toBeTrue();

    [$fresh] = Chains::write(Chains::TENANT_B, 1);

    expect($fresh->sequence)->toBe(1)
        ->and($fresh->previous_hash)->toBeNull()
        ->and(Audit::verifyTenant(Chains::TENANT_B)->isIntact())->toBeTrue();
});
