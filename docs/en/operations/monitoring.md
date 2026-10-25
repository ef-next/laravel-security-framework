# Monitoring

What to watch once the framework is in production, where each signal comes
from, and what a change in it means. The framework emits three kinds of
signal: the **doctor** (configuration and health), the **verifier**
(integrity) and **events** (behaviour). Nothing here needs a published config
file.

## 1. The doctor as a health endpoint

`php artisan security:doctor --json` prints one report:

```json
{
  "healthy": true,
  "strict": false,
  "counts": { "ok": 13, "warn": 0, "fail": 0, "skip": 0 },
  "checks": [
    { "id": "signing_key", "status": "ok", "title": "Signing key", "detail": "…", "hint": null },
    { "id": "anchor_freshness", "status": "ok", "title": "Anchor freshness", "detail": "…", "hint": null }
  ]
}
```

- `healthy` is the value to gate on: `false` when any check fails, and with
  `--strict` also when any check warns. The process exit code says the same
  (0 / 1).
- `checks[].id` is stable; alert on ids, not on titles or wording.
- `--no-verify` skips the live tail verification (the only check that reads
  event rows) and makes the run cheap enough for every few minutes.

The check ids and what a non-OK result means:

| id | WARN means | FAIL means |
| --- | --- | --- |
| `environment` | `APP_DEBUG` on, or `AUDIT_FAIL_FAST` off in production | — |
| `app_key` | — | no `APP_KEY` |
| `signing_key` | — | no keyring, malformed, active key missing or shorter than 32 bytes |
| `database_driver` | — | SQLite in production, or unsupported driver |
| `append_only_config` | — | `AUDIT_ENFORCE_APPEND_ONLY=false` |
| `append_only_triggers` | — | the triggers are not installed on `audit_events` / `audit_anchors` (dropped, or migration skipped) |
| `anchor_stores` | only the database store is readable | no readable store; an external store that does not answer; under `--strict`: `AUDIT_REQUIRE_EXTERNAL_ANCHOR` not set |
| `separate_connection` | audit tables share the application connection | — |
| `anchor_freshness` | — | a chain has events older than `audit.doctor.max_anchor_age_minutes` (default 60) that no anchor covers; or the external store cannot be read |
| `verification_recency` | no intact full verification within `max_verification_age_hours` (default 24) | a recorded run found violations |
| `tail_verification` | — | the last `tail_events` (default 200) of a chain do not verify |
| `middleware_priority` | — | tenant middleware is not ordered before `SubstituteBindings` |
| `tenant_cache` | tenant lookups use the `array` store in production | — |

Two ways to run it from monitoring:

```php
// routes/console.php – mail on failure, one server
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()
    ->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor')->daily()->onOneServer()
    ->emailOutputOnFailure('security@example.test');
```

```bash
# or from an external checker (exit code + JSON), e.g. a cron on the app host
php artisan security:doctor --json --no-verify | jq -e '.healthy' >/dev/null || alert "security:doctor unhealthy"
```

Do not expose the doctor over HTTP without authentication; its output names
your configuration.

## 2. Integrity: the verifier

`audit:verify` is the source of truth for "the log is intact". Schedule it at
least daily; hourly is cheap for most installations because verification
streams in chunks.

```php
Schedule::command('audit:verify')->hourly()->onOneServer()->emailOutputOnFailure('security@example.test');
```

Two outputs, wire both:

- **Exit code 1** whenever any chain has a violation (`--fail-fast` stops at
  the first one). `emailOutputOnFailure` or your scheduler's failure hook is
  the minimum.
- **`ChainViolationDetected`** is dispatched per affected chain with the full
  `VerificationReport`. This is the signal to page on:

```php
use Efnext\Security\Audit\Events\ChainViolationDetected;

Event::listen(ChainViolationDetected::class, function (ChainViolationDetected $event): void {
    $report = $event->report;

    Alert::page('audit-integrity', [
        'chain' => $report->chainKey->toString(),
        'violations' => count($report->violations),
        'first' => $report->violations[0]->describe(),
        'report' => $report->toArray(),
    ]);
});
```

A violation is never noise. There is no "expected" violation in normal
operation — a false positive means something is wrong with the deployment
(a key removed from the ring, a restore without its anchors), which is also
worth a page. Then follow [incident response](incident-response.md).

## 3. Behaviour: events and audit actions

The framework dispatches Laravel events you can subscribe to; the audit
package already writes the tenant ones to the log
(`audit.record_tenant_events`).

| Event | When | Watch for |
| --- | --- | --- |
| `Tenant\Events\TenantAccessDenied` | membership, role or token check refused a request | a spike per tenant or per user: a client bug, a revoked user still calling, or enumeration |
| `Tenant\Events\TenantScopeBypassed` | `Tenancy::withoutTenant()` entered | any occurrence outside the code paths you expect (reports, maintenance commands) |
| `Tenant\Events\MembershipChanged` | grant, role change, status change, revoke | changes outside your admin flows |
| `Tenant\Events\TenantSwitched` | session tenant changed | unusual frequency per session |
| `Tenant\Events\TenantContextActivated` | a tenant became active | volume metric only |
| `Audit\Events\AuditEventRecorded` | after every successful audit write | write rate per chain (a drop to zero for a busy tenant means a broken writer or a silent app) |
| `Audit\Events\ChainViolationDetected` | verifier found violations | see above |

The same information is in the log as audit actions (`tenant.access_denied`,
`tenant.scope_bypassed`, `tenant.membership.<change>`, `tenant.switched`), so a query over
`audit_events` gives the history without a metrics pipeline:

```sql
-- denied requests per tenant, last 24 h
SELECT tenant_id, COUNT(*) FROM audit_events
WHERE action = 'tenant.access_denied' AND occurred_at > NOW() - INTERVAL '24 hours'
GROUP BY tenant_id ORDER BY 2 DESC;
```

## 4. Metrics worth exporting

| Metric | Source | Alert when |
| --- | --- | --- |
| age of the newest anchor per chain | `audit_anchors` (`MAX(anchored_at)` per `chain_key`) or the doctor's `anchor_freshness` | older than twice the anchor interval |
| age of the last intact full verification per chain | `audit_verification_runs WHERE intact AND source = 'audit:verify'` | older than the verify interval + slack |
| verification runs with `intact = false` | `audit_verification_runs` | any |
| audit writes per minute per chain | `AuditEventRecorded` or `COUNT(*)` over `occurred_at` | zero for a chain that is normally busy |
| `tenant.access_denied` per tenant / per actor | audit log | above a per-tenant baseline |
| `tenant.scope_bypassed` | audit log | any occurrence from an unexpected `reason` |
| doctor `healthy` | `security:doctor --json` | `false` |

Anchors and verification runs are ordinary tables; any exporter that can run
SQL can read them. The heads table (`audit_chain_heads.updated_at`) tells you
when a chain last moved.

## 5. Logs

- The audit writer does not log successful writes (the row is the record).
- `InsecureConfiguration` at boot, `InvalidSigningKey`, and refused
  configuration are exceptions, not log lines; they reach your exception
  handler and stop the process.
- The `log` anchor store writes one structured line per anchor to the channel
  you configure (`AUDIT_ANCHOR_LOG_CHANNEL`). Forwarding that channel to a
  SIEM or an immutable log service gives you a third, independent copy of
  every head — write-only, so combine it with a readable store.
- With `audit.enforce_append_only=false` the migration logs a warning; the
  doctor reports it as FAIL on every run.

## 6. What monitoring cannot tell you

- That the anchor bucket is *immutable*. The doctor checks that the store
  answers and that anchors are fresh; Object Lock is a bucket property you
  verify once (try to delete an anchor) and audit through your cloud
  provider's configuration checks.
- That the key and the database are in different hands. That is a deployment
  property; see [Trust boundaries](../diagrams/trust-boundaries.md).
- That your own code queries tenant-owned tables only through Eloquent. That
  is code review; the composite foreign keys are the last line.

Related: [Production guide](../guides/production.md) · [Incident response](incident-response.md) ·
[Events reference](../reference/events.md) · [Artisan commands](../reference/artisan-commands.md)
