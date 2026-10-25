# What the framework does not do

This is the most important page in the documentation. Every item here is a
deliberate boundary, not a bug. Your application has to cover it.

## Tenant isolation

- **It does not filter the query builder.** `DB::table('invoices')`, `DB::select()`, raw SQL, and queries in database views or stored procedures are not touched. Only Eloquent models that use `BelongsToTenant` are scoped. The composite foreign keys from `$table->tenantForeignId()` are the one guard that holds below Eloquent: they stop cross-tenant *relations*, not cross-tenant *reads*.
- **It does not stop `withoutGlobalScope(TenantScope::class)`.** That is Eloquent's own escape hatch. Treat any occurrence as a code-review finding and use `Tenancy::withoutTenant('reason', …)` instead; the latter fires an event and is written to the audit log.
- **It does not report event-less writes it blocked.** A model loaded in another tenant's context and written with events enabled throws `TenantMismatch`. Without events (`saveQuietly()`, `withoutEvents()`) the same check runs from the save query and throws too, unless the tenant column was not selected; then the `UPDATE`/`DELETE` is constrained to the active tenant and simply affects no row, and Eloquent still reports success. Select the tenant column (it is included by default) if you need the exception.
- **It does not scope `fresh()`, `refresh()` or queue restoration of a model instance you already hold.** Those reload by primary key without the tenant filter; you only get back the record you already had. Writing it is guarded as above.
- **It does not scope upsert conflict detection.** `upsert()` stamps the active tenant on the rows it inserts and never updates the tenant column on conflict, but the database matches conflicts on any unique key. Make unique indexes on tenant-owned tables tenant-qualified (`unique(['tenant_id', 'number'])`) and pass a tenant-qualified `$uniqueBy`. `insertUsing()` (sub-query inserts) is not guarded at all.
- **It does not know which models are tenant-owned.** A model without the trait is global. Add the trait, or add the architecture test from `tests/Arch` to your own suite to list global models explicitly.
- **It does not scope relationships to global models.** If `Invoice` (scoped) belongs to `Currency` (global), the currency is visible to everyone, as intended. If `Invoice` belongs to `Customer` and `Customer` lacks the trait, that is a bug in your model, not something the framework can see.
- **It does not authorize actions inside a tenant.** Membership and roles answer "may this user act for this tenant at all?". Whether a member may delete this particular invoice is your policy's job (Gates, Policies).
- **It does not bind sessions to tenants by itself.** The session resolver only reads a value that `SessionTenant::switchTo()` wrote after a membership check. If you write the session key yourself, you bypass that check.
- **It does not protect against a compromised application server.** Code running inside your process can call `Tenancy::withoutTenant()`; the framework makes that visible, not impossible.
- **It does not move data between tenants.** The tenant column is immutable. Migrations between tenants are an explicit, audited operation you write yourself with `Tenancy::withoutTenant()` and raw inserts.

## Audit log

- **It does not prevent tampering; it makes tampering detectable.** Database triggers stop casual `UPDATE`/`DELETE`. A database superuser can drop them. The hash chain, the HMAC signature and the external anchors exist so that this leaves evidence when `audit:verify` runs. Run it.
- **It is only as tamper-evident as the key and the anchors are separate from the database.** If `AUDIT_SIGNING_KEYS` lives in the same place as the database credentials, or anchors are only stored in the database, an attacker with both can rewrite history. Put the key in a secret manager and anchor to object storage with object lock or to an external log sink.
- **It does not sign or anchor automatically.** Signing happens on every write once a key is configured; anchoring happens when `php artisan audit:anchor` runs. Schedule it.
- **It does not record anything you do not tell it to.** Except for the tenant package's security events (denials, bypasses, membership changes, switches), every business event is an explicit `Audit::action(…)->record()` call. Model observers are your choice.
- **It does not make the audit write atomic with your business write by itself.** On the same connection, wrap both in one `Audit::transaction(fn () => …)` when the audit record must exist if and only if the business change does — it is `DB::transaction()` plus the retry and back-off rules the chain needs, so use it and not the bare Laravel call; outside a transaction the audit record commits on its own. With `AUDIT_DB_CONNECTION` pointing at a separate connection there is no shared transaction at all, so a crash between the two writes can leave one without the other.
- **It does not guarantee metadata privacy.** The sanitizer redacts common secret keys and secret-shaped values and pseudonymises identifiers. It cannot recognise every secret. Do not pass `$request->all()`.
- **It does not solve GDPR erasure.** Metadata is immutable. Personal data belongs in the pseudonymised fields (keyed hash) or outside the log, referenced by public id. Deleting a person then means deleting the referenced record, not the audit event. Rotating `AUDIT_PSEUDONYM_KEY` after a retention period is the closest thing to crypto-shredding the design offers; plan retention before you go live.
- **It does not store the previous state of a resource.** Put the diff into metadata if you need it.
- **It does not verify chains on every write.** Verification is a scheduled, chunked pass (`audit:verify`); `security:doctor` verifies only the tail of each chain on the spot.
- **It does not make your transactions idempotent.** `Audit::transaction()` re-runs the whole closure on concurrency conflicts. Side effects that are not part of the database transaction (mail, HTTP calls, queue pushes outside the transaction) would repeat; keep them out of the closure.
- **It does not replace monitoring.** `security:doctor` tells you whether the installation still holds the guarantees; somebody has to run it and read the result. A missing signing key or a dropped trigger is silent until then, except at boot in production (fail-fast).

## Public identifiers

- **They are not authorization.** An unguessable id stops enumeration. It does not stop someone who obtained the id from a log, a referrer header, a support ticket or an export. Every route still needs the tenant scope and your policies.
- **They are not assigned on bulk inserts.** `Model::insert()` and `upsert()` bypass Eloquent events. Generate ids with `PublicIdGenerator` first.
- **They are not unique without an index.** `generateUnique()` is check-then-insert. `$table->publicId()` adds the unique index; do not remove it.

## Operational

- **No rate limiting.** 404s for unknown tenant identifiers and 403s for denied memberships are cheap, but a scanner can still generate them. Rate-limit at the edge.
- **No multi-database tenancy.** One database, one schema, a tenant column. Database-per-tenant is a different product.
- **No tenant provisioning UI, invitations, billing or SSO.** Memberships are a table and an API.
- **No support for MySQL/MariaDB without trigger privileges.** The migration needs `CREATE TRIGGER` (with binary logging: `SUPER` or `log_bin_trust_function_creators=1`). It fails loudly rather than migrating without protection.
