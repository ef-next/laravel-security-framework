# Jobs and commands

How the tenant context travels into queued jobs, scheduled commands and
closures — and how it deliberately does not.

## Implicit propagation (default)

```php
// In a request with an active tenant, or inside Tenancy::run():
GenerateStatement::dispatch($invoice);
```

The framework registers a payload hook: every job dispatched while a tenant is
active carries `{"efnext_tenant": {"id": "…"}}` in its payload. When a worker
picks the job up, the tenant is activated before `handle()` runs and the
worker's previous state is restored afterwards (relevant for the `sync` driver
and for Octane workers). Inside the job, tenant-owned models are scoped as in
the request that dispatched it.

What is **not** propagated: a `withoutTenant()` bypass. A job dispatched inside
a cross-tenant block runs without a tenant and fails closed as soon as it
touches tenant-owned data — by design, so that a maintenance script cannot
accidentally spawn unscoped background work.

Configuration: `tenant.queue.propagate` (default `true`). With `false` no job
carries a tenant; every job then needs the explicit form below.

## Explicit: the job knows its tenant

```php
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Queue\TenantAware;
use Efnext\Security\Tenant\Queue\TenantContextMiddleware;

final class NightlyReconciliation implements ShouldQueue, TenantAware
{
    use Queueable;

    public function __construct(private readonly TenantId $tenantId) {}

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function middleware(): array
    {
        return [new TenantContextMiddleware];   // activates tenantId() around handle(); wins over the payload
    }

    public function handle(): void
    {
        Invoice::query()->where('status', 'open')->chunkById(500, fn ($invoices) => …);
    }
}
```

Use this for jobs that are dispatched from tenant-less places (schedulers,
webhooks, other jobs) or that must never depend on the dispatcher's context.
`TenantContextMiddleware` on a job that does not implement `TenantAware`
throws a `LogicException` — the mistake is caught in the first test run.

## Console commands

```bash
php artisan tenant:run acme "invoices:remind --dry-run"   # tenant by id, ten_… public id, global object id or slug
php artisan tenant:run acme "queue:work --once"           # even the worker itself, for debugging
```

A suspended or archived tenant is refused unless `--allow-inactive` is given;
the exit code is that of the inner command.

Inside your own command:

```php
public function handle(): int
{
    return Tenancy::run($this->argument('tenant'), function (): int {
        // scoped
        return self::SUCCESS;
    });
}
```

## Scheduled work for every tenant

```php
// routes/console.php
Schedule::call(function (): void {
    Tenancy::withoutTenant('nightly invoice reminders for all active tenants', function (): void {
        Tenant::query()->where('status', 'active')->each(function (Tenant $tenant): void {
            SendInvoiceReminders::dispatch($tenant->tenantId());   // TenantAware job → explicit tenant, not the bypass
        });
    });
})->dailyAt('06:00')->onOneServer();
```

The loop over tenants is a cross-tenant operation and is audited as such
(`tenant.scope_bypassed` with the reason and the calling file/line on the
global chain). Each dispatched job then runs inside exactly one tenant.

## Closures and tests

```php
Tenancy::run($tenantId, fn () => Invoice::count());          // previous context restored afterwards

// Pest
beforeEach(function (): void {
    $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
});

it('counts only the tenant\'s invoices', function (): void {
    Tenancy::run($this->acme->tenantId(), fn () => Invoice::factory()->count(3)->create());
    Tenancy::run($this->acme->tenantId(), fn () => expect(Invoice::count())->toBe(3));
});
```

Factories work inside `Tenancy::run()` because `creating` stamps the tenant.
A test that touches a tenant-owned model *outside* any context fails with
`TenantContextMissing` — that is the test telling you the production code
path has the same hole.

## Symptoms

| Symptom | Cause | Fix |
| --- | --- | --- |
| `TenantContextMissing` inside a job | dispatched without a context, or from a `withoutTenant()` block, or `propagate` is off | make the job `TenantAware`, or dispatch inside `Tenancy::run()` |
| a job processed data of the *wrong* tenant | it was dispatched inside `Tenancy::run($other, …)` | check the dispatch site; consider `TenantAware` for jobs that carry their tenant in their constructor anyway |
| `LogicException: … must implement TenantAware to use TenantContextMiddleware` | middleware on a plain job | implement the interface |
| the sync driver "leaks" a tenant into the next test | none — the previous state is restored after each job; check for your own `Tenancy::activate()` calls | use `Tenancy::run()` in tests |
