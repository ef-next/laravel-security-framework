# Concepts

The ideas behind the three packages, in the order you meet them. Each section
ends with what the concept does *not* do; the full list is in
[What it does not do](what-it-does-not-do.md).

## Fail closed

The framework's one design rule: when a security precondition is missing, the
operation is refused, never degraded. A query on a tenant-owned model without
a tenant throws instead of returning everything; production refuses to boot
without a signing key instead of writing unsigned events; a malformed public
id is a 404 before any database access; a composite foreign key that could not
be portable is refused when the migration is written, not when it runs on
another database.

The consequence for you: the failures are loud and early. A `TenantContextMissing`
in a test is not something to catch — it points at a code path that would run
without a tenant in production.

## Tenant context

A **tenant context** is the tenant that the current unit of work — HTTP
request, queued job, console command, closure — acts for. It is established
exactly once per unit of work and cleared afterwards:

- by the `tenant.resolve` middleware from an *identifier* in the request (header
  `X-Tenant-Id`, route parameter, session, subdomain — in the configured order);
- by the queue integration, from the payload of a job that was dispatched inside
  a context;
- by `Tenancy::run($tenant, fn () => …)` or `php artisan tenant:run`, explicitly.

An identifier is not a proof. Resolution only answers "which tenant is this
request about"; `tenant.member` then answers "may this user act in it" (active,
unexpired membership; a role if required). Both happen before route model
binding, so `{invoice}` in a URL is resolved *inside* the tenant.

Tenant-owned models use `BelongsToTenant`. A global scope filters every query
by the active tenant; without a context the scope throws. Creating stamps the
tenant column; updating and deleting check that the record belongs to the
active tenant, in PHP and again in the SQL `WHERE`, so `saveQuietly()` or a
removed event listener change nothing. Moving a record to another tenant is
refused.

Cross-tenant work is possible but explicit: `Tenancy::withoutTenant('nightly
aggregation', fn () => …)` lifts the scope inside the closure, dispatches an
event with the reason and the calling code location, and the audit package
records it. Inside such a block, `Model::forTenant($id)` keeps queries
explicit.

*Does not:* scope `DB::table()`, raw SQL, or models without the trait; decide
what a member may do *inside* a tenant — that is your policy layer.

## Composite foreign keys

`$table->tenantId()` adds `UNIQUE (tenant_id, id)` to a tenant-owned table;
`$table->tenantForeignId('invoice_id', 'invoices')` on a child table creates a
foreign key on `(tenant_id, invoice_id)` referencing `(tenant_id, id)`. The
database itself then refuses a line that points at another tenant's invoice —
whatever wrote it. This is the one guard that holds below Eloquent.

*Does not:* stop cross-tenant *reads* through raw SQL; allow `ON DELETE SET
NULL` (a composite key cannot clear one column; use `nullable: true` with
cascade or restrict and detach by update).

## Public and global ids

Internal auto-increment ids leak volume and invite enumeration. `HasPublicId`
gives a model an unguessable, readable id with a type prefix
(`inv_7f3k9zq2m4x8c1bt`, 80 bits of randomness), assigned on create, immutable,
used automatically by route model binding. `HasGlobalObjectId` extends it to a
name that is unique across services: `billing:invoice:inv_7f3k9zq2m4x8c1bt` —
the form other services, audit trails and support tickets should use.

*Does not:* authorize anything. Knowing an id is not permission; the tenant
middleware and your policies still decide.

## Tamper-evident audit log

Every audit event belongs to a **chain** — one per tenant plus a `global`
chain — and carries a sequence number, the hash of its predecessor, its own
hash over a canonical JSON encoding of everything that describes it (actor,
action, resource, outcome, metadata, request context, timestamp), and an HMAC
signature of that hash with a key that lives outside the database.

Three layers make changes visible:

1. **Database triggers** refuse `UPDATE` and `DELETE` on events and anchors. A
   superuser can drop them — which is why they are not the only layer.
2. **Hash chain and signature.** Rewriting one event breaks the hash of every
   later event; recomputing all hashes is possible for someone with database
   access, but the signatures need the key. `audit:verify` walks the chains and
   reports every inconsistency.
3. **Anchors.** `audit:anchor` writes the current head of every chain (sequence
   and hash) to an anchor store. A store *outside* the database — object
   storage with Object Lock, a WORM log — is what makes wholesale rewrites and
   truncation detectable: the verifier compares the chain against the anchor.

The verifier and `security:doctor` are how you *find out*. Wire
`ChainViolationDetected` to your alerting.

*Does not:* prevent tampering (it makes it detectable); sign or anchor without
the scheduler; keep metadata private (it is sanitised, not encrypted); solve
erasure (events are immutable — keep personal data out of them or pseudonymise).

## Actors, metadata, sanitising

The actor is the authenticated user (or the model's own `toAuditActor()`),
`anonymous` for unauthenticated requests, `system('console')` outside HTTP,
or whatever `->by()` says. Metadata is whatever you pass to `->with()` — after
the sanitiser has redacted secret-looking keys and values (`password`, `token`,
JWT shapes, card numbers…), pseudonymised identifiers (`email` →
`email_hash`) and bounded depth, size and string length. Pass the fields the
event is about, never `$request->all()`.

## Guarantee → condition → residual risk → diagnosis

Every promise in this documentation has that shape, because a security
property that holds "usually" is not a property. The README's table of
guarantees names the condition for each; `security:doctor` checks the
conditions it can check; the threat model names what remains. When you extend
the framework, keep the shape.

## Glossary

| Term | Meaning |
| --- | --- |
| tenant context | the tenant the current unit of work acts for |
| resolver | finds a tenant *identifier* in a request |
| locator | maps an identifier to a tenant record (id, status, public id, slug) |
| membership | a user's relation to a tenant: role, status, expiry |
| chain | the sequence of audit events of one tenant (or `global`) |
| head | the last sequence and hash of a chain, kept in `audit_chain_heads` |
| anchor | a copy of a head, signed, stored (ideally) outside the database |
| verification run | a recorded pass of `audit:verify` or the doctor over a chain |
| public id | `prefix_body`, unguessable per-model identifier |
| global object id | `service:type:public_id`, unique across services |
| strict profile | `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` enforced at boot, `security:doctor --strict` as the deployment gate |
