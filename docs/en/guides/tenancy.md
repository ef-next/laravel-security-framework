# Tenancy guide

Recipes for `efnext/security-tenant`. Each one has the same shape: the use
case, the code, what it does for security, the typical misconfiguration, and
the test that should live in *your* application. The mental model is in
[Concepts](../concepts.md); the API in [reference/api.md](../reference/api.md).

## 1. Resolve the tenant from a header, a route parameter, the session or a subdomain

**Use case.** APIs send `X-Tenant-Id`; a web app keeps the tenant in the
session; a marketing-style product uses `acme.app.example.com`.

```php
// config/tenant.php
'resolution' => [
    'chain' => ['header', 'route', 'session', 'subdomain'],   // first hit wins
    'header' => ['X-Tenant-Id'],
    'route_parameters' => ['tenant'],
    'session_key' => 'efnext.tenant',
    'subdomain' => ['base_domains' => ['app.example.com']],
],
```

```php
Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class);
});

Route::middleware(['auth', 'tenant'])->prefix('/t/{tenant}')->group(function (): void {   // route parameter
    Route::get('/invoices', [InvoiceController::class, 'index']);
});

Route::middleware(['auth', 'tenant.resolve:optional'])->get('/help', HelpController::class);   // may run without a tenant
```

The identifier may be the tenant's UUID, its public id (`ten_…`), its global
object id (`billing:tenant:ten_…` — this service only) or its slug; every
shape is validated before a query runs, and unknown identifiers are cached
briefly so scanning is cheap for you and useless for the scanner.

**Security effect.** Resolution never grants anything; it only selects the
tenant. `tenant.member` (part of the `tenant` group) then requires an active,
unexpired membership. Missing identifier → 400, unknown → 404, suspended → 403,
not a member → 403 plus an audit event.

**Misconfiguration.** Listing `subdomain` without `base_domains` (it never
matches); putting `tenant.resolve` on a route but forgetting `tenant.member`
(the tenant is selected, membership is not checked — use the `tenant` group
unless you have a reason).

**Your test.**

```php
it('selects the tenant from the header and refuses non-members', function (): void {
    $this->actingAs($this->alice)->getJson('/api/invoices', ['X-Tenant-Id' => 'acme'])->assertOk();
    $this->actingAs($this->alice)->getJson('/api/invoices', ['X-Tenant-Id' => 'beta'])->assertForbidden();
    $this->actingAs($this->alice)->getJson('/api/invoices')->assertStatus(400);
});
```

## 2. Roles

**Use case.** Owners manage billing, admins manage users, members read.

```php
// config/tenant.php
'memberships' => [
    'roles' => ['owner', 'admin', 'member', 'viewer'],   // highest first
    'hierarchical' => true,                              // owner satisfies admin
    'default_role' => 'member',
],
```

```php
Route::post('/billing', …)->middleware('tenant.role:owner');
Route::post('/users', …)->middleware('tenant.role:owner,admin');    // either one (or higher)

$user->grantTenantMembership($tenantId, 'admin');
$user->hasTenantRole($tenantId, 'member');                           // true for admin and owner in hierarchical mode
```

**Security effect.** A role outside the configured vocabulary can neither be
granted nor satisfy a check. Denials dispatch `TenantAccessDenied` with the
required roles, which the audit package records.

**Misconfiguration.** `hierarchical => false` while routes only name the lowest
role: owners get 403. A `default_role` with privileges: every invitation
becomes an admin.

**Your test.** One request per protected route with a `viewer`, expecting 403.

## 3. Switch the tenant in the UI

**Use case.** A user belongs to several tenants and picks one in a menu.

```php
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Http\SessionTenant;

Route::post('/switch-tenant', function (Request $request, SessionTenant $session) {
    $session->switchTo($request, TenantId::fromString((string) $request->string('tenant')));   // 403 unless the user is an active member

    return redirect('/dashboard');
})->middleware('auth');
```

With `session` in the resolution chain every following request resolves the
chosen tenant. `$session->current($request)` reads it, `$session->forget($request)`
clears it.

**Security effect.** `switchTo()` checks membership exactly like the
middleware and dispatches `TenantSwitched` (audited as `tenant.switched` with
the previous tenant). A user cannot "switch" into a tenant they do not belong to.

**Misconfiguration.** Writing the session key yourself instead of calling
`switchTo()` — that skips the membership check and the audit event.

## 4. Tenant-owned models, custom column names

```php
class Project extends Model
{
    use BelongsToTenant;

    protected string $tenantColumn = 'org_id';   // when the schema uses another name
}

Schema::create('projects', function (Blueprint $table): void {
    $table->id();
    $table->tenantId('org_id');                  // same column here
    // …
});
```

Everything else is unchanged: scope, stamping on create, ownership checks,
guarded mass assignment. Do not list the tenant column in `$fillable`; if you
must (import tooling), the framework still refuses a value that differs from
the active tenant (`TenantMismatch`).

**Your test.** `expect(fn () => Project::query()->count())->toThrow(TenantContextMissing::class);`

## 5. Relations between tenant-owned tables

```php
Schema::create('invoice_lines', function (Blueprint $table): void {
    $table->id();
    $table->tenantId();
    $table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete();                    // required
    $table->tenantForeignUuid('approved_by_id', 'approvers', nullable: true)->restrictOnDelete();   // optional
});
```

**Security effect.** The composite key `(tenant_id, invoice_id) →
invoices(tenant_id, id)` makes the database refuse a line that references
another tenant's invoice — through Eloquent, `DB::table()`, raw SQL or a
migration script alike.

**Misconfiguration.** `foreignId('invoice_id')->constrained()` (a plain key:
the database no longer checks the tenant); `nullOnDelete()` on a composite key
(refused at migration authoring time — the tenant column cannot be cleared;
detach by update). Uniqueness that should be per tenant must include the
tenant column: `$table->unique(['tenant_id', 'number'])`.

**Your test.**

```php
it('refuses a line that points at another tenant\'s invoice, even through raw SQL', function (): void {
    $invoice = Tenancy::run($this->acme->tenantId(), fn () => Invoice::create(['number' => 'A-1']));

    expect(fn () => DB::table('invoice_lines')->insert([
        'tenant_id' => $this->beta->id, 'invoice_id' => $invoice->id, 'description' => 'x', 'amount_cents' => 1,
    ]))->toThrow(QueryException::class);
});
```

## 6. Unique constraints and upserts inside a tenant

```php
Invoice::query()->upsert(
    [['number' => 'A-1', 'total_cents' => 100], ['number' => 'A-2', 'total_cents' => 200]],
    uniqueBy: ['tenant_id', 'number'],            // the tenant column is stamped into every row for you
    update: ['total_cents'],
);
```

The tenant-aware builder stamps the active tenant into every row of
`insert()`, `insertOrIgnore()`, `insertGetId()` and `upsert()`, refuses a
conflicting value, and rejects the tenant column in `update()`, `increment()`,
`decrement()` and the update list of `upsert()`. Note that conflict detection
itself is the database's: the unique index has to include the tenant column,
otherwise two tenants cannot both have an invoice `A-1`.

Not guarded: `insertUsing()` / `insertOrIgnoreUsing()` (sub-query inserts) —
treat them like raw SQL in code review.

## 7. Jobs, commands, closures

```php
// Implicit: dispatched inside a request or Tenancy::run() → the tenant travels in the payload
GenerateStatement::dispatch($invoice);

// Explicit: the job knows its tenant
final class NightlyReconciliation implements ShouldQueue, TenantAware
{
    public function __construct(private readonly TenantId $tenantId) {}

    public function tenantId(): TenantId { return $this->tenantId; }

    public function middleware(): array { return [new TenantContextMiddleware]; }

    public function handle(): void { Invoice::query()->where('status', 'open')->each(…); }
}

// Code
Tenancy::run($tenantId, fn () => Invoice::count());

// CLI
php artisan tenant:run acme "invoices:remind --dry-run"
```

A scheduled command that must run for every tenant loops explicitly:

```php
Tenancy::withoutTenant('nightly reminders for all tenants', function (): void {
    Tenant::query()->where('status', 'active')->each(function (Tenant $tenant): void {
        Tenancy::run($tenant->tenantId(), fn () => InvoiceReminder::send());
    });
});
```

**Security effect.** A job without a tenant fails closed the moment it touches
tenant-owned data. A `withoutTenant()` bypass is never propagated into jobs.
The reason and the calling code of every bypass are audited.

**Misconfiguration.** `tenant.queue.propagate = false` without making jobs
`TenantAware`; dispatching from `withoutTenant()` and expecting the job to
inherit the bypass (it does not — by design).

**Your test.** Dispatch a job inside `Tenancy::run()` with the `sync` driver and
assert it saw only that tenant's rows.

## 8. Controlled cross-tenant maintenance

```php
$total = Tenancy::withoutTenant('monthly revenue report for finance', function (): int {
    return Invoice::query()->where('status', 'paid')->sum('total_cents');   // all tenants
});

Tenancy::withoutTenant('GDPR export for support ticket 4711', function () use ($tenantId): void {
    Invoice::forTenant($tenantId)->get();                                   // explicit even inside a bypass
});
```

**Security effect.** The reason is mandatory and, together with the file and
line of the caller, ends up in the audit log (`tenant.scope_bypassed`, on the
tenant's chain or the global one). That is the trail an auditor asks for.

**Misconfiguration.** `withoutGlobalScope(TenantScope::class)` — the same
effect without the audit trail; grep for it in code review. Empty reasons are
refused.

## 9. Sanctum tokens bound to a tenant

See the [Sanctum tokens guide](sanctum-tokens.md).

## 10. Membership lifecycle

```php
$user->grantTenantMembership($tenantId, 'member', expiresAt: now()->addDays(30));   // invite that expires
$user->changeTenantMembershipStatus($tenantId, MembershipStatus::Suspended);
$user->revokeTenantMembership($tenantId);
```

Each call dispatches `MembershipChanged` (audited as `tenant.membership.granted`,
`…role_changed`, `…status_changed`, `…revoked`). A suspended or expired
membership is refused by `tenant.member` with a specific message; the cached
tenant record is unaffected (memberships are not cached).

## Things the tenant package deliberately does not do

- filter `DB::table()`, raw SQL, views or stored procedures;
- authorize actions *within* a tenant (use policies);
- support moving records between tenants (`TenantColumnImmutable`);
- guard `insertUsing()`;
- cache memberships (every request checks the table; the migration indexes
  `(tenant_id, user_id)` uniquely and `(user_id, status)`).

Read [What it does not do](../what-it-does-not-do.md) before relying on any of it.
