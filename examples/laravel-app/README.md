# Example: a billing API on the ef.next Security Framework

A stock Laravel 12 application plus the **fifteen files in this directory**:
two tenants' worth of customers and invoices behind a token-authenticated API,
with tenant isolation, public ids and a signed audit trail — and the tests
that prove each guarantee.

The directory contains only what differs from `laravel new`. `install.sh`
creates a fresh skeleton, copies these files over it, installs the framework
from this repository and runs everything; CI does the same on every push,
once on SQLite and once on PostgreSQL (`example` job in
`.github/workflows/gates.yml`). The tests run against the database you
choose — `.env.testing` only holds the SQLite defaults; `install.sh` passes
the five `DB_*` variables explicitly, because neither `phpunit.xml` nor
`.env.testing` overrides a variable that is already set in the environment.

```bash
# in the framework's Docker image (nothing but Docker on the host)
./start-security-framework.sh example            # application and tests on SQLite
./start-security-framework.sh example pgsql      # application and tests on PostgreSQL (real triggers)

# or with PHP 8.3+ and Composer on the host
examples/laravel-app/install.sh                  # → build/example-app
```

Afterwards, in `build/example-app`: `php artisan db:seed --class=DemoSeeder`
prints a token, two tenant ids and curl lines; `php artisan serve` serves the
API.

## What is in here

| File | Shows |
| --- | --- |
| `app/Models/User.php` | `HasTenantMemberships` (which tenants, which role) and Sanctum's `HasApiTokens` |
| `app/Models/Customer.php`, `app/Models/Invoice.php` | `BelongsToTenant` + `HasPublicId` + `HasGlobalObjectId`, `publicIdPrefix()`, `resourceType()`; `tenant_id` is **not** fillable |
| `database/migrations/…customers…`, `…invoices…` | `$table->tenantId()`, `$table->publicId()`, `$table->tenantForeignId('customer_id', 'customers')` — the composite foreign key — and per-tenant unique indexes |
| `routes/api.php` | `auth:sanctum` + `tenant` (+ `tenant.token`) group, `tenant.role:admin` on the state change, one route outside any tenant |
| `app/Http/Controllers/InvoiceController.php` | scoped queries with no tenant code in them, `wherePublicId()`, `Audit::transaction()` around change + record, a failed outcome, a job dispatched after the transaction |
| `app/Jobs/SendPaymentReceipt.php` | the tenant travels with the job; `SerializesModels` re-fetches the invoice inside the tenant |
| `bootstrap/app.php` | the skeleton's file with `routes/api.php` registered — nothing framework-specific |
| `routes/console.php` | the scheduler entries: `audit:anchor`, `audit:verify`, `security:doctor --no-verify` |
| `.env.testing` | the test environment: SQLite in memory, a signing key so the signed path is exercised, `SECURITY_SERVICE_NAME=billing` |
| `tests/Feature/TenantIsolationTest.php` | model layer: no tenant → exception, no leak, per-tenant uniqueness, composite FK refusal, cross-context update refusal, public ids |
| `tests/Feature/InvoiceApiTest.php` | HTTP: 400 / 404 / 403 / 404-for-foreign-record / role / token binding / 200 |
| `tests/Feature/AuditTrailTest.php` | the paid event is signed and verifiable, change and record commit together, `UPDATE`/`DELETE` on audit rows are refused, denials are audited |
| `database/seeders/DemoSeeder.php` | demo data and curl lines |

## What it deliberately does not show

- Authorization *inside* a tenant (may this member see this invoice?) — that
  is Laravel policies, unchanged by the framework.
- External anchors on object storage and the strict doctor profile — the
  production setup is in [docs/en/guides/production.md](../../docs/en/guides/production.md);
  the example runs with database anchors, which the doctor reports as a
  warning.
- Web routes and sessions; the session tenant resolver and `TenantSwitched`
  are documented in [docs/en/guides/tenancy.md](../../docs/en/guides/tenancy.md).

## Adapting it

Rename `Invoice` to your aggregate, keep the three traits and the two
migration macros, keep `Audit::transaction()` around every change that has an
audit record, keep the tests. The checklist an agent follows for the same job
is [AGENTS.md](../../AGENTS.md).
