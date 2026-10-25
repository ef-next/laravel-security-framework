# ef.next Security Framework

Fail-closed tenant isolation, a tamper-evident audit log and safe public identifiers for Laravel 12 on PHP 8.3/8.4 (Laravel 13 and PHP 8.5: experimental, see [Compatibility](#compatibility)).

**Status: pre-release.** The current version line is `1.0.0-beta` (see the [CHANGELOG](CHANGELOG.md)); the API can still change before `1.0.0`, and every change is listed there. Releases are signed tags with SBOMs ([Releasing](docs/maintainers/releasing.md)).

| Package | What it does |
| --- | --- |
| `efnext/security-tenant` | Resolves the tenant of a request, job or command; refuses every query on tenant-owned models without a tenant; enforces memberships and roles; propagates the tenant through queues. |
| `efnext/security-audit` | Appends events to per-tenant hash chains with sequence numbers, database triggers against UPDATE/DELETE, HMAC signatures with keys outside the database, external anchors and a verifier. |
| `efnext/security-addressing` | Unguessable, readable public ids (`dom_7f3k9zq2m4x8c1bt`) and global object ids (`example-service:domain:dom_…`) for models. |

One distribution, three components: the `efnext/security-framework` package contains all three (the component names are reserved via `replace` and will become separately installable once the monorepo is split for a stable release). Composer's default stability is `stable`, so a pre-release has to be asked for explicitly:

```bash
# until the package is on Packagist: point Composer at the repository once
composer config repositories.efnext vcs https://github.com/ef-next/laravel-security-framework

composer require "efnext/security-framework:^1.0@beta"
php artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config
php artisan migrate
php artisan audit:key:generate   # put the output into .env
php artisan security:doctor      # would this installation keep the guarantees below? exit 1 if not
```

Read [What it does not do](docs/en/what-it-does-not-do.md) before relying on any of it.

## Ten-minute integration

All three packages in one walk-through; each step is complete on its own.

**1. Tenants and users**

```php
use Efnext\Security\Tenant\Membership\HasTenantMemberships;

class User extends Authenticatable
{
    use HasTenantMemberships;
}

$tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
$user->grantTenantMembership($tenant->tenantId(), 'owner');
```

**2. Tenant-owned models**

```php
use Efnext\Security\Tenant\Eloquent\BelongsToTenant;

class Invoice extends Model
{
    use BelongsToTenant;   // every query is scoped; no tenant -> exception
}

// migration
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->tenantId();    // uuid column + FK to tenants + index + UNIQUE(tenant_id, id)
    // ...
});

Schema::create('invoice_lines', function (Blueprint $table) {
    $table->id();
    $table->tenantId();
    $table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete();   // FK (tenant_id, invoice_id) -> invoices(tenant_id, id)
});
```

The composite foreign key is defence in depth below Eloquent: even raw SQL cannot attach a line to another tenant's invoice. Optional relations use `nullable: true` with `cascadeOnDelete()` or `restrictOnDelete()`; `nullOnDelete()` is refused at migration time because a composite key cannot set only one of its columns to NULL (MySQL/MariaDB would reject the constraint, PostgreSQL the delete).

**3. Public ids (Addressing)**

```php
use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;
use Efnext\Security\Addressing\Eloquent\HasPublicId;

class Invoice extends Model implements ExternallyAddressable
{
    use BelongsToTenant;
    use HasPublicId;          // inv_7f3k9zq2m4x8c1bt, assigned on create, immutable, used for route binding
    use HasGlobalObjectId;    // billing:invoice:inv_7f3k9zq2m4x8c1bt (SECURITY_SERVICE_NAME=billing)

    public static function publicIdPrefix(): string { return 'inv'; }
    public static function resourceType(): string { return 'invoice'; }
}

// migration: add to the table from step 2
$table->publicId();          // unique, indexed column (default name: public_id)
```

`{invoice}` in a route now binds by public id (an unknown or malformed id is a 404, never an exception), `Invoice::query()->wherePublicId($id)` and `->whereGlobalObjectId($id)` look one up, `$invoice->publicId()` / `->globalObjectId()` hand them out. Use the public id in URLs, APIs and logs; use the global object id when another service or an audit trail has to name the object unambiguously. Public ids stop enumeration, they are not authorization: the tenant middleware and your policies still decide who may see the invoice. Bulk `insert()` bypasses Eloquent events and therefore assigns no id (the column is `NOT NULL`, so the database refuses the row); create through the model.

**4. Routes**

```php
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index']);      // Invoice::all() is scoped
    Route::delete('/invoices/{invoice}', ...)->middleware('tenant.role:admin');  // {invoice} is bound after the tenant is resolved
});
```

The tenant comes from `X-Tenant-Id` (internal id, `ten_…` public id, global object id or slug), a `{tenant}` route parameter, the session or a subdomain, in the configured order. Unknown tenant: 404. Suspended tenant: 403. No membership: 403 plus an audit event. The tenant middleware registers itself in Laravel's middleware priority list right before `SubstituteBindings`, so implicit route model binding of tenant-owned models works inside the `web` and `api` groups whatever order the route declares.

**5. Audit**

```php
use Efnext\Security\Audit\Facades\Audit;

Audit::action('domain.verified')->on($domain)->with(['method' => 'dns'])->record();
Audit::action('auth.login_failed')->on('user', $email)->denied()->record();
```

Actor, tenant, IP, user agent, request id and correlation id are filled in automatically and are part of the hash. Run `php artisan audit:verify` and `php artisan audit:anchor` from the scheduler.

```php
// Business change and audit record commit together; conflicts on the tenant's
// chain (deadlocks, serialization failures, MariaDB snapshot-isolation errors)
// are retried with back-off. Prefer this over DB::transaction() whenever a
// transaction contains an audit write.
Audit::transaction(function () use ($invoice) {
    $invoice->markPaid();
    Audit::action('invoice.paid')->on($invoice)->record();
});
```

The closure may run more than once: keep e-mails, API calls and other side effects outside of it (queue them after it returns, or make them idempotent).

**6. Operations**

```bash
php artisan security:doctor            # after every deployment and from monitoring (--json; --strict = deployment gate of the high-security profile)
php artisan audit:anchor               # scheduler, every few minutes
php artisan audit:verify               # scheduler, at least daily
```

In `APP_ENV=production` the audit provider refuses to boot without a signing key, on SQLite, with append-only enforcement disabled or without a readable anchor store (`AUDIT_FAIL_FAST=false` is the deliberate, temporary exception). Set `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` once your object-locked bucket is in place: production then also refuses to boot with database-only anchors, and the doctor reports them as a failure instead of a warning.

**7. Jobs and commands**

```php
ProcessInvoices::dispatch();                        // tenant travels with the job automatically
Tenancy::run($tenantId, fn () => Invoice::count()); // explicit context in code
```

```bash
php artisan tenant:run acme reports:generate        # explicit context on the CLI
```

## Where next

| You want to … | Read |
| --- | --- |
| understand what the framework does and does not promise | [What it does not do](docs/en/what-it-does-not-do.md), [Threat model](docs/en/threat-model.md), [Concepts](docs/en/concepts.md) |
| integrate it into an application | [Getting started](docs/en/getting-started.md) (a complete first integration with sample outputs), then the guides: [Tenancy](docs/en/guides/tenancy.md), [Addressing](docs/en/guides/addressing.md), [Auditing](docs/en/guides/auditing.md), [Jobs and commands](docs/en/guides/jobs-and-commands.md), [Sanctum tokens](docs/en/guides/sanctum-tokens.md); the [Integration checklist](docs/en/integration-checklist.md) before go-live |
| see it all in one working application | [examples/laravel-app](examples/laravel-app/README.md) — a stock Laravel 12 app plus fifteen files, installed and tested in CI on every push |
| look something up | [Configuration](docs/en/reference/configuration.md) (every key and environment variable), [Artisan commands](docs/en/reference/artisan-commands.md), [Events](docs/en/reference/events.md), [Exceptions](docs/en/reference/exceptions.md), [Public API](docs/en/reference/api.md), [Compatibility](docs/en/reference/compatibility.md) |
| run it in production | [Production guide](docs/en/guides/production.md) (secrets, database, anchors on object storage, the deployment gate), [Operations](docs/en/operations.md) and its runbooks: [Monitoring](docs/en/operations/monitoring.md), [Key rotation](docs/en/operations/key-rotation.md), [Incident response](docs/en/operations/incident-response.md); `.env.example` lists every variable |
| diagnose a problem | `php artisan security:doctor` (`--json`, `--strict`), then [Troubleshooting](docs/en/guides/troubleshooting.md) by symptom |
| know how it works inside | [Architecture](docs/en/architecture.md) and the diagrams: [System overview](docs/en/diagrams/system-overview.md), [Tenant request lifecycle](docs/en/diagrams/tenant-request-lifecycle.md), [Audit write path and verification](docs/en/diagrams/audit-write-and-verification.md), [Trust boundaries](docs/en/diagrams/trust-boundaries.md), [Data model](docs/en/diagrams/data-model.md) |
| contribute or cut a release | [CONTRIBUTING.md](CONTRIBUTING.md), [Testing](docs/maintainers/testing.md), [Releasing](docs/maintainers/releasing.md), [SUPPORT.md](SUPPORT.md) |
| let an AI agent integrate it | [AGENTS.md](AGENTS.md) — the contract an agent has to keep, and how it verifies its own work; [llms.txt](llms.txt) indexes the documentation for agents |

Documentation is written in English first. German ([README.de.md](README.de.md), `docs/de/`) and Swedish ([README.sv.md](README.sv.md), `docs/sv/`) editions of the user and operator pages follow each release; each translated page names the English source and the version it corresponds to.

## Guarantees and their conditions

| The framework guarantees … | … provided that |
| --- | --- |
| Eloquent queries on models using `BelongsToTenant` never return another tenant's rows and never run without a tenant. | You do not call `withoutGlobalScope(TenantScope::class)`, do not use `DB::table()` for those tables and do not run raw SQL. Grep for these in code review. |
| A record cannot be created for, moved to, updated in or deleted from a foreign tenant through Eloquent, even if the model instance was loaded in another context. | Same as above. Mass assignment of the tenant column is blocked unless you add it to `$fillable` yourself. |
| A request reaches your controller only if the user is an active, unexpired member of an existing, active tenant (and has the role you asked for). | The route carries `auth` and the `tenant` group (or `tenant.resolve` + `tenant.member`). |
| An audit record cannot be changed or deleted through the database without leaving a trace. | Triggers are installed (the migration refuses unsupported drivers), a signing key is configured, and `audit:anchor` writes to a store outside the database. Without the key, a database-level attacker can rewrite a chain consistently; without external anchors, they can truncate it. `security:doctor` checks all of this; `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` makes the external store a boot condition. |
| A child row can only reference a parent of the same tenant, whatever wrote it. | The child table uses `$table->tenantForeignId()` / `tenantForeignUuid()` / `tenantForeignUlid()` and the parent was created with `$table->tenantId()` (composite key). |
| Concurrent writers never fork a chain and do not deadlock on it. | The audit tables live on a database that honours `SELECT … FOR UPDATE` (PostgreSQL, MySQL/InnoDB, MariaDB). Business transactions that contain audit writes are wrapped in `Audit::transaction()` (retries concurrency conflicts with back-off) and, for brand-new tenants, `Audit::prepareChain()` ran once. |
| Public ids are unguessable. | You keep the default entropy (80 bits). They are still not an authorization mechanism. |

## Compatibility

| | Supported – hard CI gates | Experimental – installable, observed in CI, no support promise |
| --- | --- | --- |
| PHP | 8.3, 8.4 | 8.5 |
| Laravel | 12 | 13 |
| Databases | PostgreSQL 14–17, MySQL 8.0/8.4, MariaDB 10.11–11 (SQLite for tests only) | — |

The Composer constraints allow the experimental combinations so that they can
be tried; a combination becomes supported when its CI job has been a required
gate for a release. Only the latest minor release receives fixes
(`SECURITY.md`); the release process is in [docs/maintainers/releasing.md](docs/maintainers/releasing.md).

## Development

Everything runs in Docker; the host needs nothing but Docker Desktop:

```bash
./start-security-framework.sh          # composer audit + selfcheck + Pint + PHPStan + tests on sqlite, pgsql, mysql, mariadb
./start-security-framework.sh mutate   # mutation testing on the security core
```

The other modes (`test <db>`, `concurrency`, `matrix`, `load`, `shell`, `down`, `PHP_VERSION=`, `LARAVEL=`, `DEPS=`), the quality gates CI enforces and the rules for pull requests are in [CONTRIBUTING.md](CONTRIBUTING.md) and [docs/maintainers/testing.md](docs/maintainers/testing.md).

### Using it from a Laravel Sail product

The framework repository is not a Laravel application, so it ships its own
`compose.yaml` instead of Sail; the workflow is the same (nothing but Docker on
the host). To develop against it from a Sail-based product, add a path
repository and require the dev version:

```json
{
    "repositories": [
        {"type": "path", "url": "../security-framework", "options": {"symlink": true}}
    ]
}
```

```bash
sail composer require efnext/security-framework:@dev
sail artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config
sail artisan migrate
sail artisan audit:key:generate
```

Mount the framework directory into the Sail container (`volumes:` in your
product's `compose.yaml`) so the symlink resolves inside Docker. Sail's MySQL
service needs `--log-bin-trust-function-creators=1` (or a root user) for the
audit triggers; see [docs/en/operations.md](docs/en/operations.md).

## Documentation

- [Getting started](docs/en/getting-started.md) · [Concepts](docs/en/concepts.md) · [What it does not do](docs/en/what-it-does-not-do.md)
- Guides: [Tenancy](docs/en/guides/tenancy.md) · [Addressing](docs/en/guides/addressing.md) · [Auditing](docs/en/guides/auditing.md) · [Jobs and commands](docs/en/guides/jobs-and-commands.md) · [Sanctum tokens](docs/en/guides/sanctum-tokens.md) · [Production](docs/en/guides/production.md) · [Troubleshooting](docs/en/guides/troubleshooting.md)
- Reference: [Configuration](docs/en/reference/configuration.md) · [Artisan commands](docs/en/reference/artisan-commands.md) · [Events](docs/en/reference/events.md) · [Exceptions](docs/en/reference/exceptions.md) · [Public API](docs/en/reference/api.md) · [Compatibility](docs/en/reference/compatibility.md)
- Operations: [Operations](docs/en/operations.md) · [Monitoring](docs/en/operations/monitoring.md) · [Key rotation](docs/en/operations/key-rotation.md) · [Incident response](docs/en/operations/incident-response.md)
- Design: [Architecture](docs/en/architecture.md) · [Threat model](docs/en/threat-model.md) · [Diagrams](docs/en/diagrams/system-overview.md) · [Integration checklist](docs/en/integration-checklist.md)
- [Example application](examples/laravel-app/README.md) · [AGENTS.md](AGENTS.md) · [llms.txt](llms.txt)
- [Contributing](CONTRIBUTING.md) · [Testing](docs/maintainers/testing.md) · [Releasing](docs/maintainers/releasing.md) · [Support](SUPPORT.md) · [Code of conduct](CODE_OF_CONDUCT.md)
- [Security policy](SECURITY.md) · [Changelog](CHANGELOG.md) · [Licence (MIT)](LICENSE)
