---
name: efnext-security-integration
description: Integrate the ef.next Security Framework into a Laravel application, or change code that touches tenant-owned models, tenant routes, audit records, public ids, queued jobs, Artisan commands or the production configuration of the framework. Includes how to verify the result.
---

# ef.next Security Framework integration

## When to use this skill

Use it when you add the framework to an application, add a tenant-owned model or table, protect a route, record an audit event, give a model a public id, write a job or command that touches tenant data, or prepare a production deployment. The framework fails closed: a query on a tenant-owned model without a tenant throws `TenantContextMissing`, a misconfigured production refuses to boot. Treat every refusal as a finding to fix, never as an error to silence.

Read https://github.com/ef-next/laravel-security-framework/blob/main/docs/en/what-it-does-not-do.md before you promise the user anything on the framework's behalf.

## Steps

1. Install (pre-release, so the stability flag is required):

   ```bash
   composer require "efnext/security-framework:^1.0@beta"
   php artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config
   php artisan migrate
   php artisan audit:key:generate   # put the output into .env
   ```

2. Users and tenants: add `Efnext\Security\Tenant\Membership\HasTenantMemberships` to the user model; create tenants with `Tenant::create(['name' => 'Acme', 'slug' => 'acme'])`; grant access with `$user->grantTenantMembership($tenant->tenantId(), 'owner')`. Call `Audit::prepareChain($tenant->tenantId())` once for every new tenant.

3. Tenant-owned models: `use Efnext\Security\Tenant\Eloquent\BelongsToTenant;` in the model, `$table->tenantId();` in the migration. Relations between tenant-owned tables:

   ```php
   $table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete();   // FK (tenant_id, invoice_id) -> invoices(tenant_id, id)
   ```

   Optional relation: `nullable: true` with `cascadeOnDelete()` or `restrictOnDelete()`. `nullOnDelete()` is refused by design; detach by update.

4. Public ids: `use HasPublicId;` (and `HasGlobalObjectId` plus `implements ExternallyAddressable` when other services must name the object), `publicIdPrefix()` and `resourceType()` as static methods, `$table->publicId();` in the migration. Route parameters such as `{invoice}` then bind by public id; unknown or malformed ids are a 404.

5. Routes:

   ```php
   Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
       Route::get('/invoices', [InvoiceController::class, 'index']);
       Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('tenant.role:admin');
   });
   ```

   The tenant arrives via the `X-Tenant-Id` header, a `{tenant}` route parameter, the session or a subdomain. Tenant-bound Sanctum tokens: `tenant.token`.

6. Audit records, always together with the business change:

   ```php
   Audit::transaction(function () use ($invoice) {
       $invoice->markPaid();
       Audit::action('invoice.paid')->on($invoice)->with(['method' => 'sepa'])->record();
   });
   ```

   Never `DB::transaction()` around an audit write. The closure may run more than once: queue e-mails and API calls after it returns. Metadata holds the fields the event is about, never `$request->all()` or whole models.

7. Jobs and commands: a job dispatched inside a tenant context carries it automatically. Elsewhere use `Tenancy::run($tenantId, fn () => ...)` or `php artisan tenant:run <tenant> <command>`. Cross-tenant work only through `Tenancy::withoutTenant('reason', fn () => ...)`.

8. Scheduler: `audit:anchor` every few minutes, `audit:verify` at least daily.

9. Production `.env`: signing key from a secret manager, `AUDIT_ANCHOR_STORES=filesystem,database` with a disk on object storage that has Object Lock, `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true`, `AUDIT_FAIL_FAST=true`. Details: https://github.com/ef-next/laravel-security-framework/blob/main/docs/en/guides/production.md

## Never

- `withoutGlobalScope(TenantScope::class)`, `DB::table()` or raw SQL on tenant-owned tables.
- The tenant column in `$fillable`; bulk `insert()` of tenant-owned or public-id models.
- Catching `TenantContextMissing` to make an error disappear.
- `AUDIT_FAIL_FAST=false`, `AUDIT_ENFORCE_APPEND_ONLY=false`, dropping the audit triggers or editing the audit migration to get an environment running.
- Treating a public id as authorization, or changing `SECURITY_SERVICE_NAME` after ids were handed out.

## Verify your work

```bash
php artisan migrate --pretend          # the audit migration must not be skipped or edited
php artisan security:doctor --strict   # exit 0 is the deployment gate; read every FAIL/WARN line, each names the fix
php artisan audit:verify               # exit 0: every chain intact
```

Add at least these tests to the application, then run its whole suite:

```php
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;

it('refuses tenant-owned queries without a tenant', function (): void {
    expect(fn () => Invoice::query()->count())->toThrow(TenantContextMissing::class);
});

it('does not leak rows across tenants', function (): void {
    Tenancy::run($acme->id, fn () => Invoice::create(['number' => 'A-1']));
    Tenancy::run($beta->id, fn () => expect(Invoice::count())->toBe(0));
});
```

and one request test per protected route: no tenant → 400 or 404, a foreign tenant → 403, a member → 200. A working application with exactly these tests: https://github.com/ef-next/laravel-security-framework/tree/main/examples/laravel-app

## When something fails

| Symptom | Meaning | Do |
| --- | --- | --- |
| `TenantContextMissing` | a code path without a tenant | `tenant` middleware, `Tenancy::run()` or a justified `withoutTenant('reason')` |
| trigger creation fails on MySQL/MariaDB | missing `SUPER` or `log_bin_trust_function_creators` | migrate with a privileged user or set the server variable |
| `InsecureConfiguration` at boot | production without key, on SQLite, triggers off, no readable or (if required) external anchor | configure what the message names |
| `security:doctor --strict` fails `anchor_stores` | external anchoring not required or the store does not answer | `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true`, `filesystem` first in `AUDIT_ANCHOR_STORES`, check the disk |
| `LogicException` about SET NULL | `nullOnDelete()` on a composite tenant key | `nullable: true` with cascade or restrict |
| deadlocks or serialization failures around audit writes | business transaction without retry | `Audit::transaction()`; `Audit::prepareChain()` for new tenants |
| route binding returns 404 for an existing row | wrong prefix, malformed id or another tenant's row | check the id and the active tenant; for a foreign tenant the 404 is correct |

Reference pages: https://github.com/ef-next/laravel-security-framework/tree/main/docs/en/reference (configuration, Artisan commands, exceptions, events, public API) and https://github.com/ef-next/laravel-security-framework/blob/main/docs/en/guides/troubleshooting.md
