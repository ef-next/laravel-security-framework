# Exceptions

Two families. **Request errors** are `HttpException`s that Laravel renders as a
4xx response; the framework throws them from middleware and your application is
not expected to catch them. **Programming and configuration errors** are
`LogicException`/`InvalidArgumentException`/`RuntimeException`s that mean the
code or the deployment is wrong; catching them hides the problem — fix the
cause the message names. Every message says what is wrong and, where it makes
sense, what to do.

## Tenant — `Efnext\Security\Tenant\Exceptions` (all implement `TenantException`)

| Exception | HTTP | Thrown when | Message | Fix |
| --- | --- | --- | --- | --- |
| `TenantIdentifierMissing` | **400** | `tenant.resolve` finds no identifier (header, route parameter, session, subdomain) | `No tenant identifier found in the request (checked: …)` | client sends the identifier; or `tenant.resolve:optional` for routes that may run without a tenant |
| `TenantNotFound` | **404** | the identifier matches no tenant | `Tenant not found.` (identifier kept out of the body) | — |
| `TenantSuspended` | **403** | the tenant's status is not `active` (an unknown status counts as suspended) | `Tenant is suspended.` / `Tenant is archived.` | reactivate the tenant |
| `TenantAccessDenied` | **403** | `tenant.member`, `tenant.role`, `tenant.token` or a session switch deny access; `reason()` returns the `DenialReason` | `You are not a member of this tenant.`, `Your membership … is not active.`, `… has expired.`, `Your role … does not permit this action.`, `This API token is not bound to a tenant.`, `… is not valid for this tenant.` | grant the membership/role, renew the token |
| `TenantContextMissing` | 500 | a tenant-owned model is queried, created, updated or deleted without an active tenant; `Tenancy::require()` without context | `No tenant context: cannot query <Model> without an active tenant.` + hint | add the `tenant` middleware, wrap the code in `Tenancy::run()`, or declare a cross-tenant operation with `Tenancy::withoutTenant('reason', …)`. **Never catch it.** |
| `TenantMismatch` | 500 | a model loaded or created for one tenant is written while another tenant is active | `Refusing to create/update/delete <Model> owned by tenant "…" while tenant "…" is active.` | the code mixes contexts; use `Tenancy::run()` per tenant |
| `TenantColumnImmutable` | 500 | the tenant column of a persisted record is changed (model or query builder) | `<Model>: tenant_id of a persisted record must not change. Moving data between tenants is not supported.` | re-create the record in the target tenant |
| `InvalidTenantId` | 500 | `TenantId::fromString()` with a malformed value | `Invalid tenant id "…": expected 1-191 printable characters …` | use `TenantId::tryFromString()` for untrusted input |

Also thrown by the tenant package: `Illuminate\Auth\AuthenticationException`
(401) from `tenant.member` for unauthenticated requests; `InvalidArgumentException`
for `withoutTenant('')`, an unknown role in `grantTenantMembership()`, an empty
role list, or a bad `tenant.resolution.chain` entry; `LogicException` for
`nullOnDelete()` on a composite tenant key, a custom Eloquent builder that does
not use `GuardsTenantColumn`, `TenantContextMiddleware` on a job that is not
`TenantAware`, and membership helpers on an unsaved user.

## Audit — `Efnext\Security\Audit\Exceptions` (all implement `AuditException`)

None is an `HttpException`; uncaught in HTTP they surface as 500.

| Exception | Thrown when | Message | Fix |
| --- | --- | --- | --- |
| `InvalidAuditEntry` | action, resource type/id or metadata do not validate; `->on()` missing; no tenant under policy `required` | `Invalid audit entry: …` / `… a tenant is required (tenant policy "required") and none was given or active.` | fix the call; `->global()` or `->forTenant()` where the policy allows |
| `AuditEventImmutable` | `AuditEvent` is updated or deleted through Eloquent | `Audit records are append-only; they can neither be updated nor deleted.` | — (by design) |
| `InvalidSigningKey` | `AUDIT_SIGNING_KEYS` / `AUDIT_SIGNING_ACTIVE_KEY` malformed, key < 32 bytes, active key not in the ring | `Invalid audit signing key configuration: … Expected AUDIT_SIGNING_KEYS="k1:base64:<key>,…"` | `php artisan audit:key:generate` |
| `InsecureConfiguration` | boot in `APP_ENV=production` with `AUDIT_FAIL_FAST` on and a configuration that removes a guarantee (no key, SQLite, append-only off, no readable anchor store, external anchor required but missing) | `Refusing to boot the audit log in production with an insecure configuration:` + one line per problem | configure what the lines name; `AUDIT_FAIL_FAST=false` only as a deliberate, temporary exception |
| `UnsupportedDatabaseDriver` | the migration or the doctor meets a driver without append-only triggers | `Append-only triggers are not implemented for the "<driver>" driver (supported: pgsql, mysql, mariadb, sqlite).` | use a supported database |
| `CanonicalizationFailed` | metadata cannot be canonicalised (objects, resources, NaN/Inf) | `Audit record cannot be canonicalised: …` | pass scalars, arrays, DateTimes, Stringables |

Also: `InvalidArgumentException` for an unknown `AUDIT_ANCHOR_STORES` entry;
`RuntimeException` if a chain head row disappears mid-write.

## Addressing — `Efnext\Security\Addressing\Exceptions` (extend `AddressingException`)

Route binding never throws for a malformed id: `resolveRouteBinding()` returns
`null`, which is a 404.

| Exception | Thrown when | Fix |
| --- | --- | --- |
| `InvalidPublicId` | `PublicId::of()/fromString()` with a bad prefix or body; a pre-set id with the wrong prefix on `creating`; entropy below 8 bytes | prefix `[a-z][a-z0-9]{1,11}`, body `[a-z0-9]{8,64}`; use `tryFromString()` for input; keep `entropy_bytes ≥ 8` |
| `InvalidGlobalObjectId` | `GlobalObjectId::of()/fromString()` with malformed segments | `<service>:<type>:<public id>`, segments `[a-z][a-z0-9-]{0,62}`; `tryFromString()` for input |
| `MissingPublicId` | `publicId()` on a model that has not been created yet | create the model first |
| `PublicIdImmutable` | the public id column of a persisted model is changed | — (by design) |
| `PublicIdGenerationFailed` | no unique id after `max_attempts` (practically unreachable at 80 bits) | check entropy setting and the system random source |

Also: `InvalidArgumentException('Unknown public id encoding …')` for a bad
`addressing.public_ids.encoding`.
