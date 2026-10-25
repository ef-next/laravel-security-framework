<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Events\TenantAccessDenied as TenantAccessDeniedEvent;
use Efnext\Security\Tenant\Events\TenantSwitched;
use Efnext\Security\Tenant\Exceptions\TenantAccessDenied;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Http\SessionTenant;
use Efnext\Security\Tenant\Membership\DenialReason;
use Efnext\Security\Tenant\Membership\MembershipStatus;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Efnext\Security\Tenant\Tests\Fixtures\User;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
 * The session "tenant switcher": SessionTenant stores a tenant in the session
 * only after a membership check, fires TenantSwitched, and the session
 * resolver picks the stored tenant up on later requests.
 */

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->acme = Tenants::create('Acme', 'acme');
    $this->beta = Tenants::create('Beta', 'beta');
    $this->user = User::query()->create(['email' => 'u@example.test']);
    $this->user->grantTenantMembership($this->acme->id, 'member');

    $this->switcher = app(SessionTenant::class);
});

function requestWithSession(?User $user = null): Request
{
    $request = Request::create('/switch', 'POST');
    $request->setLaravelSession(app('session.store'));

    if ($user !== null) {
        $request->setUserResolver(static fn (): User => $user);
    }

    return $request;
}

it('stores the tenant after a membership check and fires TenantSwitched', function (): void {
    Event::fake([TenantSwitched::class]);
    $request = requestWithSession($this->user);

    expect($this->switcher->current($request))->toBeNull();

    $this->switcher->switchTo($request, TenantId::fromString($this->acme->id));

    expect($request->session()->get('efnext.tenant'))->toBe($this->acme->id)
        ->and($this->switcher->current($request)?->toString())->toBe($this->acme->id);

    Event::assertDispatched(TenantSwitched::class, fn (TenantSwitched $e): bool => $e->actorId === (string) $this->user->id
        && $e->from === null
        && $e->to->toString() === $this->acme->id);

    // Switching again records where the user came from.
    $this->user->grantTenantMembership($this->beta->id, 'viewer');
    $this->switcher->switchTo($request, TenantId::fromString($this->beta->id));

    Event::assertDispatched(TenantSwitched::class, fn (TenantSwitched $e): bool => $e->from?->toString() === $this->acme->id && $e->to->toString() === $this->beta->id);

    $this->switcher->forget($request);

    expect($this->switcher->current($request))->toBeNull();
});

it('refuses to store a tenant the user may not act for', function (): void {
    Event::fake([TenantAccessDeniedEvent::class, TenantSwitched::class]);
    $request = requestWithSession($this->user);

    // Not a member of beta.
    expect(fn () => $this->switcher->switchTo($request, TenantId::fromString($this->beta->id)))->toThrow(TenantAccessDenied::class);
    Event::assertDispatched(TenantAccessDeniedEvent::class, fn (TenantAccessDeniedEvent $e): bool => $e->reason === DenialReason::NoMembership && $e->tenantId?->toString() === $this->beta->id);

    // Suspended membership.
    $this->user->changeTenantMembershipStatus($this->acme->id, MembershipStatus::Suspended);
    expect(fn () => $this->switcher->switchTo($request, TenantId::fromString($this->acme->id)))->toThrow(TenantAccessDenied::class);

    // No authenticated user at all.
    expect(fn () => $this->switcher->switchTo(requestWithSession(), TenantId::fromString($this->acme->id)))->toThrow(TenantAccessDenied::class);
    Event::assertDispatched(TenantAccessDeniedEvent::class, fn (TenantAccessDeniedEvent $e): bool => $e->reason === DenialReason::Unauthenticated);

    expect($request->session()->has('efnext.tenant'))->toBeFalse();
    Event::assertNotDispatched(TenantSwitched::class);
});

it('handles requests without a session gracefully', function (): void {
    $bare = Request::create('/');

    expect($this->switcher->current($bare))->toBeNull();

    $this->switcher->forget($bare); // no exception
});

it('is picked up by the session resolver on later requests, but only for real members', function (): void {
    Route::middleware([StartSession::class, 'tenant.resolve'])->get('/whoami', fn () => ['tenant' => Tenancy::requireId()->toString()]);
    Route::middleware([StartSession::class, 'auth', 'tenant'])->get('/member-only', fn () => ['ok' => true]);

    $this->withSession(['efnext.tenant' => $this->acme->id])->getJson('/whoami')->assertOk()->assertJson(['tenant' => $this->acme->id]);

    // A session value is a hint, not a grant: the membership middleware still decides.
    $this->actingAs($this->user)->withSession(['efnext.tenant' => $this->acme->id])->getJson('/member-only')->assertOk();
    $this->actingAs($this->user)->withSession(['efnext.tenant' => $this->beta->id])->getJson('/member-only')->assertForbidden();

    // Garbage in the session never reaches the database: malformed identifiers are "unknown" (404), like everywhere else.
    $this->withSession(['efnext.tenant' => "acme' OR 1=1"])->getJson('/whoami')->assertNotFound();
});
