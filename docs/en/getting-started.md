# Getting started

One scenario from an empty Laravel application to a running, verified
installation: a multi-tenant billing API with invoices. Every step is complete;
you should not need another page or the framework's source while you follow it.
The same code, runnable and tested, lives in
[`examples/laravel-app`](../../examples/laravel-app).

What you will have at the end: two tenants, a user who is a member of one of
them, an `Invoice` model that cannot be read or written outside its tenant, a
public id in every URL, an audit event for every business change that a
database administrator cannot alter unnoticed, a job that carries its tenant,
and a verified development installation: `security:doctor` reports every
check green except the two findings a development setup is expected to have
(SQLite, database-only anchors) — step 9 shows the exact output and the
[Production guide](guides/production.md) turns those two green as well.

## 0. Prerequisites

- PHP 8.3 or 8.4, Composer, a fresh Laravel 12 application (`laravel new billing`).
- PostgreSQL, MySQL 8 or MariaDB for anything beyond this walk-through. SQLite
  is fine while you follow it — the framework refuses SQLite in production and
  tells you so.
- For the API part: `php artisan install:api` (Laravel Sanctum).

## 1. Install

```bash
composer require "efnext/security-framework:^1.0@beta"   # pre-release: the stability flag is required (README → Status)
php artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config
```

This adds `config/tenant.php`, `config/audit.php` and `config/addressing.php`.
The framework's migrations (`tenants`, `tenant_memberships`, `audit_events`,
`audit_chain_heads`, `audit_anchors`, `audit_verification_runs`) are loaded
automatically and run first.

## 2. Environment

```bash
php artisan audit:key:generate
```

prints two lines; put them into `.env` together with the service name:

```dotenv
SECURITY_SERVICE_NAME=billing
TENANT_USER_KEY_TYPE=unsignedBigInteger      # the type of users.id — must match before the first migrate

AUDIT_SIGNING_KEYS="k1:base64:…"             # from audit:key:generate
AUDIT_SIGNING_ACTIVE_KEY=k1
```

`.env.example` in the framework repository lists every other variable with
its default. Then:

```bash
php artisan migrate
```

On MySQL/MariaDB with binary logging the migration user needs `SUPER` or the
server variable `log_bin_trust_function_creators=1`, because the audit tables
get triggers that refuse `UPDATE` and `DELETE`.

## 3. Users and tenants

```php
// app/Models/User.php
use Efnext\Security\Tenant\Membership\HasTenantMemberships;

class User extends Authenticatable
{
    use HasTenantMemberships;
    // …
}
```

Create two tenants and one membership — a seeder, Tinker, or a test:

```php
use Efnext\Security\Tenant\Eloquent\Tenant;

$acme = Tenant::create(['name' => 'Acme Ltd', 'slug' => 'acme']);
$beta = Tenant::create(['name' => 'Beta GmbH', 'slug' => 'beta']);

$user = User::factory()->create(['email' => 'alice@acme.test']);
$user->grantTenantMembership($acme->tenantId(), 'owner');
```

Tenants get a UUID, a public id (`ten_…`) and a status (`active`). Roles come
from `config/tenant.php` (`owner`, `admin`, `member`, `viewer`, highest first).

## 4. The invoice: tenant-owned, publicly addressable

```php
// app/Models/Invoice.php
use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;
use Efnext\Security\Addressing\Eloquent\HasPublicId;
use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model implements ExternallyAddressable
{
    use BelongsToTenant;
    use HasPublicId;
    use HasGlobalObjectId;

    protected $fillable = ['number', 'total_cents', 'status'];   // note: no tenant_id, no public_id

    public static function publicIdPrefix(): string { return 'inv'; }
    public static function resourceType(): string { return 'invoice'; }

    public function lines(): HasMany { return $this->hasMany(InvoiceLine::class); }

    public function markPaid(): void
    {
        $this->update(['status' => 'paid']);
    }
}
```

```php
// app/Models/InvoiceLine.php
class InvoiceLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['invoice_id', 'description', 'amount_cents'];
}
```

```php
// database/migrations/2026_01_01_000000_create_invoices_tables.php
Schema::create('invoices', function (Blueprint $table): void {
    $table->id();
    $table->tenantId();          // uuid tenant_id + FK to tenants + index + UNIQUE(tenant_id, id)
    $table->publicId();          // inv_… , unique
    $table->string('number');
    $table->unsignedBigInteger('total_cents')->default(0);
    $table->string('status')->default('open');
    $table->timestamps();
    $table->unique(['tenant_id', 'number']);   // uniqueness is per tenant, so the tenant column is part of it
});

Schema::create('invoice_lines', function (Blueprint $table): void {
    $table->id();
    $table->tenantId();
    $table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete();   // (tenant_id, invoice_id) -> invoices(tenant_id, id)
    $table->string('description');
    $table->unsignedBigInteger('amount_cents');
    $table->timestamps();
});
```

What this buys you: every query on `Invoice` and `InvoiceLine` is scoped to
the active tenant and *throws* without one; a line can only reference an
invoice of the same tenant, even through raw SQL; an invoice is addressed as
`inv_7f3k9zq2m4x8c1bt` in URLs and as `billing:invoice:inv_7f3k9zq2m4x8c1bt`
anywhere a global name is needed.

## 5. Routes

```php
// routes/api.php
use App\Http\Controllers\InvoiceController;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);          // {invoice} binds by public id
    Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->middleware('tenant.role:admin');
});
```

`tenant` is `tenant.resolve` (find the tenant, 400/404/403) followed by
`tenant.member` (is the authenticated user an active member? 401/403).
`tenant.role:admin` additionally requires the `admin` role or higher. The
framework places these before route model binding, so `{invoice}` is resolved
inside the tenant — an invoice of another tenant is a 404, not a leak.

The tenant comes from the `X-Tenant-Id` header by default (id, `ten_…` public
id, global object id or slug); route parameter, session and subdomain are the
alternatives (`config/tenant.php`, `resolution.chain`).

## 6. The controller: business change and audit record together

```php
// app/Http/Controllers/InvoiceController.php
use App\Models\Invoice;
use Efnext\Security\Audit\Facades\Audit;
use Illuminate\Http\Request;

class InvoiceController
{
    public function index()
    {
        return Invoice::query()->latest()->get(['public_id', 'number', 'total_cents', 'status']);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['number' => 'required|string', 'total_cents' => 'required|integer|min:0']);

        return Audit::transaction(function () use ($data) {
            $invoice = Invoice::create($data);                       // tenant_id and public_id are filled in
            Audit::action('invoice.created')->on($invoice)->with(['number' => $invoice->number])->record();

            return response()->json($invoice->only('public_id', 'number', 'status'), 201);
        });
    }

    public function show(Invoice $invoice)
    {
        return $invoice->load('lines');
    }

    public function pay(Invoice $invoice)
    {
        Audit::transaction(function () use ($invoice): void {
            $invoice->markPaid();
            Audit::action('invoice.paid')->on($invoice)->with(['total_cents' => $invoice->total_cents])->record();
        });

        return response()->noContent();
    }
}
```

`Audit::transaction()` is `DB::transaction()` plus the retry rules the chain
needs: business change and audit record commit together, and a conflict on the
tenant's chain (two payments at once) is retried with back-off instead of
failing. The closure may therefore run more than once — keep e-mails and API
calls outside of it.

## 7. Try it

Create a token for Alice (`$user->createToken('cli')->plainTextToken`) and:

```bash
curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: acme" -H "Accept: application/json" \
     -d number=A-1 -d total_cents=12500 http://localhost:8000/api/invoices
# {"public_id":"inv_7f3k9zq2m4x8c1bt","number":"A-1","status":"open"}

curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: acme" -H "Accept: application/json" \
     http://localhost:8000/api/invoices/inv_7f3k9zq2m4x8c1bt
# {"id":1,"public_id":"inv_7f3k9zq2m4x8c1bt","number":"A-1", … ,"lines":[]}
```

And the refusals, which are the point:

| Request | Response | Why |
| --- | --- | --- |
| no `X-Tenant-Id` | `400 {"message":"No tenant identifier found in the request (checked: header X-Tenant-Id, route parameter {tenant}, session key efnext.tenant)."}` | nothing to resolve |
| `X-Tenant-Id: nope` | `404 {"message":"Tenant not found."}` | unknown tenant |
| `X-Tenant-Id: beta` with Alice's token | `403 {"message":"You are not a member of this tenant."}` | Alice belongs to Acme only; an audit event `tenant.access_denied` is written |
| `POST /invoices/inv_…/pay` as a `member` | `403 {"message":"Your role in this tenant does not permit this action."}` | `tenant.role:admin` |
| `GET /invoices/inv_zzzzzzzzzzzzzzzz` | `404` | malformed or foreign id — no query is even made for a malformed one |
| `Invoice::count()` in Tinker without a tenant | `TenantContextMissing: No tenant context: cannot query App\Models\Invoice without an active tenant. Resolve a tenant first (tenant middleware), wrap the code in Tenancy::run(...), or declare a deliberate cross-tenant operation with Tenancy::withoutTenant("reason", ...)` | fail closed |

## 8. Jobs and commands

```php
use App\Jobs\SendInvoiceReminder;
use Efnext\Security\Tenant\Facades\Tenancy;

SendInvoiceReminder::dispatch($invoice);                       // inside a request: the tenant travels with the job
Tenancy::run($acme->tenantId(), fn () => Invoice::count());   // explicit context in code
```

```bash
php artisan tenant:run acme "invoices:remind"                  # explicit context on the CLI
```

Inside the job, `Invoice` queries are scoped to the tenant the job was
dispatched from; a job dispatched without a tenant fails closed when it touches
tenant-owned data. A job that knows its own tenant implements `TenantAware` and
returns `[new TenantContextMiddleware]` from `middleware()`.

## 9. Anchor, verify, doctor

```php
// routes/console.php
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->daily()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
```

Run them once by hand:

```
$ php artisan audit:anchor
  tenant:0192b6a4-3c1e-7d2f-9a5b-4e6c8d0f1a2b ....... anchored #2 (signed k1)
  Store ............................................ database
  Anchors written .................................. 1

$ php artisan audit:verify
  tenant:0192b6a4-3c1e-7d2f-9a5b-4e6c8d0f1a2b intact  2 events, signatures: valid, anchor: #2
  Chains checked ................................... 1
  Chains with violations ........................... 0

$ php artisan security:doctor
  [OK] Environment .................. APP_ENV=local, debug on, fail-fast on.
  [OK] Application key  APP_KEY is set (pseudonymisation pepper derived from it).
  [OK] Signing key ......................... 1 key(s) in the ring, active: k1.
  [FAIL] Database driver ................... Connection "sqlite" uses SQLite.
         Fine for development and tests; production needs PostgreSQL, MySQL or MariaDB (row locks across connections, durability).
  [OK] Append-only enforcement  Enabled (triggers are installed by the migration).
  [OK] Append-only triggers  All 4 triggers present on audit_events and audit_anchors.
  [WARN] Anchor stores ..................... Only the database store is readable.
         Anchors inside the audit database are not independent of it; … Add `filesystem` (S3 with Object Lock) … and consider AUDIT_REQUIRE_EXTERNAL_ANCHOR=true.
  [OK] Audit connection  Audit tables share the application connection "sqlite"; `Audit::transaction()` is atomic with business writes.
  [OK] Anchor freshness  1 chain(s) anchored at their head, 0 with recent unanchored events (within 60 min).
  [OK] Full verification  All 1 chain(s) fully verified and intact within the last 24 h.
  [OK] Tail verification  Last 200 event(s) of every chain verified now: 2 events, all intact.
  [OK] Tenant middleware priority  ResolveTenant runs before SubstituteBindings.
  [OK] Tenant lookup cache .. Store "file" (file), TTL 60s, negative TTL 5s.

  OK / WARN / FAIL / SKIP ...................................... 11 / 1 / 1 / 0
```

The two non-OK lines are exactly the two things that separate this walk-through
from production: a real database, and an anchor store outside it. The
[Production guide](guides/production.md) turns both green; `security:doctor
--strict` is the deployment gate that proves it.

## 10. Prove the guarantees in your own test suite

```php
use App\Models\Invoice;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Facades\Tenancy;

it('refuses tenant-owned queries without a tenant', function (): void {
    expect(fn () => Invoice::query()->count())->toThrow(TenantContextMissing::class);
});

it('does not leak invoices across tenants', function (): void {
    Tenancy::run($this->acme->tenantId(), fn () => Invoice::create(['number' => 'A-1']));

    Tenancy::run($this->beta->tenantId(), fn () => expect(Invoice::count())->toBe(0));
});

it('rejects a member of another tenant', function (): void {
    $this->actingAs($this->alice)
        ->getJson('/api/invoices', ['X-Tenant-Id' => 'beta'])
        ->assertForbidden();
});
```

The example application ships these and a few more; copy them.

## Where next

- [Tenancy guide](guides/tenancy.md) — resolvers, roles, switching tenants, cross-tenant work, custom columns.
- [Addressing guide](guides/addressing.md) — public and global ids in depth.
- [Auditing guide](guides/auditing.md) — what to record, metadata, actors, verification.
- [Production guide](guides/production.md) — keys, Object Lock, `--strict`, monitoring.
- [Troubleshooting](guides/troubleshooting.md) — by symptom.
- [Integration checklist](integration-checklist.md) — tick it before go-live.
