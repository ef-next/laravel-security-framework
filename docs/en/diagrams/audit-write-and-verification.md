# Audit write path and verification

**Reader question:** what happens between `Audit::action()->record()` and a
persistent, signed chain entry — and how do `audit:anchor`, `audit:verify` and
`security:doctor` later prove that nothing was changed?

## The write path

```mermaid
sequenceDiagram
    autonumber
    participant App as Business code
    participant M as AuditManager
    participant W as DatabaseAuditWriter
    participant San as MetadataSanitizer
    participant DB as Database
    participant Sig as HmacSigner<br/>(key from secret manager)

    App->>M: Audit::transaction(fn)
    M->>DB: BEGIN
    App->>M: Audit::action('invoice.paid')->on($invoice)->with([...])->record()
    M->>W: record(AuditEntry)
    W->>W: tenant = entry ?? active context (policy "required": none → refuse)
    W->>W: actor = ->by() ?? authenticated user / anonymous / system
    W->>San: sanitize(metadata)
    San-->>W: redacted keys → [REDACTED], identifiers → *_hash, size bounded
    W->>DB: ensure head row for chain (autocommit, outside the transaction)
    W->>DB: SELECT … FROM audit_chain_heads WHERE chain_key = ? FOR UPDATE
    DB-->>W: last_sequence n, last_hash h(n)
    W->>W: canonical JSON of {v, id, chain, tenant, sequence n+1, previous_hash h(n), occurred_at_us, actor, action, resource, outcome, metadata, request}
    W->>W: event_hash = SHA-256(domain ‖ canonical JSON)
    W->>Sig: sign(event_hash)
    Sig-->>W: HMAC-SHA256(event_hash, active key), key_id
    W->>DB: INSERT audit_events (…, sequence n+1, previous_hash h(n), event_hash, key_id, signature)
    W->>DB: UPDATE audit_chain_heads SET last_sequence = n+1, last_hash = event_hash
    W-->>App: AuditEvent (immutable model) · dispatches AuditEventRecorded
    App->>M: business change in the same transaction
    M->>DB: COMMIT (on deadlock / serialization failure: retry with back-off)
    Note over DB: triggers refuse UPDATE/DELETE on audit_events and audit_anchors
```

## Anchoring and verification

```mermaid
flowchart TB
    subgraph anchor["audit:anchor (every few minutes)"]
        direction LR
        h["read head:<br/>last_sequence, last_hash"] --> s["sign head hash<br/>with active key"] --> st["store Anchor in every store,<br/>external first"]
        st --> S3[("Object storage<br/>Object Lock")]
        st --> ADB[("audit_anchors<br/>(database)")]
    end

    subgraph verify["audit:verify (daily) · security:doctor tail check (each run) · Audit::verify()"]
        direction TB
        e1["for every event in sequence order"] --> c1{"sequence = previous + 1?"}
        c1 -- no --> v1["sequence_gap /<br/>missing_predecessor"]
        c1 -- yes --> c2{"previous_hash = hash of<br/>the previous event?"}
        c2 -- no --> v2["previous_hash_mismatch"]
        c2 -- yes --> c3{"recomputed hash =<br/>stored event_hash?"}
        c3 -- no --> v3["event_hash_mismatch"]
        c3 -- yes --> c4{"HMAC with key_id valid?"}
        c4 -- no --> v4["signature_invalid /<br/>signature_key_unknown /<br/>signature_missing"]
        c4 -- yes --> e1
        e1 --> hd{"head row = last event?"}
        hd -- no --> v5["head_mismatch"]
        hd -- yes --> an{"newest anchor = chain at that<br/>sequence, and not ahead?"}
        an -- no --> v6["anchor_mismatch /<br/>anchor_ahead"]
        an -- yes --> ok["intact — run recorded in<br/>audit_verification_runs"]
        v1 & v2 & v3 & v4 & v5 & v6 --> alert["report with violations<br/>exit 1 · ChainViolationDetected"]
    end

    S3 -. "preferred source<br/>(first readable store)" .-> an
    ADB -. fallback .-> an
```

## In words

**Writing.** Every event is appended to one chain (per tenant, or `global`).
The writer locks the chain's head row, so concurrent writers to the same
tenant queue up instead of forking the chain; a deadlock or serialization
failure is retried by `Audit::transaction()` with jittered back-off. The event's
hash covers everything that describes it — including the previous hash, which
is what makes it a chain — and is signed with a key the database never sees.
Triggers make the rows append-only at the database level.

**Anchoring** copies the head (sequence and hash, signed) to the anchor stores.
The external store is the one that matters: an attacker who can rewrite the
database can rewrite the `audit_anchors` table too, but not an object under
Object Lock.

**Verification** replays the chain: continuity, links, recomputed hashes,
signatures, then the head and the newest anchor. Each violation reason points
at a different kind of manipulation — a changed row, a deleted range, a
rewrite without the key, a truncation, a consistent rewrite before the last
anchor. Every run is recorded; the doctor's `verification_recency` check tells
you when the last intact run was.

## What the picture does not say

- **Detectable, not impossible.** Someone with the database *and* the signing
  key *and* the ability to delete the external anchors can rewrite history.
  That is the trust boundary: keep those three apart (see
  [Trust boundaries](trust-boundaries.md)).
- **`recorded_at` is outside the hash** (a server timestamp set after
  hashing); everything else you see on an event is inside it.
- Metadata is sanitised before hashing, so the hash covers the redacted form.
  The original values are never stored.
- Verification of a very long chain is chunked (`audit.verification.chunk_size`)
  and can be resumed from a sequence; it is not an all-or-nothing pass.

Related: [Auditing guide](../guides/auditing.md) · [Incident response](../operations/incident-response.md) ·
[Hash vectors](../../../packages/audit/tests/Fixtures/hash-vectors.json)
