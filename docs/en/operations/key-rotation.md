# Key rotation

The audit package signs every event and every anchor with an HMAC key from a
**keyring**. Rotation is designed to be boring: add a key, make it active,
deploy. Nothing is re-signed, nothing is migrated, and old signatures keep
verifying for as long as their key stays in the ring.

## The keyring

```dotenv
AUDIT_SIGNING_KEYS="k1:base64:<32+ random bytes>,k2:base64:<32+ random bytes>"
AUDIT_SIGNING_ACTIVE_KEY=k2
```

- Entries are comma-separated, each `<id>:base64:<key>`. Ids match
  `/^[A-Za-z0-9_.-]{1,32}$/`; keys are at least 32 bytes after decoding.
- **The active key signs.** Every other key in the ring only verifies.
- Each event and anchor stores the `key_id` it was signed with, so the
  verifier picks the right key by itself.
- An entry that is malformed, a key that is too short, an active id that is
  not in the ring, or keys without an active id → `InvalidSigningKey` at
  boot. The framework does not start with a broken ring; it does not fall back
  to an unsigned mode.

Generate a key:

```bash
php artisan audit:key:generate --id=k2
# prints two lines to append / set:
#   AUDIT_SIGNING_KEYS="k2:base64:…"
#   AUDIT_SIGNING_ACTIVE_KEY=k2
```

The command prints, it does not write. Put the value into your secret
manager; the application reads it from the environment at boot.

## Where the key lives

The key must not be readable from wherever the database is readable. This is
the single most important operational property of the audit log: an attacker
with database access *and* the key can rewrite history consistently up to the
last external anchor, and the signatures will not tell you. Concretely:

- Secret manager (Vault, AWS Secrets Manager, GCP Secret Manager, …) → env
  var at boot. Not in the database, not in a config table, not in a file the
  web server can serve.
- A **different vault path / IAM policy** from the database credentials.
  The application host holds both at runtime — that is unavoidable — but a
  DBA, a backup operator or a leaked database dump must not come with the key.
- The verifier host (where `audit:verify` runs in an incident) gets the ring
  read-only; it never needs the active key to sign.
- `AUDIT_PSEUDONYM_KEY` (the pepper for hashed identifiers in metadata) is a
  second secret with the same rules. Unset, it is derived from `APP_KEY`;
  set it separately in production so that rotating `APP_KEY` does not change
  pseudonyms.

## Scheduled rotation

Rotate on a schedule (yearly is common; your policy decides) and on every
suspicion. The procedure is the same:

1. **Generate** a new key: `php artisan audit:key:generate --id=k3`.
2. **Append** it to `AUDIT_SIGNING_KEYS` in the secret manager. Keep every
   existing entry. Do not yet change the active key.
3. **Deploy** (or restart) so that every instance knows `k3`. With several
   instances, wait until all of them run with the new ring — an instance that
   signs with `k3` while another one has not loaded it would still write valid
   events, but the second instance's doctor and verifier would report
   `signature_key_unknown` until it restarts.
4. **Activate**: set `AUDIT_SIGNING_ACTIVE_KEY=k3`, deploy again.
5. **Verify**: `php artisan security:doctor --strict` (the `signing_key` check
   reports `n key(s) in the ring, active: k3`) and `php artisan audit:verify`. Events after the
   switch carry `key_id = k3`; earlier ones still verify with `k2`.
6. **Anchor** once explicitly: `php artisan audit:anchor --force`, so that
   the first anchor signed with the new key exists immediately.

Two deployments instead of one are deliberate: a ring that gains a key is
always safe to roll out gradually; a ring whose active key changes is only
safe once every instance knows that key.

## Retiring a key

Remove a key from the ring only when **no event or anchor signed with it is
still within your retention period**. The verifier reports every event whose
`key_id` is not in the ring as `signature_key_unknown`; that is a violation,
and `audit:verify` will exit 1 for as long as such events exist.

Before removing `k1`:

```sql
SELECT chain_key, MIN(occurred_at), MAX(occurred_at), COUNT(*)
FROM audit_events WHERE key_id = 'k1' GROUP BY chain_key;
SELECT COUNT(*) FROM audit_anchors WHERE key_id = 'k1';
```

If both are empty (or every row is outside retention and has been purged by
your retention process), drop the entry, deploy, and run `audit:verify` once
more. A retired key should still be archived offline — an auditor may ask you
to re-verify an exported range years later.

## Compromised key

Treat a key as compromised when it was in a leaked dump, on a lost laptop,
in a log line, in a CI artifact, or held by someone who has left with it.

1. **Rotate immediately** (steps 1–6 above, in one go if necessary: adding
   `k3` and activating it in a single deployment is acceptable under
   pressure — the short window of `signature_key_unknown` on a not-yet
   restarted instance is preferable to another hour of a compromised active
   key).
2. **Do not remove the compromised key yet.** The events it signed are still
   evidence; the question is whether they were tampered with.
3. **Verify from a clean host**: run `audit:verify --json` on a machine that
   never held the compromised key, reading the database and the external
   anchor store. Signatures made with `k2` still verify — the point of the
   run is the *anchors*: every external anchor written before the compromise
   is a signed statement about the chain that the attacker could not have
   forged in that store. If the chain still matches those anchors, history up
   to the last pre-compromise anchor is intact.
4. **Bracket the exposure**: from the last anchor you trust to the rotation,
   events are "signed with a key an attacker also had". They are not
   necessarily false — but they cannot be *proven* true by signature alone.
   Record that window in your incident notes; the anchors and the
   application logs from that period are the corroborating evidence.
5. Continue with [incident response](incident-response.md) if any check
   fails.

## Rotating `APP_KEY`

`APP_KEY` is Laravel's encryption key, not the audit signing key. Rotating it
does not touch signatures. It does affect `AUDIT_PSEUDONYM_KEY` when that is
unset (derived from `APP_KEY`): identifiers hashed into metadata before and
after the rotation would no longer match each other. Set `AUDIT_PSEUDONYM_KEY`
explicitly *before* you rotate `APP_KEY` for the first time, and keep it
stable.

## Checklist

- [ ] keys generated with `audit:key:generate`, ≥ 32 bytes, ids you can read
      in a report (`k2026-01`, not `key`)
- [ ] ring and active key in the secret manager; database credentials on a
      different path
- [ ] rotation in two deployments (add, then activate) on multi-instance setups
- [ ] `security:doctor --strict` and `audit:verify` green after each step
- [ ] key removed only after retention; archived offline afterwards
- [ ] `AUDIT_PSEUDONYM_KEY` set explicitly in production

Related: [Auditing guide](../guides/auditing.md) · [Production guide](../guides/production.md) ·
[Trust boundaries](../diagrams/trust-boundaries.md) · [Configuration → signing](../reference/configuration.md)
