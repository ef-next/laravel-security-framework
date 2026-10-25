# Instructions for AI agents

This file is for agents (and people in a hurry). It has two parts: the
**integration contract** for an agent that adds this framework to an
application, and the **repository rules** for an agent that changes the
framework itself. Everything here is also documented for humans in the
[README](README.md), `docs/en/` and [CONTRIBUTING.md](CONTRIBUTING.md); when
the two disagree, the human documentation and the code win — and please open an
issue.

## Part 1 — Integrating the framework into an application

If the application uses [Laravel Boost](https://laravel.com/docs/boost),
`php artisan boost:install` has already put the short form of this part into
your context (`resources/boost/guidelines/core.blade.php`) and offers the skill
`efnext-security-integration`. This part is the complete version.

### What you are integrating

Three Laravel packages, installed together as `efnext/security-framework`:

| Package | Purpose | Fails closed by |
| --- | --- | --- |
| `efnext/security-tenant` | resolve the tenant of a request/job/command, scope every query on tenant-owned models, enforce membership and roles, carry the tenant through queues | throwing `TenantContextMissing` on any query of a tenant-owned model without a tenant; 404/403 before a controller runs |
| `efnext/security-audit` | append-only, hash-chained, HMAC-signed, externally anchored audit log per tenant, with a verifier and a doctor | refusing to boot in production without a signing key, on SQLite, without append-only triggers or (opt-in) without an external anchor |
| `efnext/security-addressing` | unguessable public ids (`inv_7f3k9zq2m4x8c1bt`) and global object ids (`billing:invoice:inv_…`) for models | 404 for malformed or unknown ids in route binding, never a database query for a malformed id |

Read [docs/en/what-it-does-not-do.md](docs/en/what-it-does-not-do.md) before
you promise anything to the user on the framework's behalf.

Where to look things up while you work (all under `docs/en/`; `llms.txt` at
the repository root indexes them):

| Need | Page |
| --- | --- |
| a complete, working integration to copy from | [examples/laravel-app](examples/laravel-app/README.md) — models, migrations, routes, controller, job and the tests; CI installs and runs it on every push |
| the meaning of a config key or environment variable | [reference/configuration.md](docs/en/reference/configuration.md); `.env.example` lists every variable |
| an Artisan command's options and exit codes | [reference/artisan-commands.md](docs/en/reference/artisan-commands.md) |
| an exception, its HTTP status and the fix | [reference/exceptions.md](docs/en/reference/exceptions.md) |
| facades, traits, middleware, blueprint macros | [reference/api.md](docs/en/reference/api.md) |
| a symptom (status code, doctor line, verifier reason) | [guides/troubleshooting.md](docs/en/guides/troubleshooting.md) |
| the production setup and the deployment gate | [guides/production.md](docs/en/guides/production.md) |

### Steps (each is complete; the README shows the code)

1. `composer require "efnext/security-framework:^1.0@beta"` (pre-release — Composer's default stability `stable` would not select it; until the package is on Packagist, `composer config repositories.efnext vcs <repository url>` first), publish the three configs, `php artisan migrate`, `php artisan audit:key:generate` → `.env`.
2. Users: add `HasTenantMemberships` to the user model; create tenants with `Tenant::create([...])`; grant memberships with `$user->grantTenantMembership($tenant->tenantId(), 'owner')`.
3. Tenant-owned models: `use BelongsToTenant`; migration `$table->tenantId()`; relations between tenant-owned tables `$table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete()` (composite foreign key). Optional relation: `nullable: true` — `nullOnDelete()` is refused by design.
4. Public ids: `use HasPublicId` (+ `HasGlobalObjectId` and `implements ExternallyAddressable` when other services must name the object), `publicIdPrefix()`, `resourceType()`; migration `$table->publicId()`. Route binding uses the public id automatically.
5. Routes: `Route::middleware(['auth:sanctum', 'tenant'])` (`tenant` = `tenant.resolve` + `tenant.member`); roles with `tenant.role:admin`; Sanctum token binding with `tenant.token`. The tenant arrives via `X-Tenant-Id`, a `{tenant}` route parameter, the session or a subdomain.
6. Audit: `Audit::action('invoice.paid')->on($invoice)->with([...])->record()`; business change plus audit record always inside `Audit::transaction(fn () => ...)`; new tenants get `Audit::prepareChain($tenantId)` once.
7. Jobs and commands: jobs dispatched inside a tenant context carry it automatically; explicit context with `Tenancy::run($tenantId, fn () => ...)` or `php artisan tenant:run <tenant> <command>`.
8. Scheduler: `audit:anchor` every few minutes, `audit:verify` at least daily.
9. Production `.env` (see `.env.example`, block "Production"): signing key from a secret manager, `AUDIT_ANCHOR_STORES=filesystem,database` with a disk on object storage that has Object Lock, `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true`, `AUDIT_FAIL_FAST=true`.

### Invariants you must keep

- Never call `withoutGlobalScope(TenantScope::class)`, never query tenant-owned tables with `DB::table()` or raw SQL in application code. Cross-tenant work goes through `Tenancy::withoutTenant('reason', fn () => ...)`, which is audited.
- Never add the tenant column to `$fillable`; the framework sets it from the context.
- Never catch `TenantContextMissing` to make an error go away. It means a route, job, command or test runs without a tenant: add the `tenant` middleware, `Tenancy::run()`, or a justified `withoutTenant()`.
- Never wrap an audit write in `DB::transaction()`; use `Audit::transaction()` (same semantics plus the retry and back-off rules the chain needs). The closure may run more than once: no e-mails or API calls inside it.
- Never pass `$request->all()` or whole models into audit metadata; pass the fields the event is about. Metadata is immutable and sanitised, not encrypted.
- Never set `AUDIT_FAIL_FAST=false`, `AUDIT_ENFORCE_APPEND_ONLY=false` or remove the audit triggers to get an environment running. Fix the configuration the doctor names instead.
- Never treat a public id as authorization. It stops enumeration; the tenant middleware and the application's policies decide access.
- Never insert tenant-owned or public-id models with bulk `insert()`; Eloquent events set the tenant and the id.
- Never change `SECURITY_SERVICE_NAME` after ids have been handed out.

### How you verify your own work

```bash
php artisan migrate --pretend          # the audit migration must not be skipped or edited
php artisan security:doctor --strict   # exit 0 is the deployment gate; read every FAIL/WARN line, each names the fix
php artisan audit:verify               # exit 0: every chain intact
```

Add to the application's test suite, at minimum:

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

and a request test per protected route: no tenant header → 400/404, foreign
tenant → 403, member → 200. `examples/laravel-app/tests/Feature/` contains
exactly these tests for a billing API; copy and rename them. Then run the
whole suite of the application; the framework's own guarantees table in the
README tells you which condition each guarantee depends on — check each
condition against what you built.

### When something fails

| Symptom | Meaning | Do |
| --- | --- | --- |
| `TenantContextMissing` | code path without tenant | middleware / `Tenancy::run()` / `withoutTenant('reason')` |
| migration fails creating triggers on MySQL/MariaDB | missing `SUPER` or `log_bin_trust_function_creators` | run the migration with a privileged user or set the server variable |
| `InsecureConfiguration` at boot | production without key / on SQLite / triggers off / no readable or (if required) external anchor | configure what the message names; do not switch fail-fast off |
| `security:doctor --strict` fails `anchor_stores` | `AUDIT_REQUIRE_EXTERNAL_ANCHOR` is off or the external store does not answer | set it, put `filesystem` first in `AUDIT_ANCHOR_STORES`, check the disk |
| `Foreign key constraint is incorrectly formed` / `LogicException` about SET NULL | `nullOnDelete()` on a composite tenant key | `nullable: true` with cascade or restrict; detach by update |
| deadlocks / serialization failures around audit writes | business transaction without retry | `Audit::transaction()`, `Audit::prepareChain()` for new tenants |
| route binding returns 404 for an existing row | wrong prefix, malformed id, or wrong tenant | check the id and the active tenant; that 404 is the intended answer for a foreign tenant |

## Part 2 — Changing the framework itself

- Read [CONTRIBUTING.md](CONTRIBUTING.md) first; it lists what every change must touch (docs, CHANGELOG, tests).
- Run everything through `./start-security-framework.sh` (Docker). Do not install PHP or databases on the host and do not edit `vendor/`.
- Do not weaken a refusal into a warning, do not remove a second defence layer because the first one exists, and do not regenerate `packages/audit/tests/Fixtures/hash-vectors.json` — a failing `HashVectorsTest` means you changed the hash format, which is a new `HashableRecord::VERSION`.
- Keep the structure: `packages/{addressing,tenant,audit}` depend in that direction only (`tests/Arch/ArchitectureTest.php` enforces it); `Hashing` and `Sanitizing` stay free of framework dependencies.
- Documentation: English first in `docs/en/` (users and operators) and `docs/maintainers/`; translations in `docs/de/` and `docs/sv/` follow a release and start with `<!-- source: docs/en/<page>.md sha256: <hash of that file> -->`. Do not edit a translation together with its English source; `bin/docs-check` reports the drift and the translation is refreshed after the release.
- `bin/docs-check` is a gate: every `env()` in a package config must be in `.env.example` and `docs/en/reference/configuration.md`; every command, exception and event class in its reference page; relative links must resolve; `php` blocks must parse (mark a deliberate fragment with `...`).
- A change to an integration step, a default or a refusal is also a change to `examples/laravel-app` (its tests run in CI), to Part 1 of this file and to the Laravel Boost guideline and skill in `resources/boost/` – the same contract at three lengths.
- Commits are conventional (`feat(audit): …`, `fix(tenant): …`, `docs: …`); the CHANGELOG entry explains the consequence for a user, not the diff.
