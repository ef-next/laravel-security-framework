<?php

declare(strict_types=1);

use Efnext\Security\Audit\Eloquent\AuditEvent;
use Efnext\Security\Audit\Exceptions\InvalidAuditEntry;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Audit\Records\AuditEntry;
use Efnext\Security\Audit\Tests\Fixtures\Chains;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Membership\HasTenantMemberships;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class AuditTestUser extends Authenticatable
{
    use HasTenantMemberships;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function (): void {
    Tenancy::clear();

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
    });

    $this->tenant = Chains::tenant(Chains::TENANT_A, 'acme');
    $this->user = AuditTestUser::query()->create(['email' => 'u@example.test']);

    Route::middleware(['auth', 'tenant'])->get('/secure', fn () => 'ok');
});

it('records access denials with the reason', function (): void {
    $this->actingAs($this->user)->getJson('/secure', ['X-Tenant-Id' => 'acme'])->assertForbidden();

    $event = AuditEvent::query()->where('action', 'tenant.access_denied')->firstOrFail();

    expect($event->tenant_id)->toBe(Chains::TENANT_A)
        ->and($event->outcome)->toBe('denied')
        ->and($event->actor_type)->toBe('user')
        ->and($event->actor_id)->toBe((string) $this->user->id)
        ->and($event->metadata['reason'])->toBe('no_membership');
});

it('records membership changes', function (): void {
    $this->user->grantTenantMembership(Chains::TENANT_A, 'admin');
    $this->user->grantTenantMembership(Chains::TENANT_A, 'owner');
    $this->user->revokeTenantMembership(Chains::TENANT_A);

    $actions = AuditEvent::query()->orderBy('sequence')->pluck('action')->all();

    expect($actions)->toBe(['tenant.membership.granted', 'tenant.membership.role_changed', 'tenant.membership.revoked']);
});

it('records scope bypasses with reason and caller', function (): void {
    Tenancy::run(Chains::TENANT_A, function (): void {
        Tenancy::withoutTenant('cross-tenant report', fn () => null);
    });

    $event = AuditEvent::query()->where('action', 'tenant.scope_bypassed')->firstOrFail();

    expect($event->metadata['reason'])->toBe('cross-tenant report')
        ->and($event->metadata['caller'])->toContain('TenantEventsRecordedTest.php');
});

it('records session tenant switches with origin and actor', function (): void {
    $this->user->grantTenantMembership(Chains::TENANT_A, 'member');

    $request = Illuminate\Http\Request::create('/switch', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn (): AuditTestUser => $this->user);

    app(Efnext\Security\Tenant\Http\SessionTenant::class)->switchTo($request, Efnext\Security\Tenant\Context\TenantId::fromString(Chains::TENANT_A));

    $event = AuditEvent::query()->where('action', 'tenant.switched')->firstOrFail();

    expect($event->tenant_id)->toBe(Chains::TENANT_A)
        ->and($event->chain_key)->toBe('tenant:'.Chains::TENANT_A)
        ->and($event->resource_type)->toBe('tenant')
        ->and($event->resource_id)->toBe(Chains::TENANT_A)
        ->and($event->outcome)->toBe('allowed')
        ->and($event->metadata)->toBe(['actor_id' => (string) $this->user->id, 'from' => null])
        ->and(Audit::verifyTenant(Chains::TENANT_A)->isIntact())->toBeTrue();

    // A refused switch is a denial on the target tenant's chain.
    $beta = Chains::tenant(Chains::TENANT_B, 'beta');

    expect(fn () => app(Efnext\Security\Tenant\Http\SessionTenant::class)->switchTo($request, Efnext\Security\Tenant\Context\TenantId::fromString(Chains::TENANT_B)))
        ->toThrow(Efnext\Security\Tenant\Exceptions\TenantAccessDenied::class);

    $denied = AuditEvent::query()->where('action', 'tenant.access_denied')->where('tenant_id', Chains::TENANT_B)->firstOrFail();

    expect($denied->metadata['reason'])->toBe('no_membership')->and($denied->outcome)->toBe('denied');
});

it('records tenant-less bypasses on the global chain even under the required policy', function (): void {
    expect(config('audit.tenant_policy'))->toBe('required');

    Tenancy::withoutTenant('maintenance', fn () => null);

    $event = AuditEvent::query()->where('action', 'tenant.scope_bypassed')->firstOrFail();

    expect($event->tenant_id)->toBeNull()
        ->and($event->chain_key)->toBe('global')
        ->and($event->resource_id)->toBe('all')
        ->and($event->metadata['reason'])->toBe('maintenance')
        ->and(Audit::verifyGlobal()->isIntact())->toBeTrue();

    // Application events still need a tenant under the required policy.
    expect(fn () => Audit::action('platform.booted')->on('platform', 'core')->record())->toThrow(InvalidAuditEntry::class);

    // ... unless they are explicitly global.
    $global = Audit::action('platform.booted')->on('platform', 'core')->global()->record();

    expect($global->chain_key)->toBe('global')->and($global->sequence)->toBe(2);
});

it('refuses a global entry that also names a tenant', function (): void {
    expect(fn () => AuditEntry::make('x.y', 'r', '1', tenantId: Chains::TENANT_A, global: true))->toThrow(InvalidAuditEntry::class);
});
