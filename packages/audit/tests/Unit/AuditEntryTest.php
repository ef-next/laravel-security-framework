<?php

declare(strict_types=1);

use Efnext\Security\Audit\Enums\Outcome;
use Efnext\Security\Audit\Exceptions\InvalidAuditEntry;
use Efnext\Security\Audit\Records\Actor;
use Efnext\Security\Audit\Records\AuditEntry;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Tenant\Context\TenantId;

it('validates action, resource and metadata shape', function (): void {
    $entry = AuditEntry::make('domain.updated', 'domain', 'dom_7f3k9zq2m4x8c1bt', 'denied', ['k' => 'v'], 'tenant-a');

    expect($entry->outcome)->toBe(Outcome::Denied)
        ->and($entry->tenantId?->toString())->toBe('tenant-a')
        ->and(fn () => AuditEntry::make('Domain.Updated', 'domain', 'x'))->toThrow(InvalidAuditEntry::class)
        ->and(fn () => AuditEntry::make('domain updated', 'domain', 'x'))->toThrow(InvalidAuditEntry::class)
        ->and(fn () => AuditEntry::make('domain.updated', 'Domain', 'x'))->toThrow(InvalidAuditEntry::class)
        ->and(fn () => AuditEntry::make('domain.updated', 'domain', ''))->toThrow(InvalidAuditEntry::class)
        ->and(fn () => AuditEntry::make('domain.updated', 'domain', "x\n"))->toThrow(InvalidAuditEntry::class)
        ->and(fn () => AuditEntry::make('domain.updated', 'domain', 'x', metadata: ['a', 'b']))->toThrow(InvalidAuditEntry::class)
        ->and(fn () => AuditEntry::make('domain.updated', 'domain', 'x', outcome: 'maybe'))->toThrow(InvalidAuditEntry::class)
        ->and(fn () => AuditEntry::make('domain.updated', 'domain', 'x', occurredAt: new DateTimeImmutable('+1 hour')))->toThrow(InvalidAuditEntry::class);
});

it('derives immutable variants', function (): void {
    $entry = AuditEntry::make('domain.updated', 'domain', 'dom_1');
    $withTenant = $entry->withTenant(TenantId::fromString('t1'));
    $withActor = $withTenant->withActor(Actor::system('scheduler'));
    $withRequest = $withActor->withRequestContext('req', null, '203.0.113.1', 'ua');

    expect($entry->tenantId)->toBeNull()
        ->and($withTenant->tenantId?->toString())->toBe('t1')
        ->and($withActor->actor?->id)->toBe('scheduler')
        ->and($withRequest->requestId)->toBe('req')
        ->and($withRequest->userAgent)->toBe('ua');
});

it('maps tenants to chain keys', function (): void {
    $tenant = TenantId::fromString('9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b');

    expect(ChainKey::forTenant($tenant)->toString())->toBe('tenant:9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b')
        ->and(ChainKey::for(null)->isGlobal())->toBeTrue()
        ->and(ChainKey::fromString('global')->isGlobal())->toBeTrue()
        ->and(ChainKey::fromString('9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b')->tenantId()?->equals($tenant))->toBeTrue()
        ->and(ChainKey::fromString('tenant:abc')->tenantId()?->toString())->toBe('abc')
        ->and(ChainKey::forTenant($tenant)->equals(ChainKey::fromString('tenant:9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b')))->toBeTrue()
        ->and(ChainKey::forTenant($tenant)->equals(ChainKey::global()))->toBeFalse()
        ->and(ChainKey::global()->equals(null))->toBeFalse();
});

it('pins the exact limits and the precedence rules of the derived variants', function (): void {
    $ok = fn (callable $make): bool => $make() instanceof AuditEntry;
    $fails = fn (callable $make): bool => (function () use ($make): bool {
        try {
            $make();
        } catch (InvalidAuditEntry) {
            return true;
        }

        return false;
    })();

    $action191 = 'a.'.str_repeat('b', 189);

    expect($ok(fn () => AuditEntry::make($action191, 'domain', 'x')))->toBeTrue()
        ->and($fails(fn () => AuditEntry::make($action191.'c', 'domain', 'x')))->toBeTrue()
        ->and($ok(fn () => AuditEntry::make('domain.updated', 'domain', str_repeat('r', 191))))->toBeTrue()
        ->and($fails(fn () => AuditEntry::make('domain.updated', 'domain', str_repeat('r', 192))))->toBeTrue()
        ->and($fails(fn () => AuditEntry::make('domain.updated', 'domain', '   ')))->toBeTrue()
        ->and($fails(fn () => AuditEntry::make('domain.updated', 'domain', "x\x7f")))->toBeTrue()
        ->and($ok(fn () => AuditEntry::make('domain.updated', 'domain', 'x', metadata: [])))->toBeTrue()
        ->and($ok(fn () => AuditEntry::make('domain.updated', 'domain', 'x', occurredAt: new DateTimeImmutable('+4 minutes'))))->toBeTrue()
        ->and($fails(fn () => AuditEntry::make('domain.updated', 'domain', 'x', occurredAt: new DateTimeImmutable('+6 minutes'))))->toBeTrue()
        ->and($fails(fn () => new AuditEntry('domain.updated', 'domain', 'x', tenantId: TenantId::fromString('t1'), global: true)))->toBeTrue();

    // A global entry stays global only while it has no tenant; explicit request context is never overwritten.
    $global = AuditEntry::make('platform.booted', 'platform', 'p', global: true);
    $explicit = new AuditEntry('domain.updated', 'domain', 'x', requestId: 'req-explicit', ipAddress: '203.0.113.1');
    $merged = $explicit->withRequestContext('req-http', 'corr-http', '198.51.100.1', 'ua-http');

    expect($global->withTenant(null)->global)->toBeTrue()
        ->and($global->withTenant(TenantId::fromString('t1'))->global)->toBeFalse()
        ->and($global->withActor(Actor::system())->global)->toBeTrue()
        ->and($merged->requestId)->toBe('req-explicit')
        ->and($merged->correlationId)->toBe('corr-http')
        ->and($merged->ipAddress)->toBe('203.0.113.1')
        ->and($merged->userAgent)->toBe('ua-http')
        ->and($merged->withRequestContext(null, null, null, null)->userAgent)->toBe('ua-http');
});
