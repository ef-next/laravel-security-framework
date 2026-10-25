<!-- source: docs/en/diagrams/audit-write-and-verification.md sha256: 6a7efed426655d800c81c4659f0491d96b7f435b51662a42c082dc885cb31fc6 -->
# Audit-Schreibpfad und Verifikation

*Deutsche Ausgabe — die [englische Fassung](../../en/diagrams/audit-write-and-verification.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

**Leserfrage:** Was passiert zwischen `Audit::action()->record()` und einem
persistenten, signierten Ketteneintrag — und wie beweisen `audit:anchor`,
`audit:verify` und `security:doctor` später, dass nichts verändert wurde?

## Der Schreibpfad

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

## Ankerung und Verifikation

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

## In Worten

**Schreiben.** Jedes Ereignis wird an eine Kette angehängt (pro Tenant
(Mandant), oder `global`). Der Writer sperrt die Zeile des Kettenkopfs, sodass
gleichzeitige Writer für denselben Tenant sich einreihen, statt die Kette zu
verzweigen; ein Deadlock oder ein Serialisierungsfehler wird von
`Audit::transaction()` mit zufälligem Backoff erneut versucht. Der Hash des
Ereignisses deckt alles ab, was es beschreibt — einschließlich des vorherigen
Hashes, der es erst zu einer Kette macht — und wird mit einem Schlüssel
signiert, den die Datenbank nie zu sehen bekommt. Trigger machen die Zeilen
auf Datenbankebene append-only (nur anfügend).

**Ankerung** kopiert den Kettenkopf (Sequenz und Hash, signiert) in die
Anker-Stores. Der externe Store ist der, auf den es ankommt: Ein Angreifer,
der die Datenbank umschreiben kann, kann auch die Tabelle `audit_anchors`
umschreiben — aber kein Objekt unter Object Lock.

**Verifikation** spielt die Kette erneut durch: Kontinuität, Verknüpfungen,
neu berechnete Hashes, Signaturen, dann den Kopf und den neuesten Anker. Jeder
Verstoßgrund weist auf eine andere Art von Manipulation hin — eine veränderte
Zeile, einen gelöschten Bereich, ein Umschreiben ohne den Schlüssel, eine
Kürzung, ein konsistentes Umschreiben vor dem letzten Anker. Jeder Lauf wird
aufgezeichnet; die `verification_recency`-Prüfung des Doctors sagt Ihnen, wann
der letzte intakte Lauf war.

## Was das Bild nicht sagt

- **Erkennbar, nicht unmöglich.** Wer die Datenbank *und* den
  Signaturschlüssel *und* die Möglichkeit hat, die externen Anker zu löschen,
  kann die Historie umschreiben. Das ist die Vertrauensgrenze: Halten Sie
  diese drei getrennt (siehe [Vertrauensgrenzen](trust-boundaries.md)).
- **`recorded_at` liegt außerhalb des Hashes** (ein Server-Zeitstempel, der
  nach dem Hashing gesetzt wird); alles andere, was Sie an einem Ereignis
  sehen, liegt innerhalb.
- Metadaten werden vor dem Hashing bereinigt, sodass der Hash die geschwärzte
  Form abdeckt. Die ursprünglichen Werte werden nie gespeichert.
- Die Verifikation einer sehr langen Kette wird in Abschnitte unterteilt
  (`audit.verification.chunk_size`) und kann ab einer Sequenz fortgesetzt
  werden; sie ist kein Alles-oder-Nichts-Durchlauf.

Siehe auch: [Auditing-Leitfaden](../guides/auditing.md) ·
[Incident Response](../operations/incident-response.md) ·
[Hash-Vektoren](../../../packages/audit/tests/Fixtures/hash-vectors.json)
