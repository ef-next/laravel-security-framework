<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Http\Middleware\EnsureTenantMember;
use Efnext\Security\Tenant\Http\Middleware\EnsureTokenTenant;
use Efnext\Security\Tenant\Http\Middleware\ResolveTenant;
use Efnext\Security\Tenant\TenantServiceProvider;
use Efnext\Security\Tenant\Tests\Fixtures\Invoice;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Efnext\Security\Tenant\Tests\Fixtures\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * Laravel's 'web' and 'api' groups contain SubstituteBindings and group
 * middleware runs before route middleware unless the priority list says
 * otherwise. These tests pin that implicit route model binding of tenant-owned
 * models happens after the tenant is resolved, whatever order the route
 * declares.
 */

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->acme = Tenants::create('Acme', 'acme');
    $this->beta = Tenants::create('Beta', 'beta');
    $this->user = User::query()->create(['email' => 'u@example.test']);
    $this->user->grantTenantMembership($this->acme->id, 'admin');

    $this->acmeInvoice = Tenancy::run($this->acme->id, fn (): Invoice => Invoice::query()->create(['number' => 'A-1']));
    $this->betaInvoice = Tenancy::run($this->beta->id, fn (): Invoice => Invoice::query()->create(['number' => 'B-1']));

    // Same shape as a route inside the 'api' group: SubstituteBindings is declared first.
    Route::middleware([SubstituteBindings::class, 'auth', 'tenant'])
        ->get('/bound/{invoice}', fn (Invoice $invoice) => ['number' => $invoice->number]);

    Route::middleware([SubstituteBindings::class, 'auth', 'tenant.resolve', 'tenant.role:admin'])
        ->delete('/bound/{invoice}', fn (Invoice $invoice) => ['deleted' => $invoice->delete()]);

    Route::middleware([SubstituteBindings::class, 'tenant.resolve:optional'])
        ->get('/loose/{invoice}', fn (Invoice $invoice) => ['number' => $invoice->number]);
});

it('orders the tenant middleware after authentication and before SubstituteBindings', function (): void {
    // Resolving the kernel syncs aliases and the priority list to the router, as a real request would.
    $kernel = $this->app->make(HttpKernel::class);

    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $route = collect($router->getRoutes()->getRoutes())
        ->first(fn (IlluminateRoute $r): bool => $r->uri() === 'bound/{invoice}' && in_array('GET', $r->methods(), true));

    expect($route)->toBeInstanceOf(IlluminateRoute::class);

    /** @var list<string> $stack */
    $stack = $router->gatherRouteMiddleware($route);
    $position = function (string $class) use ($stack): int {
        foreach ($stack as $index => $middleware) {
            if (str_starts_with($middleware, $class)) {
                return $index;
            }
        }

        throw new RuntimeException($class.' is not in the middleware stack: '.implode(', ', $stack));
    };

    expect($position(Authenticate::class))->toBeLessThan($position(ResolveTenant::class))
        ->and($position(ResolveTenant::class))->toBeLessThan($position(EnsureTenantMember::class))
        ->and($position(EnsureTenantMember::class))->toBeLessThan($position(SubstituteBindings::class));

    $priority = $kernel->getMiddlewarePriority();

    expect(array_search(ResolveTenant::class, $priority, true))->toBeLessThan(array_search(SubstituteBindings::class, $priority, true))
        ->and(array_search(EnsureTokenTenant::class, $priority, true))->toBeLessThan(array_search(SubstituteBindings::class, $priority, true));
});

it('binds a tenant-owned model only after the tenant is resolved', function (): void {
    // Unauthenticated: 401 before anything else (actingAs() below sticks for the rest of the test).
    $this->getJson('/bound/'.$this->acmeInvoice->id, ['X-Tenant-Id' => 'acme'])->assertUnauthorized();

    $this->actingAs($this->user)
        ->getJson('/bound/'.$this->acmeInvoice->id, ['X-Tenant-Id' => 'acme'])
        ->assertOk()
        ->assertJson(['number' => 'A-1']);

    // Another tenant's record is invisible, not an error.
    $this->actingAs($this->user)
        ->getJson('/bound/'.$this->betaInvoice->id, ['X-Tenant-Id' => 'acme'])
        ->assertNotFound();

    // Missing tenant identifier: the tenant middleware answers 400 before any model is loaded.
    $this->actingAs($this->user)
        ->getJson('/bound/'.$this->acmeInvoice->id)
        ->assertStatus(400);

    // No membership: 403, not a TenantContextMissing 500.
    $this->actingAs($this->user)
        ->getJson('/bound/'.$this->betaInvoice->id, ['X-Tenant-Id' => 'beta'])
        ->assertForbidden();
});

it('runs role checks before the bound model is deleted', function (): void {
    $this->actingAs($this->user)
        ->deleteJson('/bound/'.$this->betaInvoice->id, [], ['X-Tenant-Id' => 'acme'])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->deleteJson('/bound/'.$this->acmeInvoice->id, [], ['X-Tenant-Id' => 'acme'])
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(Tenancy::run($this->beta->id, fn (): bool => Invoice::query()->where('number', 'B-1')->exists()))->toBeTrue();
});

it('still fails closed when a route binds a tenant-owned model without any tenant', function (): void {
    // tenant.resolve:optional without an identifier leaves no context; binding the
    // model is then a programming error and must not silently succeed.
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/loose/'.$this->acmeInvoice->id))->toThrow(TenantContextMissing::class);
});

it('inserts the tenant middleware before SubstituteBindings in any priority list', function (): void {
    $default = [Authenticate::class, SubstituteBindings::class, 'App\Authorize'];

    expect(TenantServiceProvider::prioritise($default))->toBe([
        Authenticate::class, ResolveTenant::class, EnsureTenantMember::class, EnsureTokenTenant::class, SubstituteBindings::class, 'App\Authorize',
    ])->and(TenantServiceProvider::prioritise([Authenticate::class]))->toBe([
        Authenticate::class, ResolveTenant::class, EnsureTenantMember::class, EnsureTokenTenant::class, SubstituteBindings::class,
    ])->and(TenantServiceProvider::prioritise(TenantServiceProvider::prioritise($default)))->toBe(TenantServiceProvider::prioritise($default));
});
