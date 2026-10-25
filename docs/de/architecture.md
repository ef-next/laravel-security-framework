<!-- source: docs/en/architecture.md sha256: 08a1a85bfd031a7376725ee2f87cb641ee7423240763c5bcc9dff18b30e1c383 -->
# Architektur

*Deutsche Ausgabe — die [englische Fassung](../en/architecture.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Drei Pakete, eine Abhängigkeitsrichtung: `addressing <- tenant <- audit`. Der
Architektur-Test in `tests/Arch` lässt den Build fehlschlagen, wenn diese
Richtung verletzt wird oder wenn ein Paket auf Klassen der Host-Anwendung
verweist.

```
Efnext\Security\Addressing        identifiers, generation, Eloquent traits
Efnext\Security\Tenant            context, resolution, locating, membership, HTTP, queue, console
Efnext\Security\Audit             records, hashing, signing, sanitizing, writing, verification, anchoring
```

## Tenant: Request-Lifecycle

```
request ─▶ ResolveTenant ──▶ TenantResolver (chain) ──▶ Candidate(identifier, source)
                │                                                │
                │                                                ▼
                │                                    TenantLocator (cache -> db)
                │                                                │
                │                        null ─▶ 404     inactive ─▶ 403
                │                                                │
                ▼                                                ▼
        TenantContextManager.activate(TenantContext) ◀───── TenantRecord
                │
                ▼
        EnsureTenantMember ──▶ MembershipChecker ──▶ Membership(status, role, expiry)
                │                        │
                │             denial ─▶ TenantAccessDenied event ─▶ 403 / 401
                ▼
        controller: Invoice::all()  ──▶ TenantScope reads the manager
                                            │
                                            ├─ context      ─▶ WHERE tenant_id = ?
                                            ├─ bypass block ─▶ no filter (evented)
                                            └─ nothing      ─▶ TenantContextMissing (LogicException)
                │
                ▼
        RequestHandled / Octane *Terminated ─▶ manager.clear()
```

Wichtige Entscheidungen:

- **Der Scope wirft eine Exception, statt alles zurückzugeben.**
  `TenantContextMissing` erweitert `LogicException`, wird nie als 4xx
  gerendert und nennt in seiner Meldung die drei legitimen Lösungen.
- **Resolution und Lookup sind getrennt.** Resolver finden nur einen
  String. Der Locator macht daraus einen bestehenden Tenant-Datensatz mit
  einem Status. Fehlerhaft geformte Identifier erreichen nie eine Abfrage
  (UUID/ULID-Prüfung für `id`, `PublicId`-Parser für `public_id`, strikte
  Regex für `slug`).
- **Der Kontext ist ein Value Object mit einer `source`.** Bei einer
  forensischen Analyse lässt sich ein über den Header aufgelöster Tenant
  (Mandant) von einem über die Queue weitergereichten unterscheiden.
- **`withoutTenant()` erfordert einen Grund** und löst
  `TenantScopeBypassed` mit Datei:Zeile des Aufrufers aus; das Audit-Paket
  zeichnet es auf.
- **Die Queue-Weitergabe nutzt die Job-Payload**, nicht
  Job-Eigenschaften, sodass jeder Job den Tenant des Dispatchers ohne
  Codeänderungen erhält. `TenantAware`-Jobs können ihn überschreiben. Der
  Manager unterbricht und setzt den Zustand pro Job fort, sodass sich der
  Sync-Treiber wie ein echter Worker verhält.
- **Aufräumen an Request-Grenzen** (`RequestHandled`, Octanes
  Terminated-Events) und an Job-Grenzen, sodass ein lange laufender Worker
  keinen Tenant in die nächste Arbeitseinheit durchsickern lassen kann.

## Audit: Schreibpfad

```
Audit::action('domain.verified')->on($domain)->with([...])->record()
   │
   ▼
AuditEntry (validated)  ─▶  tenant from entry or context (policy: required|nullable)
   │                        actor from entry or ActorResolver (user / anonymous / system)
   │                        request context (request id, correlation id, ip, user agent)
   │                        metadata -> MetadataSanitizer -> CanonicalJson::roundTrip
   ▼
INSERT OR IGNORE audit_chain_heads(chain_key)   ← auto-commit, only if the head is missing (see below)
transaction on the audit connection
   ├─ SELECT … FOR UPDATE audit_chain_heads WHERE chain_key = ?    ← serialises writers
   ├─ sequence = last_sequence + 1, previous_hash = last_hash
   ├─ event_hash = sha256("efnext-audit-v2\n" + canonical JSON of the whole record)
   ├─ signature  = hmac-sha256(event_hash, active key)              ← key never in the database
   ├─ INSERT audit_events (UNIQUE chain_key+sequence as backstop)
   └─ UPDATE audit_chain_heads SET last_sequence, last_hash
```

Was gehasht wird (Hash-Version 2): id, chain key, tenant id, sequence,
previous hash, occurred_at in Mikrosekunden, actor type/id/label, action,
resource type/id, outcome, metadata, request id, correlation id, ip, user
agent. Nicht gehasht: `recorded_at` (informative Serverzeit; Reihenfolge und
Manipulationssicherheit beruhen auf `sequence` und `occurred_at_us`),
`hash_version`, `event_hash`, `key_id`, `signature`. Eine Änderung an
`recorded_at` wird vom Verifier daher nicht erkannt; die
append-only-Trigger (nur anfügend) sind für diese Spalte der einzige
Schutz.

Warum `Audit::transaction()` existiert: Innerhalb der Transaktion eines
Aufrufers wird die Sperre auf den Kettenkopf an der Stelle des
Audit-Schreibvorgangs genommen, nach den eigenen Lesevorgängen des
Aufrufers. Unter Lastkonkurrenz kann jede Datenbank die Transaktion
daraufhin abbrechen (InnoDB-Deadlock, PostgreSQL-Serialisierungsfehler,
MariaDB ≥ 11.6 Snapshot-Isolation-Fehler 1020). `Audit::transaction()`
wiederholt die gesamte Geschäftstransaktion bei Nebenläufigkeitsfehlern mit
zufällig gestreutem Backoff; es ist der empfohlene Wrapper für
Transaktionen, die Audit-Schreibvorgänge enthalten.

Warum die Head-Zeile außerhalb der Transaktion erstellt wird: Bei InnoDB
sorgt `INSERT … IGNORE`, gefolgt von `SELECT … FOR UPDATE` auf derselben
neuen Zeile innerhalb einer Transaktion, dafür, dass jeder gleichzeitige
Erst-Writer eine Shared Lock hält und dann eine Exclusive Lock anfordert;
InnoDB beendet alle bis auf einen (Deadlock). Wird die Zeile in einer
eigenen Auto-Commit-Anweisung erstellt, nimmt `FOR UPDATE` eine einfache
Record Lock, und die Writer reihen sich ein. Läuft `record()` innerhalb der
Transaktion eines Aufrufers, tritt der Head-Insert dieser zwangsläufig bei;
rufen Sie `Audit::prepareChain($tenantId)` auf, wenn Sie einen Tenant
anlegen, falls Ihre ersten Audit-Ereignisse unter Nebenläufigkeit innerhalb
größerer Transaktionen geschrieben werden. Weiterhin auftretende Deadlocks
werden mit Jitter wiederholt (`audit.write.attempts`), wenn der Writer die
Transaktion besitzt, und andernfalls an die Transaktion des Aufrufers
weitergereicht.

Warum eine Heads-Tabelle: `MAX(sequence)+1` ist unter nebenläufigen
Transaktionen auf jeder Isolationsstufe unterhalb von serialisierbar ein
Wettlauf. Eine gesperrte Zeile pro Kette ist günstig, portabel über
PostgreSQL/MySQL/MariaDB hinweg, und gibt dem Verifier eine zweite Meinung
darüber, wo die Kette enden sollte.

Warum Sequenznummern statt Zeitstempel: Zwei Anwendungsserver mit ein paar
Millisekunden Uhrenabweichung dürfen keine „Manipulations“-Alarme erzeugen.
Zeitstempel werden erfasst und gehasht; sie werden nicht für die Reihenfolge
verwendet.

## Audit: Verifikation

`HashChainVerifier::verify(chain, from, to)` durchläuft die Kette in
Sequenzreihenfolge in Blöcken (Chunks) und meldet:

| Check | Erkennt |
| --- | --- |
| fortlaufende Sequenz | gelöschte oder eingeschleuste Zeilen |
| `previous_hash`-Verkettung | neu angeordnete oder ersetzte Zeilen |
| neu berechneter `event_hash` | jede Änderung an einer gehashten Spalte (alle Spalten außer `recorded_at`, `hash_version`, `key_id`, `signature`) |
| HMAC-Signatur | konsistente Umschreibungen durch jemanden ohne den Schlüssel |
| Übereinstimmung des Kettenkopfs | ein abgeschnittenes Ende |
| Übereinstimmung mit dem neuesten Anker | ein abgeschnittenes Ende oder eine zusammen mit dem Kettenkopf durchgeführte Umschreibung |

`ChainViolationDetected` wird für jede Kette mit Befunden ausgelöst;
`audit:verify` beendet sich mit einem Exit-Code ungleich null.

## Audit: Ankern

`audit:anchor` erstellt einen Snapshot von `(chain, sequence, hash,
signature, time)` in einem oder mehreren Stores: `database` (bequem, nicht
unabhängig), `filesystem` (jede Laravel-Disk; Object Lock verwenden), `log`
(nur schreibend, für SIEMs). Der Verifier liest den neuesten Anker aus dem
ersten Store, der einen hat; konfigurieren Sie zuerst den externen Store.
Eine reine `log`-Konfiguration gibt dem Verifier nichts zum Vergleichen:
Kombinieren Sie sie mit `filesystem` (oder `database`), damit Anker
zurückgelesen werden können.

## Addressing

`PublicId` und `GlobalObjectId` sind Value Objects, die entweder kanonisch
oder ungültig sind: nur Kleinschreibung, kein Trimmen, strikte Regexes.
`HasPublicId` weist beim Erstellen zu, verweigert Änderungen, hält die
Spalte aus der Mass Assignment heraus und sorgt dafür, dass das
Route-Model-Binding bei fehlerhaft geformten Werten die Datenbank
überspringt. `$table->publicId()` erstellt die eindeutige Spalte.

## Operative Selbstprüfungen

`security:doctor` (`Efnext\Security\Audit\Doctor\SecurityDoctor`) führt
unabhängige Checks aus und meldet OK/WARN/FAIL/SKIP: Konfiguration
(Schlüssel, Treiber, append-only), installierte Trigger
(`AppendOnly::missingTriggers()`), Anker-Stores, Anker-Aktualität und
Verifikations-Aktualität pro Kette (aus `audit_verification_runs`,
geschrieben von `audit:verify` und dem Doctor), eine Live-Tail-Verifikation,
die Priorität der Tenant-Middleware und den Cache-Store. `ProductionGuard`
hält die Teilmenge, die nur die Konfiguration betrifft, und wird beim Boot
unter `APP_ENV=production` erzwungen (`InsecureConfiguration`).

## Schema-Helfer

`$table->tenantId()` fügt die Tenant-Spalte hinzu, ihren FK auf `tenants`,
einen Index und `UNIQUE(tenant_id, id)`.
`$table->tenantForeignId('invoice_id', 'invoices')` (sowie die
uuid-/ulid-Varianten) fügt die Spalte plus einen zusammengesetzten
Fremdschlüssel `(tenant_id, invoice_id) → invoices(tenant_id, id)` hinzu,
sodass die Datenbank selbst eine Beziehung über Tenant-Grenzen hinweg
verweigert — auf PostgreSQL, MySQL, MariaDB und SQLite. Die Helfer liefern
eine `TenantForeignKeyDefinition` zurück, die `cascadeOnDelete()`,
`restrictOnDelete()` und `noActionOnDelete()` akzeptiert, bei
`nullOnDelete()`/`nullOnUpdate()` (und `SET DEFAULT`) jedoch eine Exception
wirft: Ein zusammengesetzter Schlüssel kann nicht nur eine seiner Spalten
leeren, sodass `SET NULL` auch die NOT-NULL-Tenant-Spalte leeren müsste —
MySQL/MariaDB verweigern die Erstellung eines solchen Constraints (Errno
150), PostgreSQL/SQLite erstellen ihn und verweigern dann das Löschen des
Elternteils. Eine optionale Beziehung ist
`tenantForeignId('invoice_id', 'invoices', nullable: true)` mit Cascade
oder Restrict; das Trennen erfolgt als Update im Anwendungscode.

## Konfigurationsoberfläche

Alles befindet sich in `config/tenant.php`, `config/audit.php` und
`config/addressing.php`; jede Option ist direkt dort dokumentiert. Bindings
sind Singletons, die einmal pro Prozess aus der Konfiguration aufgelöst
werden; Tests, die die Konfiguration ändern, lösen das betroffene Binding
explizit erneut auf.
