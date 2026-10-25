# Architecture

Three packages, one dependency direction: `addressing <- tenant <- audit`. The
architecture test in `tests/Arch` fails the build when that direction is violated
or when a package references host-application classes.

```
Efnext\Security\Addressing        identifiers, generation, Eloquent traits
Efnext\Security\Tenant            context, resolution, locating, membership, HTTP, queue, console
Efnext\Security\Audit             records, hashing, signing, sanitizing, writing, verification, anchoring
```

## Tenant: request lifecycle

```
request ─▶ ResolveTenant ──▶ TenantResolver (chain) ──▶ Candidate(identifier, source)
                │                                                │
                │                                                ▼
                │                                    TenantLocator (cache -> db)
                │                                                │
                │                        null ─▶ 404     inactive ─▶ 403
                │                                                │
                ▼                                                ▼
        TenantContextManager.activate(TenantContext) ◀───── TenantRecord
                │
                ▼
        EnsureTenantMember ──▶ MembershipChecker ──▶ Membership(status, role, expiry)
                │                        │
                │             denial ─▶ TenantAccessDenied event ─▶ 403 / 401
                ▼
        controller: Invoice::all()  ──▶ TenantScope reads the manager
                                            │
                                            ├─ context      ─▶ WHERE tenant_id = ?
                                            ├─ bypass block ─▶ no filter (evented)
                                            └─ nothing      ─▶ TenantContextMissing (LogicException)
                │
                ▼
        RequestHandled / Octane *Terminated ─▶ manager.clear()
```

Key decisions:

- **The scope throws instead of returning everything.** `TenantContextMissing` extends `LogicException`, is never rendered as a 4xx, and names the three legitimate fixes in its message.
- **Resolution and lookup are separate.** Resolvers only find a string. The locator turns it into an existing tenant record with a status. Malformed identifiers never reach a query (UUID/ULID check for `id`, `PublicId` parser for `public_id`, strict regex for `slug`).
- **The context is a value object with a `source`.** Forensics can tell a header-resolved tenant from a queue-propagated one.
- **`withoutTenant()` requires a reason** and fires `TenantScopeBypassed` with the caller's file:line; the audit package records it.
- **Queue propagation uses the job payload**, not job properties, so every job gets the dispatcher's tenant without code changes. `TenantAware` jobs may override it. The manager suspends and resumes state per job so the sync driver behaves like a real worker.
- **Cleanup on request boundaries** (`RequestHandled`, Octane's terminated events) and on job boundaries, so a long-running worker cannot leak a tenant into the next unit of work.

## Audit: write path

```
Audit::action('domain.verified')->on($domain)->with([...])->record()
   │
   ▼
AuditEntry (validated)  ─▶  tenant from entry or context (policy: required|nullable)
   │                        actor from entry or ActorResolver (user / anonymous / system)
   │                        request context (request id, correlation id, ip, user agent)
   │                        metadata -> MetadataSanitizer -> CanonicalJson::roundTrip
   ▼
INSERT OR IGNORE audit_chain_heads(chain_key)   ← auto-commit, only if the head is missing (see below)
transaction on the audit connection
   ├─ SELECT … FOR UPDATE audit_chain_heads WHERE chain_key = ?    ← serialises writers
   ├─ sequence = last_sequence + 1, previous_hash = last_hash
   ├─ event_hash = sha256("efnext-audit-v2\n" + canonical JSON of the whole record)
   ├─ signature  = hmac-sha256(event_hash, active key)              ← key never in the database
   ├─ INSERT audit_events (UNIQUE chain_key+sequence as backstop)
   └─ UPDATE audit_chain_heads SET last_sequence, last_hash
```

What is hashed (hash version 2): id, chain key, tenant id, sequence, previous hash, occurred_at in microseconds, actor type/id/label, action, resource type/id, outcome, metadata, request id, correlation id, ip, user agent. Not hashed: `recorded_at` (informational server time; ordering and the tamper-evidence rest on `sequence` and `occurred_at_us`), `hash_version`, `event_hash`, `key_id`, `signature`. A change to `recorded_at` is therefore not detected by the verifier; the append-only triggers are the only guard for that column.

Why `Audit::transaction()` exists: inside a caller's transaction the head lock is taken at the point of the audit write, after the caller's own reads. Under contention every database may then abort the transaction (InnoDB deadlock, PostgreSQL serialization failure, MariaDB ≥ 11.6 snapshot-isolation error 1020). `Audit::transaction()` retries the whole business transaction with jittered back-off on concurrency errors; it is the recommended wrapper for transactions that contain audit writes.

Why the head row is created outside the transaction: on InnoDB, `INSERT … IGNORE` followed by `SELECT … FOR UPDATE` on the same new row inside one transaction makes every concurrent first writer hold a shared lock and then request an exclusive one; InnoDB kills all but one (deadlock). With the row created in its own auto-commit statement, `FOR UPDATE` takes a plain record lock and writers queue. When `record()` runs inside a caller's transaction, the head insert necessarily joins it; call `Audit::prepareChain($tenantId)` when you create a tenant if your first audit events are written inside larger transactions under concurrency. Deadlocks that still occur are retried with jitter (`audit.write.attempts`) when the writer owns the transaction, and propagate to the caller's transaction otherwise.

Why a heads table: `MAX(sequence)+1` under concurrent transactions is a race on every isolation level below serializable. A locked row per chain is cheap, portable across PostgreSQL/MySQL/MariaDB, and gives the verifier a second opinion about where the chain should end.

Why sequence numbers instead of timestamps: two application servers with a few milliseconds of clock skew must not produce "tampering" alerts. Timestamps are recorded and hashed; they are not used for ordering.

## Audit: verification

`HashChainVerifier::verify(chain, from, to)` walks the chain in sequence order in chunks and reports:

| Check | Catches |
| --- | --- |
| contiguous sequence | deleted or injected rows |
| `previous_hash` linkage | reordered or replaced rows |
| recomputed `event_hash` | any change to a hashed column (all columns except `recorded_at`, `hash_version`, `key_id`, `signature`) |
| HMAC signature | consistent rewrites by someone without the key |
| chain head agreement | a truncated tail |
| latest anchor agreement | a truncated tail or rewrite performed together with the head |

`ChainViolationDetected` is dispatched for every chain with findings; `audit:verify` exits non-zero.

## Audit: anchoring

`audit:anchor` snapshots `(chain, sequence, hash, signature, time)` into one or more stores: `database` (convenient, not independent), `filesystem` (any Laravel disk; use object lock), `log` (write-only, for SIEMs). The verifier reads the latest anchor from the first store that has one; configure the external store first. A `log`-only configuration gives the verifier nothing to compare against: pair it with `filesystem` (or `database`) so anchors can be read back.

## Addressing

`PublicId` and `GlobalObjectId` are canonical-or-invalid value objects: lowercase only, no trimming, strict regexes. `HasPublicId` assigns on create, refuses changes, keeps the column out of mass assignment, and makes route model binding skip the database for malformed values. `$table->publicId()` creates the unique column.

## Operational self-checks

`security:doctor` (`Efnext\Security\Audit\Doctor\SecurityDoctor`) runs independent checks and reports OK/WARN/FAIL/SKIP: configuration (keys, driver, append-only), installed triggers (`AppendOnly::missingTriggers()`), anchor stores, anchor freshness and verification recency per chain (from `audit_verification_runs`, written by `audit:verify` and the doctor), a live tail verification, tenant middleware priority and cache store. `ProductionGuard` holds the configuration-only subset and is enforced at boot in `APP_ENV=production` (`InsecureConfiguration`).

## Schema helpers

`$table->tenantId()` adds the tenant column, its FK to `tenants`, an index and `UNIQUE(tenant_id, id)`. `$table->tenantForeignId('invoice_id', 'invoices')` (and the uuid/ulid variants) adds the column plus a composite foreign key `(tenant_id, invoice_id) → invoices(tenant_id, id)`, so the database itself refuses a relation across tenants — on PostgreSQL, MySQL, MariaDB and SQLite. The helpers return a `TenantForeignKeyDefinition` that accepts `cascadeOnDelete()`, `restrictOnDelete()` and `noActionOnDelete()` but throws on `nullOnDelete()`/`nullOnUpdate()` (and `SET DEFAULT`): a composite key cannot clear one of its columns, so `SET NULL` would have to clear the NOT NULL tenant column too — MySQL/MariaDB refuse to create such a constraint (errno 150), PostgreSQL/SQLite create it and then refuse the parent delete. An optional relation is `tenantForeignId('invoice_id', 'invoices', nullable: true)` with cascade or restrict; detaching is an update in application code.

## Configuration surface

Everything is in `config/tenant.php`, `config/audit.php` and `config/addressing.php`; every option is documented in place. Bindings are singletons resolved from config once per process; tests that change config re-resolve the affected binding explicitly.
