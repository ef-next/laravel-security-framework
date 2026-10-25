<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Events\TenantAccessDenied;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Membership\DenialReason;
use Efnext\Security\Tenant\Membership\MembershipStatus;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Efnext\Security\Tenant\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->acme = Tenants::create('Acme', 'acme');
    $this->beta = Tenants::create('Beta', 'beta');
    $this->user = User::query()->create(['email' => 'u@example.test']);

    Route::middleware(['auth', 'tenant'])->get('/member', fn () => ['ok' => true]);
    Route::middleware(['auth', 'tenant.resolve', 'tenant.role:admin'])->get('/admin', fn () => ['ok' => true]);
    Route::middleware(['tenant'])->get('/anonymous', fn () => ['ok' => true]);
});

it('allows members and denies everyone else', function (): void {
    Event::fake([TenantAccessDenied::class]);
    $this->user->grantTenantMembership($this->acme->id, 'member');

    $this->actingAs($this->user)->getJson('/member', ['X-Tenant-Id' => 'acme'])->assertOk();
    $this->actingAs($this->user)->getJson('/member', ['X-Tenant-Id' => 'beta'])->assertForbidden();

    Event::assertDispatched(TenantAccessDenied::class, function (TenantAccessDenied $e): bool {
        return $e->reason === DenialReason::NoMembership
            && $e->tenantId?->toString() === $this->beta->id
            && $e->actorId === (string) $this->user->id;
    });
});

it('denies unauthenticated requests with 401 even when the route lacks auth middleware', function (): void {
    $this->getJson('/anonymous', ['X-Tenant-Id' => 'acme'])->assertUnauthorized();
});

it('denies inactive and expired memberships', function (): void {
    $this->user->grantTenantMembership($this->acme->id, 'member');
    $this->user->changeTenantMembershipStatus($this->acme->id, MembershipStatus::Suspended);
    $this->actingAs($this->user)->getJson('/member', ['X-Tenant-Id' => 'acme'])->assertForbidden();

    $this->user->grantTenantMembership($this->acme->id, 'member', new DateTimeImmutable('-1 minute'));
    $this->actingAs($this->user)->getJson('/member', ['X-Tenant-Id' => 'acme'])->assertForbidden();

    $this->user->grantTenantMembership($this->acme->id, 'member', new DateTimeImmutable('+1 hour'));
    $this->actingAs($this->user)->getJson('/member', ['X-Tenant-Id' => 'acme'])->assertOk();
});

it('enforces roles hierarchically', function (): void {
    Event::fake([TenantAccessDenied::class]);

    $this->user->grantTenantMembership($this->acme->id, 'member');
    $this->actingAs($this->user)->getJson('/admin', ['X-Tenant-Id' => 'acme'])->assertForbidden();

    Event::assertDispatched(TenantAccessDenied::class, fn (TenantAccessDenied $e): bool => $e->reason === DenialReason::RoleInsufficient && $e->requiredRoles === ['admin']);

    $this->user->grantTenantMembership($this->acme->id, 'admin');
    $this->actingAs($this->user)->getJson('/admin', ['X-Tenant-Id' => 'acme'])->assertOk();

    $this->user->grantTenantMembership($this->acme->id, 'owner');
    $this->actingAs($this->user)->getJson('/admin', ['X-Tenant-Id' => 'acme'])->assertOk();
});

it('does not leak whether a tenant exists to non-members', function (): void {
    // Unknown tenant and known-but-foreign tenant must not be distinguishable by status text.
    $unknown = $this->actingAs($this->user)->getJson('/member', ['X-Tenant-Id' => 'nope']);
    $foreign = $this->actingAs($this->user)->getJson('/member', ['X-Tenant-Id' => 'beta']);

    expect($unknown->getStatusCode())->toBe(404)
        ->and($foreign->getStatusCode())->toBe(403);
});
