# Integration checklist

Work through this before the first deployment and again before each release
that touches tenancy or auditing.

## Setup

- [ ] `composer require "efnext/security-framework:^1.0@beta"` (pre-release: stability flag required, see the README); the three providers are auto-discovered.
- [ ] Config published and reviewed: `tenant.php`, `audit.php`, `addressing.php`.
- [ ] `SECURITY_SERVICE_NAME` set to this service's name (global object ids).
- [ ] `tenant.memberships.user_key_type` matches your users table primary key.
- [ ] Migrations run on every environment; the audit migration succeeded (it aborts on unsupported drivers or missing trigger privileges).
- [ ] `php artisan audit:key:generate` output stored in the secret manager; `AUDIT_SIGNING_KEYS` and `AUDIT_SIGNING_ACTIVE_KEY` present in every environment.
- [ ] `audit.anchoring.stores` includes a readable store outside the database (`filesystem` on an object-locked bucket), listed first; `log` is a useful addition but write-only.
- [ ] `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` in production once that store exists, so a deployment without it cannot boot.
- [ ] `php artisan security:doctor --strict` passes on every environment that is meant to be production-like, and the deployment pipeline runs it after every rollout and stops on exit 1 (strict = high-security profile: warnings are failures and `AUDIT_REQUIRE_EXTERNAL_ANCHOR` must be on).

## Models

- [ ] Every tenant-owned model uses `BelongsToTenant`; its table was created with `$table->tenantId()`.
- [ ] Relations between tenant-owned tables use `$table->tenantForeignId()` / `tenantForeignUuid()` / `tenantForeignUlid()` (composite foreign keys), not plain `foreignId()`.
- [ ] Optional relations are `tenantForeignId(..., nullable: true)` with `cascadeOnDelete()`/`restrictOnDelete()`; no `nullOnDelete()` on a composite tenant key (refused at migration time).
- [ ] Every model that is deliberately global is listed in a comment or an architecture test.
- [ ] No tenant-owned model lists the tenant column in `$fillable`.
- [ ] Models exposed in URLs use `HasPublicId` and `$table->publicId()`.
- [ ] The user model uses `HasTenantMemberships`.

## Routes

- [ ] Every route that reads or writes tenant data is inside `Route::middleware(['auth…', 'tenant'])`.
- [ ] Privileged routes add `tenant.role:<roles>`.
- [ ] API routes served with Sanctum tokens add `tenant.token` (and consider `tenant.tokens.require_binding=true`).
- [ ] Tenant picker / switcher uses `SessionTenant::switchTo()`; nothing else writes the session key.
- [ ] Health checks, webhooks and other tenant-less routes do not touch tenant-owned models (they would get `TenantContextMissing`).

## Background work

- [ ] Jobs that must run for a fixed tenant implement `TenantAware` and use `TenantContextMiddleware`.
- [ ] Scheduled commands that touch tenant data run via `tenant:run <tenant> <command>` or wrap their logic in `Tenancy::run()`.
- [ ] Cross-tenant maintenance uses `Tenancy::withoutTenant('reason', …)` with a meaningful reason; the block is as small as possible.
- [ ] Octane: nothing else registers a long-lived tenant state; the providers clear the context on request termination.

## Auditing

- [ ] Security-relevant business actions call `Audit::action(…)->record()` (create/update/delete of important resources, exports, permission changes, logins and failures).
- [ ] Audit writes that must be atomic with the business change run inside `Audit::transaction()`; the closure contains no e-mails, API calls or other non-transactional side effects.
- [ ] Tenants are provisioned with `Audit::prepareChain($tenantId)` when their first audit events are written inside larger transactions under load.
- [ ] Metadata never contains raw request input; secrets are not passed in; personal data goes through pseudonymised keys or is referenced by public id.
- [ ] `audit:verify` scheduled (e.g. hourly), `audit:anchor` scheduled (e.g. every five minutes) and `security:doctor` scheduled or wired into monitoring; a failing run pages someone.
- [ ] `ChainViolationDetected` has a listener that alerts.
- [ ] Retention and erasure policy documented (see "What it does not do").

## Code review rules

- [ ] Grep for `withoutGlobalScope(` and `TenantScope::class`: each hit needs a justification.
- [ ] Grep for `DB::table(` and `DB::select(` against tenant-owned tables: each hit needs an explicit tenant filter.
- [ ] Grep for `withoutTenant(`: each hit has a specific reason string and a small closure.
- [ ] Grep for `->forceFill(` on tenant-owned models: none touch the tenant column.

## Tests you should own

- [ ] A request from a member of tenant B to a resource of tenant A returns 403/404 and produces an audit event.
- [ ] A command run without `tenant:run` fails with `TenantContextMissing` (yes, a failing test is the point).
- [ ] A tampered `actor_id` in `audit_events` is reported by `audit:verify`.
