<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Membership\HasTenantMemberships;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * Minimal stand-in for a Sanctum user with a personal access token; the
 * middleware only relies on currentAccessToken() returning a model with an
 * "abilities" attribute (or a non-model transient token).
 */
final class TokenUser extends Authenticatable
{
    use HasTenantMemberships;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];

    public mixed $token = null;

    public function currentAccessToken(): mixed
    {
        return $this->token;
    }
}

function tokenWithAbilities(array $abilities): Model
{
    return new class(['abilities' => $abilities]) extends Model
    {
        protected $guarded = [];

        protected $casts = ['abilities' => 'array'];
    };
}

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->acme = Tenants::create('Acme', 'acme');
    $this->beta = Tenants::create('Beta', 'beta');
    $this->user = TokenUser::query()->create(['email' => 't@example.test']);
    $this->user->grantTenantMembership($this->acme->id, 'member');
    $this->user->grantTenantMembership($this->beta->id, 'member');

    Route::middleware(['auth', 'tenant', 'tenant.token'])->get('/api', fn () => ['ok' => true]);
});

it('allows tokens bound to the active tenant and rejects others', function (): void {
    $this->user->token = tokenWithAbilities(['tenant:'.$this->acme->id, 'read']);

    $this->actingAs($this->user)->getJson('/api', ['X-Tenant-Id' => 'acme'])->assertOk();
    $this->actingAs($this->user)->getJson('/api', ['X-Tenant-Id' => 'beta'])->assertForbidden();
});

it('allows unbound tokens unless binding is required', function (): void {
    $this->user->token = tokenWithAbilities(['*']);
    $this->actingAs($this->user)->getJson('/api', ['X-Tenant-Id' => 'acme'])->assertOk();

    config()->set('tenant.tokens.require_binding', true);
    $this->actingAs($this->user)->getJson('/api', ['X-Tenant-Id' => 'acme'])->assertForbidden();
});

it('ignores transient session tokens', function (): void {
    $this->user->token = new stdClass;

    $this->actingAs($this->user)->getJson('/api', ['X-Tenant-Id' => 'acme'])->assertOk();
});
