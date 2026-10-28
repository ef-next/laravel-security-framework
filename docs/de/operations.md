<!-- source: docs/en/operations.md sha256: b0555d27c1ccbf864468e97cbabf0832226b017a968b113dc4d9eea438cb693d -->
# Betrieb

*Deutsche Ausgabe — die [englische Fassung](../en/operations.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Die Referenz des Betreibers für eine laufende Installation. Die
Alltagsabläufe haben eigene Seiten:

| Runbook | Wann Sie es brauchen |
| --- | --- |
| [Monitoring](operations/monitoring.md) | worauf zu achten ist, was welches Signal bedeutet, Doctor-JSON, Metriken |
| [Schlüsselrotation](operations/key-rotation.md) | planmäßige Rotation, einen Schlüssel ausmustern, kompromittierter Schlüssel, `APP_KEY` |
| [Incident Response](operations/incident-response.md) | ein Verifier-Verstoß oder ein Doctor-FAIL: sichern, den Umfang bestimmen, eindämmen, protokollieren |

Der [Produktionsleitfaden](guides/production.md) ist der geordnete Weg von
einer funktionierenden Entwicklungsumgebung zu einem gehärteten Deployment;
diese Seite ist die Referenz, zu der Sie zurückkehren.

## Doctor

```bash
php artisan security:doctor            # human-readable, exit 1 on FAIL
php artisan security:doctor --strict   # high-security profile / deployment gate: exit 1 on WARN as well, and AUDIT_REQUIRE_EXTERNAL_ANCHOR must be on
php artisan security:doctor --json     # monitoring
```

Prüft: Umgebung (Debug, Fail-fast), `APP_KEY`, Signaturschlüssel,
Datenbank-Treiber und Erreichbarkeit, append-only-Konfiguration **und
installierte Trigger**, Anker-Stores (lesbar, extern), separate
Audit-Verbindung, Anker-Aktualität pro Kette
(`audit.doctor.max_anchor_age_minutes`), letzte intakte vollständige
Verifikation pro Kette (`audit.doctor.max_verification_age_hours`), eine
Live-Verifikation der letzten `audit.doctor.tail_events` Ereignisse jeder
Kette, Tenant-Middleware-Priorität, Tenant-Cache-Store. Nach jedem
Deployment und aus dem Monitoring heraus ausführen. `--strict` ist das
Deployment-Gate des High-Security-Profils: Warnungen zählen als Fehler, und
die Prüfung `anchor_stores` schlägt fehl, sofern nicht
`AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` gesetzt ist — ein externer Store, der
nur konfiguriert ist, reicht nicht aus, weil nichts ein späteres Deployment
daran hindern würde, ihn wieder zu entfernen; die Anforderung muss durch den
Boot-Guard erzwungen werden. Eine Pipeline, die nach jedem Rollout
`security:doctor --strict` ausführt, kann daher keine Produktion ausliefern,
bei der ein erzwungener externer Anker fehlt, ein Debug-Flag eingeschaltet
geblieben ist oder ein Signaturschlüssel fehlt. Ohne `--strict` installiert
und läuft das Framework mit reinen Datenbank-Ankern (ein `WARN`), was der
bewusste Standard für eine Bibliothek ist.

`audit:verify` und der Doctor protokollieren ihre Läufe in
`audit_verification_runs` (Kette, Bereich, Ergebnis, Zeit). Diese Tabelle
ist Buchhaltung für die Aktualitätsprüfung, kein Nachweis.

Unter `APP_ENV=production` verweigert der Provider selbst den Start, wenn
die Konfiguration keinen Signaturschlüssel hat, SQLite verwendet, die
append-only-Durchsetzung deaktiviert oder keinen lesbaren Anker-Store hat
(`InsecureConfiguration`). `AUDIT_FAIL_FAST=false` deaktiviert diese
Schutzfunktion für eine bewusste, vorübergehende Ausnahme; der Doctor meldet
das Problem weiterhin.

`AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` ist das strikte Produktionsprofil: Der
Guard verlangt dann zusätzlich mindestens einen lesbaren Anker-Store
außerhalb der Audit-Datenbank (`filesystem` auf einem Object-Lock-Bucket,
oder eine eigene `AnchorStore`-Klasse), prüft, ob er instanziiert werden
kann (die Disk existiert, die Klasse implementiert den Contract), und der
Doctor meldet ein reines Datenbank-Setup als `FAIL`. Schalten Sie es ein,
sobald der Bucket existiert; ohne es sind reine Datenbank-Anker ein `WARN`,
weil ein Angreifer, der die Kette umschreiben kann, auch daneben
gespeicherte Anker umschreiben kann.

## Scheduling

```php
// routes/console.php or the scheduler
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->hourly()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
```

`audit:verify` beendet sich mit 1, wenn eine Kette Verstöße aufweist, und
löst pro betroffener Kette `ChainViolationDetected` aus. Binden Sie das
Ereignis an Ihr Alerting an.

Für große Installationen inkrementell verifizieren: die zuletzt verifizierte
Sequenznummer pro Kette merken und `Audit::verify($chain, $lastVerified + 1)`
aufrufen; der Verifier prüft die Vorgängerverknüpfung und den Kettenkopf.

## Schlüssel

- Erzeugen: `php artisan audit:key:generate --id=k2` (gibt aus, schreibt
  nicht).
- Rotieren: den neuen Schlüssel an `AUDIT_SIGNING_KEYS` anhängen, deployen,
  dann `AUDIT_SIGNING_ACTIVE_KEY` darauf setzen und erneut deployen. Alte
  Schlüssel bleiben gelistet, damit alte Signaturen verifizieren; einen
  Schlüssel erst entfernen, nachdem jedes damit signierte Ereignis die
  Aufbewahrungsfrist verlassen hat.
- Schlüssel in einem Secret-Manager speichern — niemals in der Datenbank,
  niemals in einer Config-Tabelle, niemals im selben Vault-Pfad wie die
  Datenbank-Zugangsdaten.
- Ablauf, Checkliste und der Fall eines kompromittierten Schlüssels:
  [Schlüsselrotation](operations/key-rotation.md).

## Anker

- `database`-Store: bequem, append-only durch Trigger, aber nicht
  unabhängig von der Datenbank.
- `filesystem`-Store: auf einen S3-(oder kompatiblen) Bucket mit Object Lock
  im Compliance-Modus und einer Aufbewahrungsfrist mindestens so lang wie
  Ihre Audit-Aufbewahrung richten.
- `log`-Store: nur schreibend, eine strukturierte Zeile pro Anker; den
  Channel an ein SIEM oder einen unveränderlichen Log-Dienst weiterleiten.
  Da er nicht zurückgelesen werden kann, ignoriert ihn der Verifier; immer
  mit einem lesbaren Store kombinieren (`filesystem` zuerst, `database` als
  Fallback).

Den externen Store zuerst in `AUDIT_ANCHOR_STORES` auflisten; der Verifier
bevorzugt den ersten Store, der einen Anker hat. Mit
`AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` startet die Produktion ohne einen
solchen Store nicht (siehe Doctor oben).

### Produktionsrezept: Anker auf Objektspeicher

Alles Folgende ist zum Kopieren gedacht; nichts benötigt eine veröffentlichte
Konfigurationsdatei.

1. **Ein Bucket mit Object Lock** (Compliance-Modus, Aufbewahrungsfrist
   mindestens so lang wie Ihre Audit-Aufbewahrung) und **eigenen
   Zugangsdaten** — niemals solche, die die Audit-Datenbank erreichen können —
   mit genau dem, was der Store braucht: put, Präfix auflisten, get (Verifier
   und Doctor lesen den neuesten Anker zurück); kein Löschen, keine Änderungen
   der Aufbewahrung. Die minimale Policy steht im
   [Produktionsleitfaden](guides/production.md#3-anker-auf-objektspeicher).
   Das Framework kann Speicher nicht unveränderlich machen; es kann nur
   Speicher nutzen, der es bereits ist.
2. **Ein Disk-Eintrag dafür** in `config/filesystems.php`:

   ```php
   'audit-anchors' => [
       'driver' => 's3',
       'key' => env('AUDIT_ANCHOR_AWS_KEY'),
       'secret' => env('AUDIT_ANCHOR_AWS_SECRET'),
       'region' => env('AUDIT_ANCHOR_AWS_REGION'),
       'bucket' => env('AUDIT_ANCHOR_AWS_BUCKET'),
       'throw' => true,
   ],
   ```

3. **Der `.env`-Block** (siehe `.env.example` für jede weitere Variable):

   ```dotenv
   AUDIT_SIGNING_KEYS=k1:base64:<value from the secret manager>
   AUDIT_SIGNING_ACTIVE_KEY=k1
   AUDIT_ANCHOR_STORES=filesystem,database
   AUDIT_ANCHOR_DISK=audit-anchors
   AUDIT_REQUIRE_EXTERNAL_ANCHOR=true
   AUDIT_FAIL_FAST=true
   ```

   `AUDIT_ANCHOR_DISK` allein ändert nichts: Der `filesystem`-Store muss in
   `AUDIT_ANCHOR_STORES` stehen, externer Store zuerst.
4. **Scheduler**: `audit:anchor` alle paar Minuten, `audit:verify`
   mindestens täglich (siehe Scheduling oben).
5. **Deployment-Gate**: `php artisan security:doctor --strict` nach dem
   Rollout; Exit 0 bedeutet, der Schlüssel ist im Ring, der externe Store
   antwortet, die Anforderung wird beim Boot erzwungen und die Ketten
   verifizieren. Exit 1 stoppt das Deployment.

## Datenbank

- PostgreSQL: nichts Besonderes. Trigger verwenden `plpgsql`.
- MySQL 8 / MariaDB 11: das Erstellen von Triggern benötigt
  `CREATE TRIGGER`; bei aktiviertem Binary Logging zusätzlich `SUPER` oder
  `log_bin_trust_function_creators=1`. Die Audit-Migration mit einem
  privilegierten Benutzer ausführen oder die Server-Variable setzen.
- SQLite: unterstützt für Entwicklung und Tests; nicht für
  Produktions-Audit-Logs verwenden (keine Zeilensperren über Verbindungen
  hinweg in der Weise, die der Writer erwartet).
- Separate Verbindung: `AUDIT_DB_CONNECTION` setzen, um Audit-Tabellen in
  eine eigene Datenbank/einen eigenen Benutzer zu schreiben. Die minimalen
  Rechte sind `SELECT`, `INSERT` auf `audit_events`, `audit_anchors` und
  `audit_verification_runs`, sowie `SELECT`, `INSERT`, `UPDATE` auf
  `audit_chain_heads` (die Kopf-Zeile wird bei jedem Schreibvorgang
  fortgeschrieben; `SELECT … FOR UPDATE` benötigt `UPDATE`). Zusammen mit
  den Triggern verhindert dies, dass der Anwendungsbenutzer jemals ein
  Ereignis ändert oder löscht.
- Separate Verbindung und Atomarität: Ein Audit-Schreibvorgang auf einer
  eigenen Verbindung kann keine Transaktion mit Ihrem Business-Schreibvorgang
  teilen. Wenn der Audit-Datensatz genau dann existieren muss, wenn die
  Business-Änderung existiert, beide auf einer Verbindung halten und in
  einem `Audit::transaction()` bündeln (nächste Punkte); andernfalls
  akzeptieren, dass ein Absturz zwischen beiden das eine ohne das andere
  zurücklässt.
- MySQL/MariaDB und Nebenläufigkeit: Der Writer erzeugt die Kopf-Zeile der
  Kette in einem Auto-Commit-Statement vor seiner Transaktion, damit
  gleichzeitige erste Writer keinen Deadlock erzeugen können. Innerhalb
  Ihrer eigenen Transaktion ist das nicht möglich; wenn Sie die ersten
  Ereignisse neuer Ketten unter Last innerhalb größerer Transaktionen
  schreiben, `Audit::prepareChain($tenantId)` aufrufen (außerhalb der
  Transaktion, z. B. wenn der Tenant angelegt wird).
- Audit-Schreibvorgänge innerhalb von Business-Transaktionen: **Verwenden
  Sie `Audit::transaction()`**, nicht `DB::transaction()`. Der Kettenkopf
  wird zum Zeitpunkt des Audit-Schreibvorgangs gesperrt und bis zum Commit
  Ihrer Transaktion gehalten, sodass gleichzeitige Transaktionen für
  denselben Tenant dort serialisieren. Jede Datenbank kann daraus einen
  wiederholbaren Konflikt machen: InnoDB-Deadlocks, PostgreSQL
  `could not serialize access` unter REPEATABLE READ, und MariaDB 11.6+
  (Standard `innodb_snapshot_isolation=ON`)
  `ER_CHECKREAD 1020 "Record has changed since last read"`, sobald sich die
  Kopf-Zeile nach dem ersten Lesen Ihrer Transaktion geändert hat. Laravel
  wirft diese als `DeadlockException` / `QueryException`;
  `DB::transaction($fn, attempts: n)` wiederholt sofort und kollidiert unter
  anhaltender Last tendenziell erneut. Solche Transaktionen in
  `Audit::transaction(fn () => ..., attempts: 10)` einbetten: Es wiederholt
  die gesamte Transaktion mit zufällig gestreutem Back-off ausschließlich
  bei Nebenläufigkeitsfehlern und führt den Callback einmal aus, wenn
  bereits eine Transaktion offen ist. Da die Closure mehrfach laufen kann,
  darf sie keine E-Mails versenden, keine externen APIs aufrufen oder
  andere nicht-transaktionale Nebenwirkungen erzeugen; solche erst nach der
  Rückkehr einreihen oder idempotent gestalten. Alternativen: die
  Verbindung mit `'isolation_level' => 'READ COMMITTED'` betreiben
  (MySQL/MariaDB; jedes Statement sieht den zuletzt committeten Stand), oder
  den Audit-Schreibvorgang aus der Business-Transaktion herauslösen, wenn
  strikte Atomarität nicht erforderlich ist.

## Qualitäts-Gates

Jedes Release muss die folgenden Gates bestehen; `security:doctor` ist das
einzige, das ein Betreiber ausführt. Die übrigen stehen hier, damit Sie
wissen, was ein grünes Release bedeutet — wie man sie ausführt und
erweitert, ist Maintainer-Dokumentation (`docs/maintainers/testing.md`).

| Gate | Was es beweist |
| --- | --- |
| `composer audit` | keine bekannten Advisories im Dependency-Baum |
| Pint, PHPStan Level 8 | Stil, Typen (einschließlich der Traits im Kontext eines echten Models) |
| Test-Matrix | Verhalten auf SQLite, PostgreSQL, MySQL, MariaDB; PHP 8.3/8.4/8.5 × Laravel 13 als harte Gates, niedrigste und höchste Abhängigkeiten, PostgreSQL 14–17, MySQL 8.0/8.4, MariaDB 10.11–11; Line Coverage ≥ 80 % (PHP 8.5 und Laravel 13 werden in einem separaten Job beobachtet, der fehlschlagen darf) |
| Nebenläufigkeits- und Lasttests | geforkte Writer verzweigen niemals eine Kette, mit und ohne Business-Transaktionen; Dutzende Writer auf zwei Ketten bleiben linear |
| Randomisierte Invarianten | kanonisches JSON, Hashing, Identifier, Tenant-IDs und der Sanitiser gelten für zufällige Eingaben |
| Known-Answer-Vektoren | das Hash-Format ist byteidentisch mit den erfassten Vektoren (Version, Domain-String, gehashte Felder, kanonisches JSON, HMAC); eine Änderung ist eine neue Hash-Version, niemals eine stille Bearbeitung, und `packages/audit/tests/Fixtures/hash-vectors.json` erlaubt es jedem, einen Hash ohne diese Codebasis nachzurechnen |
| Mutation Testing | die Tests bemerken, wenn der Sicherheitskern geändert wird (CI schlägt unterhalb eines Scores von 70 % fehl; der vollständige Report ist ein Build-Artefakt) |

## Performance

- Das Tenant-Lookup wird gecacht (`tenant.cache`, Standard 60 s, negativ
  5 s). Statusänderungen invalidieren den Cache beim Speichern.
- Jeder Audit-Schreibvorgang hält für die Dauer des Inserts eine
  Zeilensperre auf `audit_chain_heads`; Writer auf unterschiedliche Ketten
  blockieren sich gegenseitig nicht. Auf PostgreSQL sind niedrige
  einstellige Millisekunden pro Schreibvorgang zu erwarten. Nur der
  allererste Schreibvorgang einer Kette fügt eine zusätzliche
  Existenzprüfung und ein Insert hinzu; danach ist es ein günstiges
  `SELECT` vor der Transaktion.
- Die Verifikation erfolgt in Chunks (`audit.verification.chunk_size`,
  Standard 1000) und streamt; der Speicherverbrauch bleibt konstant.

## Observability

Ereignisse, auf die Sie lauschen können:

| Ereignis | Wann |
| --- | --- |
| `Tenant\Events\TenantContextActivated` | ein Tenant wurde aktiv (Request, Job, `run()`) |
| `Tenant\Events\TenantAccessDenied` | Mitgliedschafts-, Rollen- oder Token-Prüfung fehlgeschlagen |
| `Tenant\Events\TenantScopeBypassed` | `withoutTenant()` betreten |
| `Tenant\Events\MembershipChanged` | Gewährung / Rollenänderung / Statusänderung / Entzug |
| `Tenant\Events\TenantSwitched` | Session-Tenant geändert |
| `Audit\Events\AuditEventRecorded` | nach jedem erfolgreichen Audit-Schreibvorgang |
| `Audit\Events\ChainViolationDetected` | Verifier hat Verstöße gefunden |

Das Audit-Paket abonniert die ersten fünf und schreibt sie ins Log
(`audit.record_tenant_events`). Ereignisse ohne Tenant (ein
`withoutTenant()` außerhalb jedes Tenant-Kontexts, eine Verweigerung für
einen unbekannten Tenant) landen unabhängig von `audit.tenant_policy` auf
der `global`-Kette; sie wird wie jede andere Kette mit `audit:verify`
verifiziert.

Doctor-JSON, Check-IDs, die exportwürdigen Metriken und die Alerting-Regeln:
[Monitoring](operations/monitoring.md).

## Incident Response

In Kürze: zuerst die Datenbank und den Anker-Store sichern; von einem
vertrauenswürdigen Host `php artisan audit:verify --json` und
`security:doctor --json --strict` ausführen und die Ausgabe aufbewahren;
den `reason` jedes Verstoßes lesen (`event_hash_mismatch` zeigt genau die
veränderten Zeilen; `sequence_gap` und `previous_hash_mismatch` grenzen
Löschungen ein; `head_mismatch`/`anchor_ahead` zeigen eine Kürzung;
`signature_*` zeigt Umschreibungen ohne den Schlüssel; `anchor_mismatch`
zeigt Umschreibungen mit dem Schlüssel, aber vor dem letzten Anker); den
letzten externen Anker finden, zu dem die Kette noch passt — das ist der
letzte vertrauenswürdige Punkt. Der vollständige Ablauf, einschließlich
Eindämmung und was zu dokumentieren ist:
[Incident Response](operations/incident-response.md).
