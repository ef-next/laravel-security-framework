<?php

declare(strict_types=1);

use Efnext\Security\Audit\Eloquent\AuditEvent;
use Efnext\Security\Audit\Enums\ActorType;
use Efnext\Security\Audit\Events\AuditEventRecorded;
use Efnext\Security\Audit\Exceptions\AuditEventImmutable;
use Efnext\Security\Audit\Exceptions\InvalidAuditEntry;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Hashing\EventHasher;
use Efnext\Security\Audit\Hashing\HashableRecord;
use Efnext\Security\Audit\Records\Actor;
use Efnext\Security\Audit\Records\AuditEntry;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Tenancy::clear();
});

it('appends events with contiguous sequences and linked hashes', function (): void {
    [$first, $second] = Chains::write(Chains::TENANT_A, 2);

    expect($first->sequence)->toBe(1)
        ->and($first->previous_hash)->toBeNull()
        ->and($first->chain_key)->toBe('tenant:'.Chains::TENANT_A)
        ->and($first->tenant_id)->toBe(Chains::TENANT_A)
        ->and($first->hash_version)->toBe(HashableRecord::VERSION)
        ->and($first->event_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($first->key_id)->toBe('k1')
        ->and($first->signature)->toMatch('/^[0-9a-f]{64}$/')
        ->and($second->sequence)->toBe(2)
        ->and($second->previous_hash)->toBe($first->event_hash)
        ->and(app(EventHasher::class)->hash($second->fresh()->hashableRecord()))->toBe($second->event_hash);

    $head = DB::table('audit_chain_heads')->where('chain_key', $first->chain_key)->first();
    expect((int) $head->last_sequence)->toBe(2)->and($head->last_hash)->toBe($second->event_hash);
});

it('keeps chains per tenant independent', function (): void {
    Chains::write(Chains::TENANT_A, 2);
    [$b] = Chains::write(Chains::TENANT_B, 1);

    expect($b->sequence)->toBe(1)->and($b->previous_hash)->toBeNull();
});

it('requires a tenant by default and supports a global chain when nullable', function (): void {
    expect(fn () => Audit::action('platform.booted')->on('platform', 'core')->record())->toThrow(InvalidAuditEntry::class);

    config()->set('audit.tenant_policy', 'nullable');
    app()->forgetInstance(Efnext\Security\Audit\Contracts\AuditWriter::class);
    app()->forgetInstance(Efnext\Security\Audit\AuditManager::class);
    Audit::clearResolvedInstance(Efnext\Security\Audit\AuditManager::class);

    $event = Audit::action('platform.booted')->on('platform', 'core')->bySystem('scheduler')->record();

    expect($event->chain_key)->toBe('global')->and($event->tenant_id)->toBeNull()->and($event->sequence)->toBe(1);
});

it('takes the tenant from the active context or an explicit override', function (): void {
    $fromContext = Tenancy::run(Chains::TENANT_A, fn () => Audit::action('x.y')->on('r', '1')->record());
    $explicit = Tenancy::run(Chains::TENANT_A, fn () => Audit::action('x.y')->on('r', '1')->forTenant(Chains::TENANT_B)->record());

    expect($fromContext->tenant_id)->toBe(Chains::TENANT_A)->and($explicit->tenant_id)->toBe(Chains::TENANT_B);
});

it('records anonymous requests and authenticated users', function (): void {
    Route::middleware('tenant.resolve')->post('/act', fn () => Audit::action('thing.done')->on('thing', 't1')->record()->actor()->toArray());
    Route::middleware('tenant.resolve')->post('/anon', fn () => Audit::action('auth.login_failed')->on('user', 'someone')->denied()->record()->actor()->toArray());

    Chains::tenant(Chains::TENANT_A, 'a');

    // Anonymous first: actingAs() sticks to the guard for the rest of the test.
    $this->postJson('/anon', [], ['X-Tenant-Id' => Chains::TENANT_A])
        ->assertOk()->assertJson(['type' => 'anonymous', 'id' => null]);

    $this->actingAs(new GenericUser(['id' => 77]))->postJson('/act', [], ['X-Tenant-Id' => Chains::TENANT_A])
        ->assertOk()->assertJson(['type' => 'user', 'id' => '77']);
});

it('records system and explicit actors outside HTTP requests', function (): void {
    $system = Tenancy::run(Chains::TENANT_A, fn () => Audit::action('cleanup.ran')->on('job', 'nightly')->record());
    expect($system->actor()->type)->toBe(ActorType::System)
        ->and($system->actor_id)->toBe('console')
        ->and($system->user_agent)->toBeNull()
        ->and($system->ip_address)->toBeNull();

    $explicit = Tenancy::run(Chains::TENANT_A, fn () => Audit::action('sync.ran')->on('job', 'sync')->by(Actor::service('risk-engine'))->record());
    expect($explicit->actor_type)->toBe('service')->and($explicit->actor_id)->toBe('risk-engine');
});

it('stores request context in hashed columns', function (): void {
    Chains::tenant(Chains::TENANT_A, 'a');
    Route::middleware('tenant.resolve')->post('/ctx', fn () => Audit::action('thing.done')->on('thing', 't1')->record()->only(['request_id', 'correlation_id', 'ip_address', 'user_agent', 'metadata']));

    $response = $this->postJson('/ctx', [], [
        'X-Tenant-Id' => Chains::TENANT_A,
        'X-Request-Id' => 'req-1',
        'X-Correlation-Id' => 'corr-1',
        'User-Agent' => 'pest/1.0',
    ]);

    $response->assertOk()->assertJson(['request_id' => 'req-1', 'correlation_id' => 'corr-1', 'user_agent' => 'pest/1.0', 'metadata' => null]);
    expect($response->json('ip_address'))->not->toBeNull();
});

it('sanitises metadata before hashing and storing', function (): void {
    $event = Tenancy::run(Chains::TENANT_A, fn () => Audit::action('user.registered')
        ->on('user', 'usr_1')
        ->with(['password' => 'hunter2', 'email' => 'CEO@Acme.example', 'plan' => 'pro', 'nested' => ['api_key' => 'x', 'ok' => 1]])
        ->record());

    $stored = $event->fresh();

    expect($stored->metadata['password'])->toBe('[REDACTED]')
        ->and($stored->metadata)->not->toHaveKey('email')
        ->and($stored->metadata['email_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($stored->metadata['plan'])->toBe('pro')
        ->and($stored->metadata['nested']['api_key'])->toBe('[REDACTED]')
        ->and(app(EventHasher::class)->hash($stored->hashableRecord()))->toBe($stored->event_hash);
});

it('fires an event for every record', function (): void {
    Event::fake([AuditEventRecorded::class]);
    Chains::write(Chains::TENANT_A, 1);
    Event::assertDispatchedTimes(AuditEventRecorded::class, 1);
});

it('refuses updates and deletes through Eloquent', function (): void {
    [$event] = Chains::write(Chains::TENANT_A, 1);

    $event->action = 'tampered.action';
    expect(fn () => $event->save())->toThrow(AuditEventImmutable::class);

    $fresh = AuditEvent::query()->findOrFail($event->id);
    expect(fn () => $fresh->delete())->toThrow(AuditEventImmutable::class)
        ->and(fn () => AuditEvent::query()->where('id', $event->id)->update(['action' => 'x']))->toThrow(QueryException::class);
});

it('refuses updates and deletes at the database level', function (): void {
    [$event] = Chains::write(Chains::TENANT_A, 1);

    expect(fn () => DB::table('audit_events')->where('id', $event->id)->update(['actor_id' => 'someone-else']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('audit_events')->where('id', $event->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::statement('DELETE FROM audit_events'))->toThrow(QueryException::class)
        ->and(DB::table('audit_events')->where('id', $event->id)->value('actor_id'))->toBe('console');
});

it('rejects a second event with the same sequence at the database level', function (): void {
    [$event] = Chains::write(Chains::TENANT_A, 1);
    $copy = $event->getAttributes();
    $copy['id'] = (string) Illuminate\Support\Str::ulid();

    expect(fn () => DB::table('audit_events')->insert($copy))->toThrow(QueryException::class);
});

it('participates in an outer transaction so business data and audit commit together', function (): void {
    try {
        DB::transaction(function (): void {
            Tenancy::run(Chains::TENANT_A, fn () => Audit::action('x.y')->on('r', '1')->record());

            throw new RuntimeException('business logic failed after auditing');
        });
    } catch (RuntimeException) {
    }

    expect(AuditEvent::query()->count())->toBe(0)
        ->and(DB::table('audit_chain_heads')->count())->toBe(0);

    $next = Chains::write(Chains::TENANT_A, 1)[0];
    expect($next->sequence)->toBe(1);
});

it('accepts a plain entry object as well', function (): void {
    $event = Audit::record(new AuditEntry(
        action: 'import.finished',
        resourceType: 'import',
        resourceId: 'imp_1',
        tenantId: Efnext\Security\Tenant\Context\TenantId::fromString(Chains::TENANT_B),
        metadata: ['rows' => 12],
    ));

    expect($event->tenant_id)->toBe(Chains::TENANT_B)->and($event->metadata)->toBe(['rows' => 12]);
});
