## ef.next Security Framework (efnext/security-framework)

This application uses the ef.next Security Framework: fail-closed tenant isolation (`efnext/security-tenant`), a tamper-evident audit log (`efnext/security-audit`) and unguessable public ids (`efnext/security-addressing`). A query on a tenant-owned model without an active tenant throws `TenantContextMissing` on purpose. For integration work use the `efnext-security-integration` skill; the full contract is https://github.com/ef-next/laravel-security-framework/blob/main/AGENTS.md

### Rules that keep the guarantees

- Tenant-owned models use `BelongsToTenant`; their migrations use `$table->tenantId()`. A relation between two tenant-owned tables uses `$table->tenantForeignId('invoice_id', 'invoices')` (composite key with the tenant). `nullOnDelete()` on such a key is refused: use `nullable: true` with cascade or restrict.
- Never call `withoutGlobalScope(TenantScope::class)`, never use `DB::table()` or raw SQL on tenant-owned tables. Cross-tenant work goes through `Tenancy::withoutTenant('reason', fn () => ...)`, which is audited.
- Never add the tenant column to `$fillable` and never catch `TenantContextMissing`. Give the code path a tenant instead: the `tenant` route middleware, `Tenancy::run($tenantId, fn () => ...)` or `php artisan tenant:run <tenant> <command>`.
- Protected routes: `Route::middleware(['auth:sanctum', 'tenant'])`; roles with `tenant.role:admin`; tenant-bound Sanctum tokens with `tenant.token`.
- Audit records: `Audit::action('invoice.paid')->on($invoice)->with(['amount' => $amount])->record()`. A business change and its audit record always run inside `Audit::transaction(fn () => ...)`, never `DB::transaction()`. The closure may run more than once, so no e-mails or API calls inside it. A new tenant gets `Audit::prepareChain($tenantId)` once.
- Audit metadata holds only the fields the event is about, never `$request->all()` or whole models. It is immutable and sanitised, not encrypted.
- Public ids prevent enumeration; they are not authorization. Access is decided by the tenant middleware and the application's policies.
- Never create tenant-owned or public-id models with bulk `insert()`; Eloquent events set the tenant and the id.
- Never set `AUDIT_FAIL_FAST=false` or `AUDIT_ENFORCE_APPEND_ONLY=false`, never drop the audit triggers and never change `SECURITY_SERVICE_NAME` after ids were handed out. Fix what `security:doctor` names instead.

### Verify your work

@verbatim
<code-snippet name="Check an integration" lang="bash">
php artisan security:doctor --strict   # exit 0 is the deployment gate; every FAIL/WARN line names the fix
php artisan audit:verify               # exit 0: every audit chain is intact
</code-snippet>
@endverbatim

Every protected route needs request tests: no tenant → 400 or 404, a foreign tenant → 403, a member → 200.
