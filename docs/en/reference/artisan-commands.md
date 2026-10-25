# Artisan commands

Five commands; four from the audit package, one from the tenant package. All of
them return `0` on success and `1` on failure; `tenant:run` returns the exit
code of the command it wraps.

## `security:doctor`

```
security:doctor [--strict] [--json] [--no-verify]
```

Checks whether this installation still gives the guarantees the README
promises. Run it after every deployment and from monitoring.

| Option | Effect |
| --- | --- |
| `--strict` | The high-security profile / deployment gate: warnings count as failures, and the `anchor_stores` check fails unless `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true`. |
| `--json` | Machine-readable report: `{healthy, strict, counts: {ok, warn, fail, skip}, checks: [{id, status, title, detail, hint}]}`. `healthy` is the verdict under the mode the run was judged by. |
| `--no-verify` | Skip the live verification of the last `audit.doctor.tail_events` events per chain (faster, no database walk). |

Exit code `1` when any check is `FAIL`, or with `--strict` when any check is
`WARN`; otherwise `0`.

| Check id | Fails when | Warns when |
| --- | --- | --- |
| `environment` | — | production with `APP_DEBUG=true`; production with `AUDIT_FAIL_FAST=false` |
| `app_key` | `APP_KEY` empty | — |
| `signing_key` | keyring empty or invalid | — |
| `database_driver` | audit connection is SQLite or not pgsql/mysql/mariadb; connection unreachable | — |
| `append_only_config` | `audit.enforce_append_only` is false | — |
| `append_only_triggers` | a trigger is missing on `audit_events` or `audit_anchors`; unsupported driver | — |
| `anchor_stores` | no readable store; a store throws; external anchor required but not satisfied or unreadable; `--strict` while the requirement is off | only the database store is readable |
| `separate_connection` | — | audit connection differs from the application connection |
| `anchor_freshness` | heads unreadable; a chain unanchored longer than `max_anchor_age_minutes`; external anchor required but none exists | — (skip: no chains) |
| `verification_recency` | the last full run of a chain found violations | no intact full run within `max_verification_age_hours` (skip: no chains) |
| `tail_verification` | the tail of a chain does not verify | — (skip: `--no-verify` or no chains) |
| `middleware_priority` | `ResolveTenant` is not ordered before `SubstituteBindings` | — |
| `tenant_cache` | — | production with an `array`/`null` tenant cache store |

Every non-OK line carries a hint that names the fix. Each tail check is recorded
in `audit_verification_runs` with source `security:doctor`.

## `audit:verify`

```
audit:verify [--chain=*] [--from=1] [--to=] [--json] [--fail-fast]
```

Walks the hash chains and reports every violation (hash mismatch, sequence gap,
broken link, signature problem, head or anchor disagreement, unsupported hash
version, …). Schedule it at least daily.

| Option | Effect |
| --- | --- |
| `--chain` | Repeatable: a tenant id, `tenant:<id>` or `global`. Default: every chain that has a head row. |
| `--from`, `--to` | Sequence window (defaults: from the first event to the head). |
| `--json` | One report object per chain. |
| `--fail-fast` | Stop at the first chain with violations. |

Without a signing key it prints a warning (`hashes are verified, signatures are
not`) and continues. Every run is recorded in `audit_verification_runs` with
source `audit:verify`; a chain with violations dispatches
`ChainViolationDetected`. Exit code `1` when any chain has violations.

Output per chain: `tenant:<id> intact — <n> events, signatures: valid, anchor: #<seq>`
or `tenant:<id> VIOLATIONS` followed by one line per violation
(`<reason> at sequence <n> {details}`).

## `audit:anchor`

```
audit:anchor [--chain=*] [--force]
```

Stores the current head (last sequence and hash) of every chain in the
configured anchor store(s), signed with the active key. Schedule it every few
minutes. Chains whose head has not moved since the last anchor are reported as
`unchanged` unless `--force` is given. The footer names the store
(`filesystem+database` for a composite) and the number of anchors written.

Exit code is always `0`; a store that cannot be written (unreachable disk)
raises an exception, which Artisan turns into a non-zero exit.

## `audit:key:generate`

```
audit:key:generate [--id=k1]
```

Prints a fresh 32-byte HMAC key in the format `AUDIT_SIGNING_KEYS` expects,
plus the matching `AUDIT_SIGNING_ACTIVE_KEY` line. Key ids must match
`[A-Za-z0-9_.-]{1,32}`; an invalid id exits with `1`. No database access. When
rotating, append the new entry to the existing list and move the active key.

## `tenant:run`

```
tenant:run {tenant} {artisan} [--allow-inactive]
```

Runs another Artisan command inside a tenant context, e.g.
`php artisan tenant:run acme "reports:generate --month=2026-09"`. The tenant is
given as id, public id, global object id or slug. A suspended or archived
tenant is refused (exit `1`) unless `--allow-inactive` is set; an unknown tenant
exits with `1`. Otherwise the exit code is that of the inner command; the
previous context is restored afterwards.
