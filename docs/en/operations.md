# Operations

The operator's reference for a running installation. The day-to-day
procedures have their own pages:

| Runbook | When you need it |
| --- | --- |
| [Monitoring](operations/monitoring.md) | what to watch, which signal means what, doctor JSON, metrics |
| [Key rotation](operations/key-rotation.md) | scheduled rotation, retiring a key, compromised key, `APP_KEY` |
| [Incident response](operations/incident-response.md) | a verifier violation or a doctor FAIL: preserve, scope, contain, record |

The [Production guide](guides/production.md) is the ordered path from a
working development setup to a hardened deployment; this page is the
reference you come back to.

## Doctor

```bash
php artisan security:doctor            # human-readable, exit 1 on FAIL
php artisan security:doctor --strict   # high-security profile / deployment gate: exit 1 on WARN as well, and AUDIT_REQUIRE_EXTERNAL_ANCHOR must be on
php artisan security:doctor --json     # monitoring
```

Checks: environment (debug, fail-fast), `APP_KEY`, signing key, database driver and reachability, append-only configuration **and installed triggers**, anchor stores (readable, external), separate audit connection, anchor freshness per chain (`audit.doctor.max_anchor_age_minutes`), last intact full verification per chain (`audit.doctor.max_verification_age_hours`), a live verification of the last `audit.doctor.tail_events` events of every chain, tenant middleware priority, tenant cache store. Run it after every deployment and from monitoring. `--strict` is the deployment gate of the high-security profile: warnings count as failures, and the `anchor_stores` check fails unless `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` is set — an external store that is merely configured is not enough, because nothing would stop a later deployment from dropping it again; the requirement has to be enforced by the boot guard. A pipeline that runs `security:doctor --strict` after each rollout therefore cannot ship a production without an enforced external anchor, a debug flag left on or a missing signing key. Without `--strict` the framework installs and runs with database-only anchors (a `WARN`), which is the deliberate default for a library.

`audit:verify` and the doctor record their runs in `audit_verification_runs` (chain, range, result, time). That table is bookkeeping for the recency check, not evidence.

In `APP_ENV=production` the provider itself refuses to boot when the configuration has no signing key, uses SQLite, disables append-only enforcement or has no readable anchor store (`InsecureConfiguration`). `AUDIT_FAIL_FAST=false` disables that guard for a deliberate, temporary exception; the doctor keeps reporting the problem.

`AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` is the strict production profile: the guard then also demands at least one readable anchor store outside the audit database (`filesystem` on an object-locked bucket, or a custom `AnchorStore` class), checks that it can be instantiated (the disk exists, the class implements the contract) and the doctor reports a database-only setup as `FAIL`. Switch it on as soon as the bucket exists; without it, database-only anchors are a `WARN`, because an attacker who can rewrite the chain can rewrite anchors stored next to it.

## Scheduling

```php
// routes/console.php or the scheduler
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->hourly()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
```

`audit:verify` exits 1 when any chain has violations and dispatches
`ChainViolationDetected` per affected chain. Wire the event to your alerting.

For large installations verify incrementally: keep the last verified sequence
per chain and call `Audit::verify($chain, $lastVerified + 1)`; the verifier
checks the predecessor link and the head.

## Keys

- Generate: `php artisan audit:key:generate --id=k2` (prints, does not write).
- Rotate: append the new key to `AUDIT_SIGNING_KEYS`, deploy, then set `AUDIT_SIGNING_ACTIVE_KEY` to it and deploy again. Old keys stay listed so old signatures verify; remove a key only after every event signed with it has left retention.
- Store keys in a secret manager — never in the database, never in a config table, never in the same vault path as database credentials.
- Procedure, checklist and the compromised-key case: [Key rotation](operations/key-rotation.md).

## Anchors

- `database` store: convenient, append-only through triggers, but not independent of the database.
- `filesystem` store: point it at an S3 (or compatible) bucket with Object Lock in compliance mode and a retention period at least as long as your audit retention.
- `log` store: write-only, one structured line per anchor; forward the channel to a SIEM or an immutable log service. Because it cannot be read back, the verifier ignores it; always combine it with a readable store (`filesystem` first, `database` as fallback).

List the external store first in `AUDIT_ANCHOR_STORES`; the verifier prefers the first store that has an anchor. With `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` production does not boot without such a store (see Doctor above).

### Production recipe: anchors on object storage

Everything below is copyable; nothing needs a published config file.

1. **A bucket with Object Lock** (compliance mode, retention at least as long as your audit retention) and **credentials of its own** — never the ones that can reach the audit database — with exactly what the store needs: put, list the prefix, get (the verifier and the doctor read the newest anchor back); no delete, no retention changes. The minimal policy is in the [Production guide](guides/production.md#3-anchors-on-object-storage). The framework cannot make storage immutable; it can only use storage that is.
2. **A disk for it** in `config/filesystems.php`:

   ```php
   'audit-anchors' => [
       'driver' => 's3',
       'key' => env('AUDIT_ANCHOR_AWS_KEY'),
       'secret' => env('AUDIT_ANCHOR_AWS_SECRET'),
       'region' => env('AUDIT_ANCHOR_AWS_REGION'),
       'bucket' => env('AUDIT_ANCHOR_AWS_BUCKET'),
       'throw' => true,
   ],
   ```

3. **The `.env` block** (see `.env.example` for every other variable):

   ```dotenv
   AUDIT_SIGNING_KEYS=k1:base64:<value from the secret manager>
   AUDIT_SIGNING_ACTIVE_KEY=k1
   AUDIT_ANCHOR_STORES=filesystem,database
   AUDIT_ANCHOR_DISK=audit-anchors
   AUDIT_REQUIRE_EXTERNAL_ANCHOR=true
   AUDIT_FAIL_FAST=true
   ```

   `AUDIT_ANCHOR_DISK` alone changes nothing: the `filesystem` store has to be in `AUDIT_ANCHOR_STORES`, external store first.
4. **Scheduler**: `audit:anchor` every few minutes, `audit:verify` at least daily (see Scheduling above).
5. **Deployment gate**: `php artisan security:doctor --strict` after the rollout; exit 0 means the key is in the ring, the external store answers, the requirement is enforced at boot and the chains verify. Exit 1 stops the deployment.

## Database

- PostgreSQL: nothing special. Triggers use `plpgsql`.
- MySQL 8 / MariaDB 11: creating triggers needs `CREATE TRIGGER`; with binary logging also `SUPER` or `log_bin_trust_function_creators=1`. Run the audit migration with a privileged user, or set the server variable.
- SQLite: supported for development and tests; do not use it for production audit logs (no row locks across connections in the way the writer expects).
- Separate connection: set `AUDIT_DB_CONNECTION` to write audit tables to their own database/user. The minimal grants are `SELECT`, `INSERT` on `audit_events`, `audit_anchors` and `audit_verification_runs`, and `SELECT`, `INSERT`, `UPDATE` on `audit_chain_heads` (the head row is advanced on every write; `SELECT … FOR UPDATE` needs `UPDATE`). Together with the triggers this stops the application user from ever changing or deleting an event.
- Separate connection and atomicity: an audit write on its own connection cannot share a transaction with your business write. If the audit record must exist if and only if the business change does, keep both on one connection and wrap them in one `Audit::transaction()` (next bullets); otherwise accept that a crash between the two leaves either one without the other.
- MySQL/MariaDB and concurrency: the writer creates the chain's head row in an auto-commit statement before its transaction so that concurrent first writers cannot deadlock. Inside your own transaction that is impossible; if you write the first events of new chains inside larger transactions under load, call `Audit::prepareChain($tenantId)` (outside the transaction, e.g. when the tenant is created).
- Audit writes inside business transactions: **use `Audit::transaction()`**, not `DB::transaction()`. The chain head is locked at the point of the audit write and held until your transaction commits, so concurrent transactions for the same tenant serialise there. Every database can turn that into a retryable conflict: InnoDB deadlocks, PostgreSQL `could not serialize access` under REPEATABLE READ, and MariaDB 11.6+ (default `innodb_snapshot_isolation=ON`) `ER_CHECKREAD 1020 "Record has changed since last read"` whenever the head row changed after your transaction's first read. Laravel raises these as `DeadlockException` / `QueryException`; `DB::transaction($fn, attempts: n)` retries immediately and under sustained load tends to collide again. Wrap such transactions in `Audit::transaction(fn () => ..., attempts: 10)`: it retries the whole transaction with jittered back-off on concurrency errors only, and runs the callback once when a transaction is already open. Because the closure can run several times, it must not send e-mails, call external APIs or produce other non-transactional side effects; queue those after it returns, or make them idempotent. Alternatives: run the connection with `'isolation_level' => 'READ COMMITTED'` (MySQL/MariaDB; each statement sees the latest committed state), or take the audit write out of the business transaction when strict atomicity is not required.

## Quality gates

Every release is held to the gates below; `security:doctor` is the only one an
operator runs. The rest are here so that you know what a green release means —
how to run and extend them is maintainer documentation
(`docs/maintainers/testing.md`).

| Gate | What it proves |
| --- | --- |
| `composer audit` | no known advisories in the dependency tree |
| Pint, PHPStan level 8 | style, types (including the traits in a real model context) |
| Test matrix | behaviour on SQLite, PostgreSQL, MySQL, MariaDB; PHP 8.3/8.4/8.5 × Laravel 13 as hard gates, lowest and highest dependencies, PostgreSQL 14–17, MySQL 8.0/8.4, MariaDB 10.11–11; line coverage ≥ 80 % (PHP 8.5 and Laravel 13 are observed in a separate job that may fail) |
| Concurrency and load tests | forked writers never fork a chain, with and without business transactions; dozens of writers on two chains stay linear |
| Randomised invariants | canonical JSON, hashing, identifiers, tenant ids and the sanitiser hold for random inputs |
| Known-answer vectors | the hash format is byte-identical to recorded vectors (version, domain string, hashed fields, canonical JSON, HMAC); a change is a new hash version, never a silent edit, and `packages/audit/tests/Fixtures/hash-vectors.json` lets anyone recompute a hash without this code base |
| Mutation testing | the tests notice when the security core is changed (CI fails below a 70 % score; the full report is a build artifact) |

## Performance

- Tenant lookup is cached (`tenant.cache`, default 60 s, negative 5 s). Status changes invalidate the cache on save.
- Every audit write takes one row lock on `audit_chain_heads` for the duration of the insert; writers to different chains do not block each other. Expect low single-digit milliseconds per write on PostgreSQL. Only the very first write of a chain adds an extra existence check and insert; afterwards it is one cheap `SELECT` before the transaction.
- Verification is chunked (`audit.verification.chunk_size`, default 1000) and streams; memory stays flat.

## Observability

Events you can listen to:

| Event | When |
| --- | --- |
| `Tenant\Events\TenantContextActivated` | a tenant became active (request, job, `run()`) |
| `Tenant\Events\TenantAccessDenied` | membership, role or token check failed |
| `Tenant\Events\TenantScopeBypassed` | `withoutTenant()` entered |
| `Tenant\Events\MembershipChanged` | grant / role change / status change / revoke |
| `Tenant\Events\TenantSwitched` | session tenant changed |
| `Audit\Events\AuditEventRecorded` | after each successful audit write |
| `Audit\Events\ChainViolationDetected` | verifier found violations |

The audit package subscribes to the first five and writes them to the log (`audit.record_tenant_events`). Events without a tenant (a `withoutTenant()` outside any tenant context, a denial for an unknown tenant) land on the `global` chain regardless of `audit.tenant_policy`; verify it with `audit:verify` like any other chain.

Doctor JSON, check ids, the metrics worth exporting and the alerting rules: [Monitoring](operations/monitoring.md).

## Incident response

In short: preserve the database and the anchor store before anything else; run `php artisan audit:verify --json` and `security:doctor --json --strict` from a trusted host and keep the output; read each violation's `reason` (`event_hash_mismatch` pinpoints changed rows; `sequence_gap` and `previous_hash_mismatch` bracket deletions; `head_mismatch`/`anchor_ahead` show truncation; `signature_*` shows rewrites without the key; `anchor_mismatch` shows rewrites with the key but before the last anchor); find the last external anchor the chain still matches — that is the last trustworthy point. The full procedure, including containment and what to record: [Incident response](operations/incident-response.md).
