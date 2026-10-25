# Production guide

The complete production setup, copyable: keys, database, anchors on object
storage, scheduler, monitoring, and `security:doctor --strict` as the
deployment gate. Nothing here needs a published config file; everything is
environment variables and the two blocks of PHP shown.

Read this together with the [integration checklist](../integration-checklist.md)
(tick it) and the [threat model](../threat-model.md) (know what remains).

## The target state

| Guarantee | Production condition | How you prove it |
| --- | --- | --- |
| Audit records cannot be altered unnoticed | PostgreSQL / MySQL / MariaDB; append-only triggers installed; signing key in a secret manager; anchors on object storage with Object Lock, enforced by `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` | `security:doctor --strict` exit 0 |
| Chains are verified and anchored continuously | scheduler runs `audit:anchor` every few minutes and `audit:verify` daily | doctor checks `anchor_freshness`, `verification_recency` |
| Someone finds out | `ChainViolationDetected` and doctor failures reach a person | an alert test |
| Tenants stay isolated under load | tenant cache on redis/memcached/database, not `array` | doctor check `tenant_cache` |

## 1. Secrets

```bash
php artisan audit:key:generate --id=k1
```

Put the two lines into your **secret manager**, not into a `.env` file on the
host, and never in the same vault path as the database credentials — a
database-level attacker who also has the key can rewrite history consistently.
Inject them as `AUDIT_SIGNING_KEYS` and `AUDIT_SIGNING_ACTIVE_KEY` at deploy
time. Optionally give the pseudonymisation pepper its own secret
(`AUDIT_PSEUDONYM_KEY`, 32+ random bytes); otherwise it is derived from `APP_KEY`.

## 2. Database

- PostgreSQL 14+, MySQL 8.0/8.4 or MariaDB 10.11+. SQLite refuses to boot in production.
- The **migration** user needs `CREATE TRIGGER`; on MySQL/MariaDB with binary
  logging also `SUPER` or `log_bin_trust_function_creators=1`. Run migrations
  with that user; the application user does not need it.
- Optional privilege separation: `AUDIT_DB_CONNECTION=audit` pointing at a
  connection whose user has only `SELECT, INSERT` on `audit_events`,
  `audit_anchors`, `audit_verification_runs` and `SELECT, INSERT, UPDATE` on
  `audit_chain_heads`. You lose atomicity between business and audit writes
  (the doctor says so); decide consciously.
- Keep the application's database user without `DROP TRIGGER`/`ALTER` rights on
  the audit tables. The triggers are the first layer, not the only one.

## 3. Anchors on object storage

1. A bucket with **Object Lock** in compliance mode and a retention period at
   least as long as your audit retention, and **credentials of its own** that
   the database host does not have. The `filesystem` store needs three
   permissions and must not have a fourth: it *writes* one object per anchor
   (`PutObject`), *lists* a chain's prefix and *reads* the newest anchor
   (`ListBucket` on the prefix, `GetObject`) — that is how the verifier and the
   doctor compare the chain with the external copy — and it never deletes.
   Object Lock keeps even a stolen credential with `DeleteObject` from
   removing an object within the retention period; the policy keeps the
   application from having that permission in the first place:

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       { "Effect": "Allow", "Action": ["s3:PutObject", "s3:GetObject"],
         "Resource": "arn:aws:s3:::audit-anchors-prod/audit-anchors/billing/*" },
       { "Effect": "Allow", "Action": "s3:ListBucket",
         "Resource": "arn:aws:s3:::audit-anchors-prod",
         "Condition": { "StringLike": { "s3:prefix": "audit-anchors/billing/*" } } },
       { "Effect": "Deny",
         "Action": ["s3:DeleteObject", "s3:DeleteObjectVersion", "s3:PutObjectRetention",
                    "s3:PutObjectLegalHold", "s3:BypassGovernanceRetention", "s3:PutBucketObjectLockConfiguration"],
         "Resource": ["arn:aws:s3:::audit-anchors-prod", "arn:aws:s3:::audit-anchors-prod/*"] }
     ]
   }
   ```

   Adapt bucket name and prefix (`AUDIT_ANCHOR_PATH`); other providers'
   policies map one to one (write, list-prefix, read; deny delete and
   retention changes). The framework cannot make storage immutable; it can
   only use storage that is.
2. A disk:

   ```php
   // config/filesystems.php
   'audit-anchors' => [
       'driver' => 's3',
       'key' => env('AUDIT_ANCHOR_AWS_KEY'),
       'secret' => env('AUDIT_ANCHOR_AWS_SECRET'),
       'region' => env('AUDIT_ANCHOR_AWS_REGION'),
       'bucket' => env('AUDIT_ANCHOR_AWS_BUCKET'),
       'throw' => true,
   ],
   ```

3. The environment:

   ```dotenv
   AUDIT_ANCHOR_STORES=filesystem,database     # external store first; AUDIT_ANCHOR_DISK alone changes nothing
   AUDIT_ANCHOR_DISK=audit-anchors
   AUDIT_ANCHOR_PATH=audit-anchors/billing     # one prefix per application if several share a bucket
   AUDIT_REQUIRE_EXTERNAL_ANCHOR=true          # production refuses to boot without the external store
   ```

A write-only `log` store (`AUDIT_ANCHOR_STORES=filesystem,log,database`,
`AUDIT_ANCHOR_LOG_CHANNEL=siem`) is a good third copy if you already ship logs
to an immutable sink; it never replaces the readable external store.

## 4. The production environment block

```dotenv
APP_ENV=production
APP_DEBUG=false

SECURITY_SERVICE_NAME=billing
TENANT_USER_KEY_TYPE=unsignedBigInteger
TENANT_CACHE_STORE=redis

AUDIT_SIGNING_KEYS=k1:base64:<from the secret manager>
AUDIT_SIGNING_ACTIVE_KEY=k1
AUDIT_TENANT_POLICY=required
AUDIT_ENFORCE_APPEND_ONLY=true

AUDIT_ANCHOR_STORES=filesystem,database
AUDIT_ANCHOR_DISK=audit-anchors
AUDIT_REQUIRE_EXTERNAL_ANCHOR=true
AUDIT_FAIL_FAST=true
```

`.env.example` in the framework repository documents every other variable.

## 5. Scheduler

```php
// routes/console.php
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->daily()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
```

`onOneServer()` matters: two anchoring runs at once are harmless but wasteful;
two verifications at once double the load. `audit.doctor.max_anchor_age_minutes`
(default 60) should be several multiples of the anchor interval.

## 6. The deployment gate

```bash
php artisan migrate --force
php artisan security:doctor --strict        # exit 1 stops the deployment
```

`--strict` is the high-security profile: warnings count as failures, and the
`anchor_stores` check fails unless `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` — an
external store that is merely configured could be dropped by the next
deployment without anything refusing to start; the requirement has to be
enforced at boot. Put the command into the pipeline after every rollout and
stop on a non-zero exit. `--json` gives the same verdict (`healthy`, `strict`)
for tooling.

Expected on a correct installation:

```
  [OK] Environment .......... APP_ENV=production, debug off, fail-fast on.
  [OK] Signing key .......... 1 key(s) in the ring, active: k1.
  [OK] Database driver ...... Connection "pgsql": pgsql 16.4.
  [OK] Append-only triggers . All 4 triggers present on audit_events and audit_anchors.
  [OK] Anchor stores ........ Configured: filesystem, database (readable: filesystem, database; external: filesystem, required).
  …
  OK / WARN / FAIL / SKIP ... 13 / 0 / 0 / 0
```

## 7. Monitoring

Three signals, in order of urgency:

| Signal | Source | Action |
| --- | --- | --- |
| `ChainViolationDetected` event / `audit:verify` exit 1 | verifier | page; start [incident response](../operations/incident-response.md) |
| `security:doctor` FAIL | scheduler (`emailOutputOnFailure`) or your monitoring calling `--json` | fix before the next deployment; a FAIL means a guarantee is currently not given |
| `security:doctor` WARN | same | plan the fix; under `--strict` a WARN already fails the gate |

```php
Event::listen(ChainViolationDetected::class, fn ($e) => Alert::page('audit-integrity', $e->report->chainKey->toString(), $e->report->toArray()));
```

Useful metrics to export: `audit_verification_runs` (last intact run per
chain), anchors per hour, `tenant.access_denied` events per tenant (a spike is
either a bug or an attack). Details in
[operations/monitoring.md](../operations/monitoring.md).

## 8. Deployment topology notes

- **Laravel Octane / long-running workers**: supported; the tenant context is
  cleared on every request/job end. Nothing to configure.
- **Multiple applications, one bucket**: distinct `AUDIT_ANCHOR_PATH` prefixes
  and distinct `SECURITY_SERVICE_NAME`s.
- **Read replicas**: point the audit connection at the primary; the writer needs
  `SELECT … FOR UPDATE`.
- **Blue/green**: both colours may anchor; anchors are append-only and
  idempotent per head.
- **Backups**: the audit database and the anchor bucket are backed up and
  restored *together*; a restored database without its anchors verifies but
  cannot prove it was not truncated before the restore.

## 9. Rotation and change

- Key rotation: [operations/key-rotation.md](../operations/key-rotation.md).
- Changing the anchor store: add the new store *first* in
  `AUDIT_ANCHOR_STORES`, run `audit:anchor --force`, verify, then remove the
  old one after its retention period. Anchors in the old store remain valid
  evidence for the period they cover.
- Framework upgrades: read the CHANGELOG for every skipped version, `migrate`,
  `security:doctor --strict`. Migrations are additive within a major version.

## 10. Go-live checklist (short form)

- [ ] `security:doctor --strict` exits 0 on the production environment
- [ ] keys in the secret manager, not on the host; database credentials elsewhere
- [ ] Object Lock verified by trying to delete an anchor object **with an administrative principal that has `DeleteObject`** (it must fail with an Object Lock error — with the application's credential the attempt fails at IAM and proves nothing about the lock)
- [ ] scheduler entries deployed and observed to run (`audit_anchors` grows)
- [ ] `ChainViolationDetected` wired to alerting and tested once with a fake report
- [ ] application user without `DROP TRIGGER` on the audit tables
- [ ] the full [integration checklist](../integration-checklist.md) ticked
