# Troubleshooting

By symptom. Every framework message names what is wrong and, where it makes
sense, what to do; this page adds the context. When in doubt, run
`php artisan security:doctor` — its output is the first thing to include in an
issue.

## Requests

| Symptom | Likely cause | Diagnose | Fix |
| --- | --- | --- | --- |
| `400 No tenant identifier found in the request (checked: …)` | the client sends no `X-Tenant-Id` (or route parameter / session / subdomain, whichever the chain lists) | the message lists the sources that were checked | send the identifier; for routes that may run without a tenant use `tenant.resolve:optional` |
| `404 Tenant not found.` | unknown identifier, or a valid one in a shape the lookup does not accept, or a slug column that is not `slug` | `php artisan tinker` → `app(TenantLocator::class)->locate('acme')` | check `tenant.lookup.by` and `slug_column`; the identifier must be UUID, `ten_…`, `<service>:tenant:ten_…` or a slug |
| `403 Tenant is suspended.` / `archived` | tenant status not `active` — or the status column holds a value the framework does not know (fail closed → suspended) | look at the row | set `status = active`; check `tenant.lookup.status_column` |
| `403 You are not a member of this tenant.` | correct behaviour for non-members; also for a membership with the wrong `user_id` type | `$user->tenantMembership($tenantId)` | grant the membership; check `TENANT_USER_KEY_TYPE` matched `users.id` at migration time |
| `403 Your membership … has expired / is not active` | `expires_at` in the past or status `invited`/`suspended` | as above | renew or activate |
| `403 Your role … does not permit this action.` | `tenant.role:x` with a role that is not `x` or higher | `hasTenantRole()` | grant the role; check `memberships.hierarchical` |
| `403 This API token is not valid for this tenant.` | token bound to another tenant (`tenant:<uuid>` ability) | decode the token's abilities | issue a token for this tenant; the ability needs the UUID, not the slug |
| `403 This API token is not bound to a tenant.` | `TENANT_TOKENS_REQUIRE_BINDING=true` and an unbound token | — | issue bound tokens; see the audit log for who still uses unbound ones |
| `401` on a tenant route | unauthenticated, or `auth` missing before `tenant` | — | `['auth:sanctum', 'tenant']` |
| `404` for an id that exists | malformed public id (no query is made), wrong prefix, or the record belongs to another tenant (correct) | `PublicId::tryFromString($value)` | use the id from the API response; check the active tenant |
| `500 TenantContextMissing` in a controller | `tenant.resolve` is missing, or a custom middleware order puts route model binding before it | `security:doctor` → `middleware_priority`; the route's middleware list | use the `tenant` group; do not reorder Laravel's middleware priority by hand |

## Code and tests

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `TenantContextMissing: cannot query <Model> without an active tenant` in a job, command or test | code path without a tenant | `Tenancy::run()`, a `TenantAware` job, `tenant:run`, or a justified `Tenancy::withoutTenant('reason', …)` — never catch it |
| `TenantMismatch: Refusing to update <Model> owned by tenant A while tenant B is active` | a model loaded in one context and written in another (queued model, cached instance, test helper) | keep the unit of work inside one `Tenancy::run()` |
| `TenantColumnImmutable` | code tries to move a record between tenants, or an `update()` touches `tenant_id` | re-create in the target tenant; drop the column from the update |
| `LogicException: … does not guard the tenant column` | a custom Eloquent builder on a `BelongsToTenant` model | `use GuardsTenantColumn` in the builder |
| `LogicException: A composite tenant foreign key cannot use ON DELETE SET NULL` | `nullOnDelete()` on `tenantForeign*()` | `nullable: true` with `cascadeOnDelete()`/`restrictOnDelete()`; detach by update |
| `MissingPublicId: <Model> has no public_id yet` | `publicId()` before `save()` | create first |
| `InvalidPublicId … with the wrong prefix` | a pre-set public id from another model type | let the framework assign it |
| `InvalidAuditEntry: a tenant is required (tenant policy "required")` | audit write outside a tenant context | `->global()` for genuinely tenant-less events, `->forTenant()`, or run inside a context |
| `InvalidAuditEntry: call ->on(...)` | builder used without a resource | `->on($model)` or `->on('type', 'id')` |
| `CanonicalizationFailed` | objects/resources/NaN in metadata | pass scalars, arrays, DateTimes |
| `AuditEventImmutable` | code tries to update or delete an `AuditEvent` | — (by design) |
| deadlocks / `could not serialize access` / MariaDB error 1020 around audit writes | business transaction with `DB::transaction()` | `Audit::transaction()`; `Audit::prepareChain()` for new tenants |

## Migrations and database

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `SQLSTATE[HY000]: … Access denied; you need (at least one of) the SUPER privilege(s) … TRIGGER` (MySQL/MariaDB) | binary logging on, migration user without `SUPER` | `SET GLOBAL log_bin_trust_function_creators = 1` or run the migration as a privileged user |
| `UnsupportedDatabaseDriver: Append-only triggers are not implemented for the "…" driver` | SQL Server, Oracle, … | use PostgreSQL, MySQL or MariaDB |
| `Foreign key constraint is incorrectly formed` (errno 150) | a composite tenant key with `SET NULL`, or the parent table lacks `UNIQUE (tenant_id, id)` (created with a plain `foreignUuid` instead of `tenantId()`) | see above; create parents with `$table->tenantId()` |
| `Duplicate entry` on `number` across tenants | unique index without the tenant column | `$table->unique(['tenant_id', 'number'])` |
| `NOT NULL constraint failed: invoices.public_id` on bulk insert | `insert()` bypasses Eloquent events | create through the model or generate ids with `PublicIdGenerator` |
| `tenant_memberships.user_id` type mismatch / foreign key to users fails | `TENANT_USER_KEY_TYPE` did not match `users.id` when the migration ran | set it and re-run the membership migration on a fresh schema |

## Boot and configuration

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `InsecureConfiguration: Refusing to boot the audit log in production …` | `APP_ENV=production` with no key / SQLite / append-only off / no readable anchor store / external anchor required but missing | configure what the listed lines name; **do not** set `AUDIT_FAIL_FAST=false` to get past it |
| `InvalidSigningKey` | malformed `AUDIT_SIGNING_KEYS`, key shorter than 32 bytes, active key not in the list | `php artisan audit:key:generate`; format `k1:base64:<key>` |
| `security:doctor --strict` fails `anchor_stores` although anchors work | `AUDIT_REQUIRE_EXTERNAL_ANCHOR` is off — strict requires the profile to be *enforced*, not just configured | set it to `true` |
| doctor: `Only the database store is readable` | `AUDIT_ANCHOR_STORES` lacks `filesystem` (setting `AUDIT_ANCHOR_DISK` alone does nothing) | `AUDIT_ANCHOR_STORES=filesystem,database` |
| doctor: `External store "filesystem" is not readable` | disk misconfigured, credentials wrong, bucket policy denies `ListBucket`/`GetObject` | test the disk with `Storage::disk('audit-anchors')->put('probe', '1')` |
| doctor: `anchor_freshness` FAIL | scheduler not running, or `max_anchor_age_minutes` below the anchor interval | check `schedule:run`; adjust the threshold |
| doctor: `verification_recency` WARN | `audit:verify` not scheduled or failing | schedule it; read its output |
| doctor: `Store "array" … negative cache ineffective` (WARN) | tenant cache on the array driver in production | `TENANT_CACHE_STORE=redis` |
| `InvalidArgumentException: audit.anchoring.stores entry "…" is neither database, filesystem, log nor a class` | typo in `AUDIT_ANCHOR_STORES` | fix the name; a custom store is given as a fully qualified class name |
| `InvalidArgumentException: tenant.resolution.chain must contain at least one resolver` | chain emptied in the published config | list at least `header` |

## Verification failures

`audit:verify` exits 1 and names each violation with a reason and a sequence:

| Reason | Meaning | Where to look |
| --- | --- | --- |
| `event_hash_mismatch` | the row's content differs from what was hashed | that row was changed |
| `previous_hash_mismatch` / `missing_predecessor` / `sequence_gap` | a row was removed or replaced before this one | the rows between the last good and this sequence |
| `signature_invalid` / `signature_missing` / `signature_key_unknown` | rewritten without the key, or a key was removed from the list | key list and rotation history |
| `head_mismatch` / `anchor_ahead` | the chain was truncated after the head or anchor was written | compare with the external anchors |
| `anchor_mismatch` | rewritten consistently (with the key) but before the last anchor | the anchor is the trust boundary; start incident response |
| `unsupported_hash_version` | an event claims a hash version this framework does not know | a downgrade, or a manipulated row |

Then: [operations/incident-response.md](../operations/incident-response.md).

## Getting help

- `php artisan security:doctor --json` and the framework version
  (`composer show efnext/security-framework`) in every report.
- Vulnerabilities: privately, see `SECURITY.md` — not in an issue.
