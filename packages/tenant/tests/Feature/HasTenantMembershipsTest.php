<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Events\MembershipChanged;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Membership\MembershipStatus;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Efnext\Security\Tenant\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->acme = Tenants::create('Acme', 'acme');
    $this->beta = Tenants::create('Beta', 'beta');
    $this->user = User::query()->create(['email' => 'u@example.test']);
});

it('grants, changes, suspends and revokes memberships with events', function (): void {
    Event::fake([MembershipChanged::class]);

    $membership = $this->user->grantTenantMembership($this->acme->id, 'admin');

    expect($membership->role)->toBe('admin')
        ->and($this->user->isMemberOf($this->acme->id))->toBeTrue()
        ->and($this->user->hasTenantRole($this->acme->id, 'member'))->toBeTrue()
        ->and($this->user->hasTenantRole($this->acme->id, ['owner']))->toBeFalse()
        ->and(array_map(fn ($id) => $id->toString(), $this->user->activeTenantIds()))->toBe([$this->acme->id])
        ->and($this->user->tenants()->pluck('name')->all())->toBe(['Acme']);

    Event::assertDispatched(MembershipChanged::class, fn (MembershipChanged $e): bool => $e->change === MembershipChanged::GRANTED && $e->role === 'admin');

    $this->user->grantTenantMembership($this->acme->id, 'owner');
    Event::assertDispatched(MembershipChanged::class, fn (MembershipChanged $e): bool => $e->change === MembershipChanged::ROLE_CHANGED && $e->previousRole === 'admin' && $e->role === 'owner');

    $this->user->changeTenantMembershipStatus($this->acme->id, MembershipStatus::Suspended);
    expect($this->user->isMemberOf($this->acme->id))->toBeFalse()
        ->and($this->user->activeTenantIds())->toBe([]);
    Event::assertDispatched(MembershipChanged::class, fn (MembershipChanged $e): bool => $e->change === MembershipChanged::STATUS_CHANGED && $e->status === 'suspended');

    $this->user->revokeTenantMembership($this->acme->id);
    expect($this->user->tenantMembership($this->acme->id))->toBeNull();
    Event::assertDispatched(MembershipChanged::class, fn (MembershipChanged $e): bool => $e->change === MembershipChanged::REVOKED && $e->previousRole === 'owner');
});

it('rejects unknown roles and unsaved users', function (): void {
    expect(fn () => $this->user->grantTenantMembership($this->acme->id, 'superuser'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new User(['email' => 'x@example.test']))->grantTenantMembership($this->acme->id))->toThrow(LogicException::class);
});

it('uses the configured default role', function (): void {
    config()->set('tenant.memberships.default_role', 'viewer');

    expect($this->user->grantTenantMembership($this->beta->id)->role)->toBe('viewer');
});

it('enforces one membership per user and tenant at the database level', function (): void {
    $this->user->grantTenantMembership($this->acme->id, 'member');

    expect(fn () => $this->user->tenantMemberships()->create(['tenant_id' => $this->acme->id, 'role' => 'admin']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
