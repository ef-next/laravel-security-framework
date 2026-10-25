# Compatibility

## Support matrix

| | Supported — hard CI gates | Experimental — installable, observed in CI, no support promise |
| --- | --- | --- |
| PHP | 8.3, 8.4 | 8.5 |
| Laravel | 12 | 13 (blocked until the Pest toolchain supports it; the CI row shows the current state) |
| PostgreSQL | 14, 16, 17 | — |
| MySQL | 8.0, 8.4 | — |
| MariaDB | 10.11, 11.4, 11 (latest) | — |
| SQLite | tests and local development only — production refuses to boot on it | — |

"Supported" means the combination is a required job in the CI gates
(`.github/workflows/gates.yml`) and a release is not tagged while it is red.
"Experimental" rows run in a job that may fail; a row that stays green for a
full minor release cycle becomes supported. The Composer constraints allow the
experimental combinations so that they can be tried.

Only the latest minor release receives fixes (`SECURITY.md`).

## What each database needs

| Database | Requirement | Why |
| --- | --- | --- |
| PostgreSQL | nothing special; triggers use `plpgsql` | append-only triggers |
| MySQL 8.x | the migration user has `CREATE TRIGGER`; with binary logging also `SUPER` or the server variable `log_bin_trust_function_creators=1` | InnoDB triggers under binlog |
| MariaDB 10.11+ | same as MySQL; MariaDB 11.6+ defaults to `innodb_snapshot_isolation=ON`, which turns head-row conflicts into error 1020 — `Audit::transaction()` retries them | triggers, snapshot isolation |
| SQLite | `pdo_sqlite`; used by the test suite | no row locks across connections the writer relies on — never for production audit logs |

All three production databases: the audit tables need row-level `SELECT … FOR UPDATE`
(the writer serialises appends per chain on the head row) and microsecond
timestamps (`datetime(6)` / `timestamp(6)` columns; created by the migration).

## PHP extensions

`pdo` with the driver you use, `json`, `mbstring`, `openssl` (random bytes,
HMAC via `hash_hmac` which is core), and for the test suite `pcntl` (forked
concurrency tests) and a coverage driver (`pcov` or `xdebug`) for mutation
testing.

## Laravel features relied on

Middleware priority (`Kernel::addToMiddlewarePriorityBefore` / the router's
priority list), Eloquent global scopes and model events, `Queue::createPayloadUsing()`
and the job lifecycle events, `Storage` disks for the filesystem anchor store,
Sanctum's `currentAccessToken()` abilities (optional). Laravel Octane is
supported: the tenant context is cleared on every `*Terminated` event.

## Upgrading

Semantic versioning on all packages together. Patch: no schema or behaviour
change. Minor: additive migrations only. Major: anything that changes what is
hashed (`HashableRecord::VERSION`), removes configuration keys or changes a
fail-closed default — with a migration guide. Chains written by an older patch
or minor verify under the newer one; a major keeps verifying old chains through
the stored `hash_version`. Details in `docs/maintainers/releasing.md`.
