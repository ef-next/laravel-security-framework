# Incident response

What to do when the verifier reports a violation, the doctor reports that a
guarantee is gone, or you have reason to believe the audit log or a tenant
boundary was attacked. The framework's job in an incident is to tell you
**what changed, where, and what is still trustworthy**; this page is the
procedure around that.

Read this once before you need it. The first three steps are about *not
destroying evidence*.

## 0. Preserve first

Before any remediation, restart, migration, or "let me just check in
production":

1. **Snapshot the audit database** (or the whole database if the audit tables
   share it): a consistent dump or a storage-level snapshot. Note the time.
2. **Freeze the anchor store**: do not rotate, prune or reconfigure the
   bucket. If your provider offers a legal hold, apply it. Export the listing
   (`AUDIT_ANCHOR_PATH/<chain>/*.json`) with object versions and timestamps.
3. **Copy the application and web server logs** for the window in question,
   including the request ids — `audit_events.request_id` and
   `correlation_id` link the two.
4. **Do not rotate the signing key yet** unless you already know it leaked
   (then see [key rotation → compromised key](key-rotation.md#compromised-key)
   and come back). The signatures under the current key are part of the
   evidence.
5. Work on the snapshot where you can. Every command below is read-only, but
   the fewer hands on production during triage, the better.

## 1. Get the full picture

From a host you trust — ideally one that can read the database and the anchor
store but is not an application server:

```bash
php artisan audit:verify --json > verify-$(date -u +%Y%m%dT%H%M%SZ).json
php artisan security:doctor --json --strict > doctor-$(date -u +%Y%m%dT%H%M%SZ).json
```

`audit:verify` without `--chain` walks every chain from sequence 1; on a large
installation that takes a while, which is fine — you want the complete
report, not a sample. Keep the JSON files; they are the record of what the
system said at that moment.

The report per chain:

```json
{
  "chain_key": "tenant:0192a7c3-…",
  "from_sequence": 1,
  "to_sequence": null,
  "events_checked": 48213,
  "last_hash": "…",
  "intact": false,
  "signatures": "valid",
  "anchor": { "chain_key": "…", "sequence": 48190, "event_hash": "…", "key_id": "k2", "signature": "…", "anchored_at": "…" },
  "violations": [
    { "reason": "event_hash_mismatch", "sequence": 47001, "event_id": "01J…", "expected_event_hash": "…", "stored_event_hash": "…" }
  ]
}
```

## 2. Read the violations

Each violation has a `reason`, a `sequence` and (usually) an `event_id`. The
reason tells you what kind of manipulation you are looking at:

| Reason | What it means | Where to look next |
| --- | --- | --- |
| `event_hash_mismatch` | the row at this sequence no longer produces its stored hash: **a row was edited** | that row; `recorded_at` vs. database change tracking; who had `UPDATE` on `audit_events` — the triggers should have refused it, so also check `append_only_triggers` in the doctor report |
| `previous_hash_mismatch` | this row's link does not point at the actual previous row: **a row was replaced or removed before it** | the rows between the last good sequence and this one |
| `sequence_gap` / `missing_predecessor` | a sequence number is missing: **rows were deleted** | as above; a gap at the very end is truncation (see `head_mismatch`) |
| `signature_invalid` | the hash is consistent but the signature is not: **rewritten without the key** (or with a different key under the same id) | the attacker had database access but not the key — the good case; the rows are still wrong |
| `signature_key_unknown` | signed with a key id not in the ring | did someone remove a key from `AUDIT_SIGNING_KEYS`? a configuration incident, not necessarily an attack; restore the key and re-verify |
| `signature_missing` | no signature on an event although the ring is configured | events written while the ring was empty (misconfiguration window), or a rewrite that dropped the signature |
| `head_mismatch` | `audit_chain_heads` disagrees with the last event: **the chain was truncated or the head was tampered with** | compare the head with the newest external anchor; the anchor tells you the real length |
| `anchor_ahead` | an anchor claims a sequence the chain no longer has: **truncation after the anchor** | everything between the chain's current end and the anchor's sequence is gone; the anchor proves it existed |
| `anchor_mismatch` | the chain at the anchor's sequence has a different hash than the anchor recorded: **rewritten consistently — with the key — before the last anchor** | the signing key is compromised, or a database-level attacker had it; the external anchor is your only proof. Treat the key as compromised |
| `unsupported_hash_version` | an event claims a hash version this framework does not know | a downgraded deployment reading newer rows, or a manipulated row |

Two properties of the report help with scoping:

- **`signatures: "valid"` with violations** means the attacker did not have
  the key. Everything they changed is visible as a hash or signature
  violation; nothing is hidden.
- **`anchor` is the newest anchor the verifier found** (external store first,
  database as fallback). Anything at or below `anchor.sequence` that still
  verifies is confirmed by an independent copy; everything above it is
  confirmed by the chain and the signatures only.

## 3. Find the last trustworthy point

The external anchors are the trust boundary. For the affected chain:

```bash
# list the anchors in the external store (path = AUDIT_ANCHOR_PATH, ':' in the chain key becomes '_')
aws s3 ls s3://<bucket>/audit-anchors/tenant_0192a7c3-…/
```

Each object is one JSON anchor (`sequence`, `event_hash`, `key_id`,
`signature`, `anchored_at`). Then verify the chain **up to** each anchor,
newest first, until one passes:

```bash
php artisan audit:verify --chain=tenant:0192a7c3-… --to=48190 --json
php artisan audit:verify --chain=tenant:0192a7c3-… --to=47500 --json
```

The highest anchor whose range verifies intact and whose `event_hash` equals
the chain's hash at that sequence is the **last trustworthy point**.
Everything up to it is proven by an independent copy; everything after it is
what the incident is about. If the *database* anchors disagree with the
*external* ones, the database anchors were tampered with — note that, it
tells you what the attacker could reach.

If no external anchors exist (database-only setup), the last trustworthy point
is wherever the signatures stop being consistent — and if the key was also
available to the attacker, there is no provable point at all. That is the
scenario `security:doctor --strict` and `AUDIT_REQUIRE_EXTERNAL_ANCHOR`
exist to prevent; write it into the incident report.

## 4. Scope the exposure

With the last trustworthy point known:

1. **Which tenants**: every chain in the report with `intact: false`. A
   violation in one tenant's chain does not affect another's; the `global`
   chain covers tenant-less events (membership changes, scope bypasses,
   denials for unknown tenants).
2. **Which time window**: `occurred_at` of the first violation to the time of
   detection. `audit_verification_runs` tells you when the chain was last
   verified intact — the manipulation happened after that run.
3. **Who could**: the violations narrow the attacker's access:
   - hash violations, valid signatures → database write access, no key;
   - `anchor_mismatch` or otherwise consistent rewrites → database *and* key;
   - dropped triggers (`append_only_triggers` FAIL) → DDL privileges;
   - database anchors rewritten but external ones intact → no access to the
     bucket's delete permission (Object Lock held).
4. **What the log says about itself**: query the chain around the window —
   the events *before* the tampering often show the actor:

```sql
SELECT sequence, occurred_at, actor_type, actor_id, action, resource_type, resource_id, request_id
FROM audit_events
WHERE chain_key = 'tenant:0192a7c3-…' AND sequence BETWEEN 47990 AND 48010
ORDER BY sequence;
```

`tenant.scope_bypassed` and `tenant.membership.*` events in the `global`
chain, and `tenant.access_denied` spikes, are the ones to read first.

## 5. Contain

In this order, each step only after the previous one is done and noted:

1. **Revoke the access that made it possible**: database credentials that
   were leaked or over-privileged, the account that ran DDL, the deployment
   that disabled `AUDIT_ENFORCE_APPEND_ONLY`. Re-run the audit migration's
   trigger installation if the triggers were dropped
   (`php artisan migrate:refresh --path=…` is **not** the tool — it drops
   tables; re-create the triggers with the statements the migration uses for
   your driver, or restore from the snapshot and replay).
2. **Rotate the signing key** if the analysis in step 4 says the attacker
   had it, or if you cannot rule it out
   ([key rotation](key-rotation.md#compromised-key)).
3. **Rotate `AUDIT_PSEUDONYM_KEY`** only if it leaked; it does not protect
   integrity, it protects identifiers in metadata.
4. **Suspend affected tenants** if the compromise is a tenant boundary
   problem rather than a log problem: `status = suspended` on the tenant row
   makes every request to it a 403 immediately (the cache invalidates on
   save); jobs already queued for it still run unless you also pause the
   queue.
5. **Do not "repair" the chain.** There is no supported operation that
   rewrites `audit_events`, and you should not want one: the violations are
   the record of the incident. The chain continues after the tampered range;
   `audit:verify --from=<first sequence after the incident>` verifies the
   rest.

## 6. Record

The incident report should contain, at minimum:

- the `audit:verify --json` and `security:doctor --json --strict` outputs
  from step 1, with timestamps;
- the last trustworthy anchor per affected chain (sequence, hash, store,
  object version);
- the violation table with your interpretation per reason;
- the exposure (tenants, window, access level);
- what was revoked, rotated, restored, and when;
- the sequence from which the chain is considered clean again.

Write a summary of it into the audit log itself, from the `global` chain,
so that the next verifier run carries the acknowledgement:

```php
Audit::action('security.incident.recorded')
    ->global()
    ->on('incident', 'INC-2026-014')
    ->with(['chains' => ['tenant:0192a7c3-…'], 'last_trusted_sequence' => 48190, 'reason' => 'event_hash_mismatch'])
    ->bySystem('incident-response')
    ->record();
```

## 7. After the incident

- Keep the snapshot, the anchor export and the JSON reports for your
  retention period; they are what an auditor will ask for.
- If the last trustworthy point was older than your anchor interval, the
  scheduler was not doing its job before the incident; fix the monitoring
  ([monitoring](monitoring.md)) before anything else.
- If the incident was possible because the key and the database were reachable
  from the same place, split them now
  ([trust boundaries](../diagrams/trust-boundaries.md)).
- If it was a tenant boundary problem, add the missing test to the
  application's suite before the fix goes out; `TenantContextMissing` and the
  cross-tenant leak test in [AGENTS.md](../../../AGENTS.md) are the minimum.

## When it is not an attack

Most first violations are operational:

| Symptom | Usual cause | Fix |
| --- | --- | --- |
| `signature_key_unknown` on old events after a deployment | a key was removed from `AUDIT_SIGNING_KEYS` too early | put it back; see [retiring a key](key-rotation.md#retiring-a-key) |
| `anchor_ahead` after a restore | the database was restored from a backup older than the newest anchor | the anchor is right: the restore lost events. Record the gap; the chain continues from the restored head. Restore database and anchor store *together* next time |
| `head_mismatch` right after a crash | the writer's transaction committed the event but the process died before the head update | the framework updates both in one transaction, so this should not happen; if it does, open an issue with the report and keep the snapshot |
| `signature_missing` for a range | events were written while the ring was empty (a deployment without the secret) | the range is hashed and chained but unsigned; note it, fix the deployment, and let the doctor's `signing_key` check guard it from now on |
| `unsupported_hash_version` | an older framework version reading rows written by a newer one | roll forward |

Even then: run the full verify, keep the report, and let the violation stand.
The log is supposed to remember.

Related: [Monitoring](monitoring.md) · [Key rotation](key-rotation.md) ·
[Audit write path and verification](../diagrams/audit-write-and-verification.md) ·
[Troubleshooting](../guides/troubleshooting.md)
