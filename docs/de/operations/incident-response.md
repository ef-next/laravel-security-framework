<!-- source: docs/en/operations/incident-response.md sha256: f608b6da99ca434f160ee6f3cbf5bbac829c80ea0470f41bef77a064a12a6586 -->
# Incident Response

*Deutsche Ausgabe — die [englische Fassung](../../en/operations/incident-response.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Was zu tun ist, wenn der Verifier einen Verstoß meldet, der Doctor meldet,
dass eine Garantie nicht mehr gilt, oder Sie Grund zu der Annahme haben,
dass das Audit-Log oder eine Tenant-Grenze angegriffen wurde. Die Aufgabe
des Frameworks bei einem Incident ist es, Ihnen zu sagen, **was sich
geändert hat, wo, und was noch vertrauenswürdig ist**; diese Seite
beschreibt das Vorgehen dazu.

Lesen Sie dies einmal, bevor Sie es brauchen. Die ersten drei Schritte
handeln davon, *keine Beweise zu zerstören*.

## 0. Zuerst sichern

Vor jeder Behebung, jedem Neustart, jeder Migration oder einem „ich schaue
nur kurz in der Produktion nach“:

1. **Erstellen Sie einen Snapshot der Audit-Datenbank** (oder der gesamten
   Datenbank, falls sich die Audit-Tabellen diese teilen): ein konsistenter
   Dump oder ein Snapshot auf Storage-Ebene. Notieren Sie den Zeitpunkt.
2. **Frieren Sie den Anker-Store ein**: Rotieren, bereinigen oder
   rekonfigurieren Sie den Bucket nicht. Falls Ihr Anbieter einen Legal Hold
   anbietet, legen Sie ihn an. Exportieren Sie die Auflistung
   (`AUDIT_ANCHOR_PATH/<chain>/*.json`) mit Objektversionen und
   Zeitstempeln.
3. **Kopieren Sie die Anwendungs- und Webserver-Logs** für das betreffende
   Zeitfenster, einschließlich der Request-IDs — `audit_events.request_id`
   und `correlation_id` verknüpfen die beiden.
4. **Rotieren Sie den Signaturschlüssel noch nicht**, es sei denn, Sie
   wissen bereits, dass er durchgesickert ist (siehe dann
   [Schlüsselrotation → Kompromittierter Schlüssel](key-rotation.md#kompromittierter-schlüssel)
   und kehren Sie zurück). Die Signaturen unter dem aktuellen Schlüssel sind
   Teil der Beweise.
5. Arbeiten Sie, wo möglich, mit dem Snapshot. Jeder Befehl unten ist nur
   lesend, aber je weniger Hände während der Triage an der Produktion sind,
   desto besser.

## 1. Sich das vollständige Bild verschaffen

Von einem Host aus, dem Sie vertrauen — idealerweise einem, der die
Datenbank und den Anker-Store lesen kann, aber kein Anwendungsserver ist:

```bash
php artisan audit:verify --json > verify-$(date -u +%Y%m%dT%H%M%SZ).json
php artisan security:doctor --json --strict > doctor-$(date -u +%Y%m%dT%H%M%SZ).json
```

`audit:verify` ohne `--chain` durchläuft jede Kette ab Sequenznummer 1; bei
einer großen Installation dauert das eine Weile, was in Ordnung ist — Sie
wollen den vollständigen Bericht, keine Stichprobe. Bewahren Sie die
JSON-Dateien auf; sie sind die Aufzeichnung dessen, was das System zu diesem
Zeitpunkt gesagt hat.

Der Bericht pro Kette:

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

## 2. Die Verstöße lesen

Jeder Verstoß hat einen `reason`, eine `sequence` und (meist) eine
`event_id`. Der `reason` sagt Ihnen, mit welcher Art von Manipulation Sie es
zu tun haben:

| Reason | Was es bedeutet | Wo als Nächstes nachsehen |
| --- | --- | --- |
| `event_hash_mismatch` | die Zeile an dieser Sequenznummer erzeugt ihren gespeicherten Hash nicht mehr: **eine Zeile wurde bearbeitet** | diese Zeile; `recorded_at` im Vergleich zur Datenbank-Änderungsverfolgung; wer `UPDATE`-Rechte auf `audit_events` hatte — die Trigger hätten das eigentlich verweigern müssen, prüfen Sie deshalb auch `append_only_triggers` im Doctor-Bericht |
| `previous_hash_mismatch` | der Verweis dieser Zeile zeigt nicht auf die tatsächliche vorherige Zeile: **eine Zeile davor wurde ersetzt oder entfernt** | die Zeilen zwischen der letzten guten Sequenznummer und dieser |
| `sequence_gap` / `missing_predecessor` | eine Sequenznummer fehlt: **Zeilen wurden gelöscht** | wie oben; eine Lücke ganz am Ende ist ein Abschneiden (siehe `head_mismatch`) |
| `signature_invalid` | der Hash ist konsistent, die Signatur jedoch nicht: **ohne den Schlüssel umgeschrieben** (oder mit einem anderen Schlüssel unter derselben ID) | der Angreifer hatte Datenbankzugriff, aber nicht den Schlüssel — der günstige Fall; die Zeilen sind trotzdem falsch |
| `signature_key_unknown` | signiert mit einer Key-ID, die nicht im Ring ist | hat jemand einen Schlüssel aus `AUDIT_SIGNING_KEYS` entfernt? ein Konfigurationsvorfall, nicht zwangsläufig ein Angriff; den Schlüssel wiederherstellen und erneut verifizieren |
| `signature_missing` | keine Signatur bei einem Ereignis, obwohl der Ring konfiguriert ist | Ereignisse, die geschrieben wurden, während der Ring leer war (Fehlkonfigurationsfenster), oder ein Umschreiben, das die Signatur entfernt hat |
| `head_mismatch` | `audit_chain_heads` stimmt nicht mit dem letzten Ereignis überein: **die Kette wurde abgeschnitten oder der Kettenkopf wurde manipuliert** | vergleichen Sie den Kettenkopf mit dem neuesten externen Anker; der Anker verrät Ihnen die tatsächliche Länge |
| `anchor_ahead` | ein Anker beansprucht eine Sequenznummer, die die Kette nicht mehr hat: **Abschneiden nach dem Anker** | alles zwischen dem aktuellen Ende der Kette und der Sequenznummer des Ankers ist verschwunden; der Anker beweist, dass es existierte |
| `anchor_mismatch` | die Kette hat an der Sequenznummer des Ankers einen anderen Hash als vom Anker verzeichnet: **konsistent umgeschrieben — mit dem Schlüssel — vor dem letzten Anker** | der Signaturschlüssel ist kompromittiert, oder ein Angreifer auf Datenbankebene besaß ihn; der externe Anker ist Ihr einziger Beweis. Behandeln Sie den Schlüssel als kompromittiert |
| `unsupported_hash_version` | ein Ereignis beansprucht eine Hash-Version, die dieses Framework nicht kennt | ein zurückgestuftes Deployment, das neuere Zeilen liest, oder eine manipulierte Zeile |

Zwei Eigenschaften des Berichts helfen bei der Eingrenzung:

- **`signatures: "valid"` bei gleichzeitigen Verstößen** bedeutet, dass der
  Angreifer den Schlüssel nicht hatte. Alles, was er geändert hat, ist als
  Hash- oder Signaturverstoß sichtbar; nichts ist verborgen.
- **`anchor` ist der neueste Anker, den der Verifier gefunden hat**
  (zuerst der externe Store, die Datenbank als Fallback). Alles bei oder
  unterhalb von `anchor.sequence`, das weiterhin verifiziert, ist durch eine
  unabhängige Kopie bestätigt; alles darüber ist nur durch die Kette und die
  Signaturen bestätigt.

## 3. Den letzten vertrauenswürdigen Punkt finden

Die externen Anker sind die Vertrauensgrenze. Für die betroffene Kette:

```bash
# list the anchors in the external store (path = AUDIT_ANCHOR_PATH, ':' in the chain key becomes '_')
aws s3 ls s3://<bucket>/audit-anchors/tenant_0192a7c3-…/
```

Jedes Objekt ist ein JSON-Anker (`sequence`, `event_hash`, `key_id`,
`signature`, `anchored_at`). Verifizieren Sie dann die Kette **bis zu**
jedem Anker, beginnend mit dem neuesten, bis einer besteht:

```bash
php artisan audit:verify --chain=tenant:0192a7c3-… --to=48190 --json
php artisan audit:verify --chain=tenant:0192a7c3-… --to=47500 --json
```

Der höchste Anker, dessen Bereich intakt verifiziert und dessen
`event_hash` dem Hash der Kette an dieser Sequenznummer entspricht, ist der
**letzte vertrauenswürdige Punkt**. Alles bis dahin ist durch eine
unabhängige Kopie belegt; alles danach ist das, worum es beim Incident geht.
Stimmen die *Datenbank*-Anker nicht mit den *externen* überein, wurden die
Datenbank-Anker manipuliert — notieren Sie das, es verrät Ihnen, was der
Angreifer erreichen konnte.

Existieren keine externen Anker (reines Datenbank-Setup), liegt der letzte
vertrauenswürdige Punkt dort, wo die Signaturen aufhören, konsistent zu
sein — und stand der Schlüssel auch dem Angreifer zur Verfügung, gibt es
überhaupt keinen beweisbaren Punkt. Genau das zu verhindern ist der Zweck
von `security:doctor --strict` und `AUDIT_REQUIRE_EXTERNAL_ANCHOR`; halten
Sie das im Incident-Bericht fest.

## 4. Die Tragweite eingrenzen

Sobald der letzte vertrauenswürdige Punkt bekannt ist:

1. **Welche Tenants (Mandanten)**: jede Kette im Bericht mit
   `intact: false`. Ein Verstoß in der Kette eines Tenants betrifft nicht
   die eines anderen; die Kette `global` umfasst tenant-lose Ereignisse
   (Mitgliedschaftsänderungen, Scope-Bypässe, Ablehnungen für unbekannte
   Tenants).
2. **Welches Zeitfenster**: von `occurred_at` des ersten Verstoßes bis zum
   Zeitpunkt der Entdeckung. `audit_verification_runs` verrät Ihnen, wann
   die Kette zuletzt als intakt verifiziert wurde — die Manipulation
   geschah nach diesem Lauf.
3. **Wer es gewesen sein könnte**: Die Verstöße grenzen den Zugriff des
   Angreifers ein:
   - Hash-Verstöße, gültige Signaturen → Datenbank-Schreibzugriff, kein
     Schlüssel;
   - `anchor_mismatch` oder anderweitig konsistente Umschreibungen →
     Datenbank *und* Schlüssel;
   - entfernte Trigger (`append_only_triggers` FAIL) → DDL-Rechte;
   - Datenbank-Anker umgeschrieben, externe jedoch intakt → kein Zugriff auf
     die Löschberechtigung des Buckets (Object Lock hält).
4. **Was das Log über sich selbst aussagt**: Fragen Sie die Kette rund um
   das Zeitfenster ab — die Ereignisse *vor* der Manipulation zeigen oft
   den Akteur:

```sql
SELECT sequence, occurred_at, actor_type, actor_id, action, resource_type, resource_id, request_id
FROM audit_events
WHERE chain_key = 'tenant:0192a7c3-…' AND sequence BETWEEN 47990 AND 48010
ORDER BY sequence;
```

`tenant.scope_bypassed`- und `tenant.membership.*`-Ereignisse in der Kette
`global` sowie Spitzen bei `tenant.access_denied` sind die, die Sie zuerst
lesen sollten.

## 5. Eindämmen

In dieser Reihenfolge, jeder Schritt erst, nachdem der vorherige
abgeschlossen und dokumentiert ist:

1. **Entziehen Sie den Zugriff, der es ermöglicht hat**:
   Datenbank-Zugangsdaten, die durchgesickert oder überprivilegiert waren,
   das Konto, das DDL ausgeführt hat, das Deployment, das
   `AUDIT_ENFORCE_APPEND_ONLY` deaktiviert hat. Führen Sie die
   Trigger-Installation der Audit-Migration erneut aus, falls die Trigger
   entfernt wurden (`php artisan migrate:refresh --path=…` ist **nicht**
   das richtige Werkzeug dafür — es löscht Tabellen; erstellen Sie die
   Trigger stattdessen mit den Anweisungen neu, die die Migration für
   Ihren Treiber verwendet, oder stellen Sie aus dem Snapshot wieder her
   und spielen Sie nach).
2. **Rotieren Sie den Signaturschlüssel**, wenn die Analyse aus Schritt 4
   zeigt, dass der Angreifer ihn hatte, oder wenn Sie es nicht ausschließen
   können ([Schlüsselrotation](key-rotation.md#kompromittierter-schlüssel)).
3. **Rotieren Sie `AUDIT_PSEUDONYM_KEY`** nur, wenn er durchgesickert ist;
   er schützt nicht die Integrität, sondern Identifier in den Metadaten.
4. **Sperren Sie betroffene Tenants**, wenn die Kompromittierung ein
   Problem der Tenant-Grenze und kein Problem des Logs ist:
   `status = suspended` auf der Tenant-Zeile macht jede Anfrage an ihn
   sofort zu einem 403 (der Cache invalidiert beim Speichern); bereits für
   ihn eingereihte Jobs laufen weiterhin, sofern Sie nicht auch die Queue
   pausieren.
5. **„Reparieren“ Sie die Kette nicht.** Es gibt keine unterstützte
   Operation, die `audit_events` umschreibt, und Sie sollten auch keine
   wollen: Die Verstöße sind die Aufzeichnung des Incidents. Die Kette
   setzt sich nach dem manipulierten Bereich fort;
   `audit:verify --from=<erste Sequenznummer nach dem Incident>` verifiziert
   den Rest.

## 6. Dokumentieren

Der Incident-Bericht sollte mindestens Folgendes enthalten:

- die Ausgaben von `audit:verify --json` und
  `security:doctor --json --strict` aus Schritt 1, mit Zeitstempeln;
- den letzten vertrauenswürdigen Anker pro betroffener Kette
  (Sequenznummer, Hash, Store, Objektversion);
- die Verstoßtabelle mit Ihrer Interpretation je `reason`;
- die Tragweite (Tenants, Zeitfenster, Zugriffsebene);
- was entzogen, rotiert und wiederhergestellt wurde, und wann;
- die Sequenznummer, ab der die Kette wieder als sauber gilt.

Schreiben Sie eine Zusammenfassung davon in das Audit-Log selbst, aus der
Kette `global`, damit der nächste Verifier-Lauf die Bestätigung mitträgt:

```php
Audit::action('security.incident.recorded')
    ->global()
    ->on('incident', 'INC-2026-014')
    ->with(['chains' => ['tenant:0192a7c3-…'], 'last_trusted_sequence' => 48190, 'reason' => 'event_hash_mismatch'])
    ->bySystem('incident-response')
    ->record();
```

## 7. Nach dem Incident

- Bewahren Sie den Snapshot, den Anker-Export und die JSON-Berichte für
  Ihre Aufbewahrungsfrist auf; genau danach wird ein Auditor fragen.
- War der letzte vertrauenswürdige Punkt älter als Ihr Anker-Intervall, hat
  der Scheduler vor dem Incident seine Aufgabe nicht erfüllt; beheben Sie
  vor allem anderen das Monitoring ([Monitoring](monitoring.md)).
- War der Incident möglich, weil Schlüssel und Datenbank vom selben Ort aus
  erreichbar waren, trennen Sie diese jetzt
  ([Vertrauensgrenzen](../diagrams/trust-boundaries.md)).
- War es ein Problem der Tenant-Grenze, fügen Sie den fehlenden Test zur
  Test-Suite der Anwendung hinzu, bevor der Fix ausgeliefert wird;
  `TenantContextMissing` und der Cross-Tenant-Leak-Test in
  [AGENTS.md](../../../AGENTS.md) sind das Minimum.

## Wenn es kein Angriff ist

Die meisten ersten Verstöße sind betrieblicher Natur:

| Symptom | Übliche Ursache | Fix |
| --- | --- | --- |
| `signature_key_unknown` bei alten Ereignissen nach einem Deployment | ein Schlüssel wurde zu früh aus `AUDIT_SIGNING_KEYS` entfernt | ihn zurücksetzen; siehe [Schlüssel ausmustern](key-rotation.md#schlüssel-ausmustern) |
| `anchor_ahead` nach einer Wiederherstellung | die Datenbank wurde aus einem Backup wiederhergestellt, das älter ist als der neueste Anker | der Anker hat recht: Die Wiederherstellung hat Ereignisse verloren. Halten Sie die Lücke fest; die Kette setzt sich vom wiederhergestellten Kettenkopf fort. Stellen Sie Datenbank und Anker-Store das nächste Mal *gemeinsam* wieder her |
| `head_mismatch` direkt nach einem Absturz | die Transaktion des Writers hat das Ereignis committet, aber der Prozess starb vor dem Update des Kettenkopfs | das Framework aktualisiert beides in einer Transaktion, das sollte also nicht vorkommen; falls doch, eröffnen Sie mit dem Bericht ein Issue und bewahren Sie den Snapshot auf |
| `signature_missing` für einen Bereich | Ereignisse wurden geschrieben, während der Ring leer war (ein Deployment ohne das Secret) | der Bereich ist gehasht und verkettet, aber unsigniert; notieren Sie es, beheben Sie das Deployment, und lassen Sie den `signing_key`-Check des Doctors das von nun an überwachen |
| `unsupported_hash_version` | eine ältere Framework-Version liest Zeilen, die von einer neueren geschrieben wurden | vorwärts aktualisieren (roll forward) |

Auch dann gilt: Führen Sie die vollständige Verifikation aus, bewahren Sie
den Bericht auf, und lassen Sie den Verstoß stehen. Das Log soll sich
erinnern.

Verwandte Themen: [Monitoring](monitoring.md) ·
[Schlüsselrotation](key-rotation.md) ·
[Audit-Schreibpfad und Verifikation](../diagrams/audit-write-and-verification.md) ·
[Troubleshooting](../guides/troubleshooting.md)
