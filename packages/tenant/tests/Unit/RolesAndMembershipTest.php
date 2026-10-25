<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Locating\TenantStatus;
use Efnext\Security\Tenant\Membership\DenialReason;
use Efnext\Security\Tenant\Membership\Membership;
use Efnext\Security\Tenant\Membership\MembershipStatus;
use Efnext\Security\Tenant\Membership\Roles;

it('satisfies required roles hierarchically when configured', function (): void {
    $roles = new Roles(['owner', 'admin', 'member', 'viewer']);

    expect($roles->satisfies('owner', ['admin']))->toBeTrue()
        ->and($roles->satisfies('admin', ['admin']))->toBeTrue()
        ->and($roles->satisfies('member', ['admin']))->toBeFalse()
        ->and($roles->satisfies('viewer', ['member', 'admin']))->toBeFalse()
        ->and($roles->satisfies('OWNER', ['viewer']))->toBeTrue()
        ->and($roles->satisfies(null, ['viewer']))->toBeFalse()
        ->and($roles->satisfies('superuser', ['viewer']))->toBeFalse()
        ->and($roles->satisfies('owner', ['unknown']))->toBeFalse()
        ->and($roles->highest())->toBe('owner');
});

it('requires an exact match in flat mode', function (): void {
    $roles = new Roles(['owner', 'admin', 'member'], hierarchical: false);

    expect($roles->satisfies('owner', ['admin']))->toBeFalse()
        ->and($roles->satisfies('admin', ['admin', 'owner']))->toBeTrue();
});

it('parses comma separated role lists', function (): void {
    expect(Roles::parse(' Owner, admin ,,ADMIN'))->toBe(['owner', 'admin'])
        ->and(Roles::parse(''))->toBe([]);
});

it('rejects empty vocabularies', function (): void {
    expect(fn () => new Roles([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Roles(['owner', '']))->toThrow(InvalidArgumentException::class);
});

it('grants access only to active, unexpired memberships', function (): void {
    $tenant = TenantId::fromString('tenant-a');
    $now = new DateTimeImmutable('2026-09-20 12:00:00');

    $active = new Membership('u1', $tenant, 'member', MembershipStatus::Active);
    $invited = new Membership('u1', $tenant, 'member', MembershipStatus::Invited);
    $suspended = new Membership('u1', $tenant, 'member', MembershipStatus::Suspended);
    $expired = new Membership('u1', $tenant, 'member', MembershipStatus::Active, new DateTimeImmutable('2026-09-20 11:59:59'));
    $future = new Membership('u1', $tenant, 'member', MembershipStatus::Active, new DateTimeImmutable('2026-09-21 00:00:00'));

    expect($active->grantsAccess($now))->toBeTrue()
        ->and($active->denialReason($now))->toBeNull()
        ->and($invited->denialReason($now))->toBe(DenialReason::MembershipInactive)
        ->and($suspended->denialReason($now))->toBe(DenialReason::MembershipInactive)
        ->and($expired->denialReason($now))->toBe(DenialReason::MembershipExpired)
        ->and($future->grantsAccess($now))->toBeTrue();
});

it('maps unknown stored statuses to a non-granting state', function (): void {
    expect(MembershipStatus::fromStored('ACTIVE'))->toBe(MembershipStatus::Active)
        ->and(MembershipStatus::fromStored('weird'))->toBe(MembershipStatus::Suspended)
        ->and(MembershipStatus::fromStored(null))->toBe(MembershipStatus::Suspended)
        ->and(TenantStatus::fromStored('active')->isActive())->toBeTrue()
        ->and(TenantStatus::fromStored('deleted')->isActive())->toBeFalse()
        ->and(TenantStatus::fromStored(1)->isActive())->toBeFalse();
});
