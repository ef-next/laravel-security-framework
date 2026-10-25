<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Contracts\MembershipChecker;
use Efnext\Security\Tenant\Database\TenantForeignKeyDefinition;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Resolution\HeaderResolver;
use Efnext\Security\Tenant\Resolution\ResolverChain;
use Efnext\Security\Tenant\Resolution\RouteParameterResolver;
use Efnext\Security\Tenant\Resolution\SessionResolver;
use Efnext\Security\Tenant\Resolution\SubdomainResolver;
use Efnext\Security\Tenant\Tests\Fixtures\CustomBuilderInvoice;
use Efnext\Security\Tenant\Tests\Fixtures\GuardedInvoiceBuilder;
use Efnext\Security\Tenant\Tests\Fixtures\Invoice;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Efnext\Security\Tenant\Tests\Fixtures\UnguardedBuilderInvoice;
use Efnext\Security\Tenant\Tests\Fixtures\User;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as SchemaFacade;

/*
 * Branches that only defensive code reaches: unsaved users, blank actor ids,
 * users without API tokens, custom query builders, optional tenant-bound
 * relations, empty inserts and the resolvers' self-descriptions. The mutation
 * report listed them as never executed; each is behaviour an integrator relies on.
 */

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->acme = Tenants::create('Acme', 'acme');
    $this->beta = Tenants::create('Beta', 'beta');
});

it('answers membership questions for an unsaved user without touching the database', function (): void {
    $user = new User(['email' => 'nobody@example.test']);
    $checker = app(MembershipChecker::class);

    DB::enableQueryLog();

    expect($user->activeTenantIds())->toBe([])
        ->and($user->tenantMembership($this->acme->id))->toBeNull()
        ->and($user->hasTenantRole($this->acme->id, 'member'))->toBeFalse()
        ->and($checker->membershipFor('   ', TenantId::fromString($this->acme->id)))->toBeNull();

    $user->revokeTenantMembership($this->acme->id);   // nothing to revoke, nothing thrown

    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();

    // A saved user without a membership: the same answers, this time from the database.
    $saved = User::query()->create(['email' => 'saved@example.test']);

    expect($saved->activeTenantIds())->toBe([])
        ->and($saved->tenantMembership($this->acme->id))->toBeNull()
        ->and($saved->hasTenantRole($this->acme->id, ['member', 'admin']))->toBeFalse();
});

it('treats a user with a blank identifier as unauthenticated and lets token-less users through the token check', function (): void {
    Route::middleware(['tenant.resolve', 'tenant.member'])->get('/member', fn () => ['ok' => true]);
    Route::middleware(['tenant.resolve', 'tenant.token'])->get('/token', fn () => ['ok' => true]);

    $this->actingAs(new GenericUser(['id' => '  ']))->getJson('/member', ['X-Tenant-Id' => 'acme'])->assertUnauthorized();

    // GenericUser has no currentAccessToken(): the Sanctum check does not apply and the request continues.
    $this->actingAs(new GenericUser(['id' => 7]))->getJson('/token', ['X-Tenant-Id' => 'acme'])->assertOk();
});

it('accepts a custom builder that carries the guard and refuses one that does not', function (): void {
    Tenancy::run($this->acme->id, function (): void {
        $query = CustomBuilderInvoice::query();

        expect($query)->toBeInstanceOf(GuardedInvoiceBuilder::class);

        CustomBuilderInvoice::query()->create(['number' => 'C-1']);

        expect(CustomBuilderInvoice::query()->numbered('C-1')->count())->toBe(1)
            ->and(fn () => CustomBuilderInvoice::query()->numbered('C-1')->update(['tenant_id' => $this->beta->id]))
            ->toThrow(Efnext\Security\Tenant\Exceptions\TenantColumnImmutable::class)
            ->and(fn () => UnguardedBuilderInvoice::query())->toThrow(LogicException::class, 'does not guard the tenant column');
    });
});

it('keeps optional tenant-bound relations optional and tenant-bound', function (): void {
    $columns = collect(SchemaFacade::getColumns('invoice_notes'))->keyBy('name');

    expect($columns['invoice_id']['nullable'])->toBeTrue()
        ->and($columns['tenant_id']['nullable'])->toBeFalse();

    Tenancy::run($this->acme->id, function (): void {
        $invoice = Invoice::query()->create(['number' => 'A-1']);

        DB::table('invoice_notes')->insert(['tenant_id' => $this->acme->id, 'invoice_id' => null, 'body' => 'unattached']);
        DB::table('invoice_notes')->insert(['tenant_id' => $this->acme->id, 'invoice_id' => $invoice->id, 'body' => 'attached']);

        expect(DB::table('invoice_notes')->count())->toBe(2)
            ->and(fn () => DB::table('invoice_notes')->insert(['tenant_id' => $this->beta->id, 'invoice_id' => $invoice->id, 'body' => 'cross-tenant']))
            ->toThrow(Illuminate\Database\QueryException::class);

        // Detaching is an application-level update; the parent delete cascades to what is still attached.
        DB::table('invoice_notes')->where('body', 'attached')->update(['invoice_id' => null]);
        Invoice::query()->whereKey($invoice->id)->delete();

        expect(DB::table('invoice_notes')->count())->toBe(2)
            ->and(DB::table('invoice_notes')->whereNull('invoice_id')->count())->toBe(2);
    });
});

it('refuses SET NULL and SET DEFAULT on a composite tenant foreign key before any SQL runs', function (): void {
    $define = fn (callable $action): Closure => fn () => SchemaFacade::create('optional_things', function (Blueprint $table) use ($action): void {
        $table->id();
        $table->tenantId();
        $action($table->tenantForeignId('thing_id', 'invoices', nullable: true));
    });

    expect($define(fn (TenantForeignKeyDefinition $fk) => $fk->nullOnDelete()))->toThrow(LogicException::class, 'ON DELETE SET NULL')
        ->and($define(fn (TenantForeignKeyDefinition $fk) => $fk->nullOnUpdate()))->toThrow(LogicException::class, 'ON UPDATE SET NULL')
        ->and($define(fn (TenantForeignKeyDefinition $fk) => $fk->onDelete(' Set   Null ')))->toThrow(LogicException::class, 'cascadeOnDelete() or restrictOnDelete()')
        ->and($define(fn (TenantForeignKeyDefinition $fk) => $fk->onUpdate('set default')))->toThrow(LogicException::class, 'ON UPDATE SET DEFAULT')
        ->and(SchemaFacade::hasTable('optional_things'))->toBeFalse();

    // The allowed actions still reach the grammar as a composite key; the guarded definition is the blueprint's command.
    $blueprint = new Blueprint(DB::connection(), 'optional_things');
    $blueprint->id();
    $blueprint->tenantId();
    $foreign = $blueprint->tenantForeignUuid('thing_id', 'things', nullable: true)->cascadeOnDelete()->restrictOnUpdate();
    $foreignUlid = $blueprint->tenantForeignUlid('other_id', 'others')->noActionOnDelete();

    expect($foreign)->toBeInstanceOf(TenantForeignKeyDefinition::class)
        ->and($foreign->onDelete)->toBe('cascade')
        ->and($foreign->onUpdate)->toBe('restrict')
        ->and($foreign->columns)->toBe(['tenant_id', 'thing_id'])
        ->and($foreign->references)->toBe(['tenant_id', 'id'])
        ->and($foreignUlid->onDelete)->toBe('no action')
        ->and(collect($blueprint->getCommands())->filter(fn ($command) => $command->name === 'foreign')->every(fn ($command) => $command instanceof TenantForeignKeyDefinition || $command->columns === ['tenant_id']))->toBeTrue()
        ->and(collect($blueprint->getCommands())->whereInstanceOf(TenantForeignKeyDefinition::class))->toHaveCount(2);
});

it('treats an empty insert as a no-op', function (): void {
    Tenancy::run($this->acme->id, function (): void {
        DB::enableQueryLog();

        expect(Invoice::query()->insert([]))->toBeTrue()
            ->and(Invoice::query()->insertOrIgnore([]))->toBe(0)
            ->and(DB::getQueryLog())->toBe([]);

        DB::disableQueryLog();
    });
});

it('describes resolvers for diagnostics and reads integer route parameters', function (): void {
    $chain = new ResolverChain(new HeaderResolver(['X-Tenant-Id']), new RouteParameterResolver(['tenant']), new SubdomainResolver(['app.example.test', 'app.example.org']), new SessionResolver);

    expect($chain->describe())->toContain('X-Tenant-Id', 'tenant', 'subdomain of app.example.test/app.example.org', ', ');

    $request = Request::create('/tenants/42');
    $route = (new RoutingRoute('GET', '/tenants/{tenant}', fn () => null))->bind($request);
    $route->setParameter('tenant', 42);
    $request->setRouteResolver(fn () => $route);

    expect((new RouteParameterResolver(['tenant']))->resolve($request)?->identifier)->toBe('42');
});
