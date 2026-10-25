<!-- source: docs/en/diagrams/audit-write-and-verification.md sha256: 6a7efed426655d800c81c4659f0491d96b7f435b51662a42c082dc885cb31fc6 -->
# Audit-skrivväg och verifiering

*Svensk utgåva — den [engelska versionen](../../en/diagrams/audit-write-and-verification.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

**Läsarfråga:** vad händer mellan `Audit::action()->record()` och en
beständig, signerad kedjepost — och hur bevisar `audit:anchor`,
`audit:verify` och `security:doctor` senare att ingenting har ändrats?

## Skrivvägen

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

## Förankring och verifiering

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

## I ord

**Skrivning.** Varje händelse läggs till i en kedja (per tenant, eller
`global`). Writern låser kedjans huvudrad, så samtidiga writers mot samma
tenant köar upp istället för att förgrena kedjan; ett deadlock eller
serialiseringsfel görs om av `Audit::transaction()` med jittrad backoff.
Händelsens hash täcker allt som beskriver den — inklusive den föregående
hashen, vilket är det som gör den till en kedja — och signeras med en nyckel
som databasen aldrig ser. Triggers gör raderna append-only (endast tillägg)
på databasnivå.

**Förankring** kopierar kedjehuvudet (sekvensnummer och hash, signerat) till
ankarlagren (anchor stores). Den externa lagringen är den som räknas: en
angripare som kan skriva om databasen kan skriva om tabellen
`audit_anchors` också, men inte ett objekt under Object Lock.

**Verifiering** spelar upp kedjan igen: kontinuitet, länkar, omberäknade
hashar, signaturer, sedan kedjehuvudet och det senaste ankaret. Varje
avvikelseorsak pekar på en annan typ av manipulation — en ändrad rad, ett
raderat intervall, en omskrivning utan nyckeln, en trunkering, en konsekvent
omskrivning före det senaste ankaret. Varje körning registreras; doctorns
kontroll `verification_recency` talar om för dig när den senaste intakta
körningen var.

## Vad bilden inte säger

- **Detekterbart, inte omöjligt.** Den som har databasen *och*
  signeringsnyckeln *och* möjligheten att radera de externa ankarna kan
  skriva om historiken. Det är förtroendegränsen: håll dessa tre åtskilda
  (se [Förtroendegränser](trust-boundaries.md)).
- **`recorded_at` ligger utanför hashen** (en server-tidsstämpel som sätts
  efter hashningen); allt annat du ser på en händelse ligger innanför den.
- Metadata saneras innan hashningen, så hashen täcker den redigerade
  (redacted) formen. De ursprungliga värdena lagras aldrig.
- Verifiering av en mycket lång kedja delas upp i block
  (`audit.verification.chunk_size`) och kan återupptas från ett
  sekvensnummer; det är inte en allt-eller-inget-körning.

Relaterat: [Audit-guide](../guides/auditing.md) ·
[Incidenthantering](../operations/incident-response.md) ·
[Hash-vektorer](../../../packages/audit/tests/Fixtures/hash-vectors.json)
