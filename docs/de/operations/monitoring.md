<!-- source: docs/en/operations/monitoring.md sha256: 1f47218dcaed14fe1ccea7ad534ac218861f595ca8bde0cbc6078822769b3b18 -->
# Monitoring

*Deutsche Ausgabe — die [englische Fassung](../../en/operations/monitoring.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Worauf Sie achten sollten, sobald das Framework in Produktion läuft, woher jedes
Signal kommt und was eine Veränderung darin bedeutet. Das Framework sendet drei
Arten von Signalen: den **Doctor** (Konfiguration und Zustand), den
**Verifier** (Integrität) und **Ereignisse** (Verhalten). Nichts davon
benötigt eine veröffentlichte Konfigurationsdatei.

## 1. Der Doctor als Status-Endpunkt

`php artisan security:doctor --json` gibt einen Bericht aus:

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

- `healthy` ist der Wert, an dem Sie sich orientieren: `false`, wenn ein Check
  fehlschlägt, und mit `--strict` auch, wenn ein Check eine Warnung meldet.
  Der Exit-Code des Prozesses sagt dasselbe (0 / 1).
- `checks[].id` ist stabil; alarmieren Sie anhand der IDs, nicht anhand von
  Titeln oder Formulierungen.
- `--no-verify` überspringt die Live-Tail-Verifikation (der einzige Check, der
  Ereigniszeilen liest) und macht den Lauf günstig genug für alle paar
  Minuten.

Die Check-IDs und was ein Ergebnis ungleich OK bedeutet:

| ID | Bedeutung bei WARN | Bedeutung bei FAIL |
| --- | --- | --- |
| `environment` | `APP_DEBUG` aktiv, oder `AUDIT_FAIL_FAST` in der Produktion deaktiviert | — |
| `app_key` | — | kein `APP_KEY` |
| `signing_key` | — | kein Schlüsselbund, fehlerhaft, aktiver Schlüssel fehlt oder ist kürzer als 32 Byte |
| `database_driver` | — | SQLite in der Produktion, oder nicht unterstützter Treiber |
| `append_only_config` | — | `AUDIT_ENFORCE_APPEND_ONLY=false` |
| `append_only_triggers` | — | die Trigger sind auf `audit_events` / `audit_anchors` nicht installiert (entfernt, oder Migration übersprungen) |
| `anchor_stores` | nur der Datenbank-Store ist lesbar | kein lesbarer Store; ein externer Store, der nicht antwortet; unter `--strict`: `AUDIT_REQUIRE_EXTERNAL_ANCHOR` nicht gesetzt |
| `separate_connection` | Audit-Tabellen teilen sich die Verbindung der Anwendung | — |
| `anchor_freshness` | — | eine Kette hat Ereignisse, die älter sind als `audit.doctor.max_anchor_age_minutes` (Standard 60) und von keinem Anker abgedeckt werden; oder der externe Store kann nicht gelesen werden |
| `verification_recency` | keine intakte vollständige Verifikation innerhalb von `max_verification_age_hours` (Standard 24) | ein aufgezeichneter Lauf hat Verstöße gefunden |
| `tail_verification` | — | die letzten `tail_events` (Standard 200) einer Kette verifizieren nicht |
| `middleware_priority` | — | die Tenant-Middleware ist nicht vor `SubstituteBindings` eingeordnet |
| `tenant_cache` | Tenant-Lookups verwenden in der Produktion den `array`-Store | — |

Zwei Möglichkeiten, ihn aus dem Monitoring heraus auszuführen:

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

Legen Sie den Doctor nicht ohne Authentifizierung über HTTP offen; seine
Ausgabe nennt Ihre Konfiguration.

## 2. Integrität: der Verifier

`audit:verify` ist die maßgebliche Quelle dafür, dass „das Log intakt ist“.
Planen Sie ihn mindestens täglich ein; stündlich ist für die meisten
Installationen günstig, weil die Verifikation in Blöcken (Chunks) streamt.

```php
Schedule::command('audit:verify')->hourly()->onOneServer()->emailOutputOnFailure('security@example.test');
```

Zwei Ausgaben, beide anschließen:

- **Exit-Code 1**, sobald eine Kette einen Verstoß aufweist (`--fail-fast`
  stoppt beim ersten). `emailOutputOnFailure` oder der Failure-Hook Ihres
  Schedulers ist das Minimum.
- **`ChainViolationDetected`** wird pro betroffener Kette mit dem
  vollständigen `VerificationReport` ausgelöst. Das ist das Signal, bei dem
  Sie einen Alarm (Page) auslösen sollten:

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

Ein Verstoß ist niemals Rauschen. Es gibt im Normalbetrieb keinen
„erwarteten“ Verstoß — ein Falsch-Positiv bedeutet, dass mit dem Deployment
etwas nicht stimmt (ein aus dem Ring entfernter Schlüssel, eine
Wiederherstellung ohne ihre Anker), was ebenfalls einen Alarm wert ist.
Folgen Sie danach [Incident Response](incident-response.md).

## 3. Verhalten: Ereignisse und Audit-Aktionen

Das Framework löst Laravel-Ereignisse aus, die Sie abonnieren können; das
Audit-Paket schreibt die Tenant-Ereignisse bereits in das Log
(`audit.record_tenant_events`).

| Ereignis | Wann | Worauf zu achten ist |
| --- | --- | --- |
| `Tenant\Events\TenantAccessDenied` | eine Prüfung von Mitgliedschaft, Rolle oder Token hat eine Anfrage abgelehnt | ein Anstieg pro Tenant (Mandant) oder pro Benutzer: ein Client-Bug, ein widerrufener Benutzer, der weiterhin aufruft, oder Enumeration |
| `Tenant\Events\TenantScopeBypassed` | `Tenancy::withoutTenant()` wurde betreten | jedes Auftreten außerhalb der von Ihnen erwarteten Codepfade (Reports, Wartungsbefehle) |
| `Tenant\Events\MembershipChanged` | Gewährung, Rollenänderung, Statusänderung, Widerruf | Änderungen außerhalb Ihrer Admin-Abläufe |
| `Tenant\Events\TenantSwitched` | der Session-Tenant hat sich geändert | ungewöhnliche Häufigkeit pro Session |
| `Tenant\Events\TenantContextActivated` | ein Tenant wurde aktiv | nur als Volumenmetrik |
| `Audit\Events\AuditEventRecorded` | nach jedem erfolgreichen Audit-Schreibvorgang | Schreibrate pro Kette (ein Abfall auf null bei einem stark genutzten Tenant bedeutet einen defekten Writer oder eine verstummte Anwendung) |
| `Audit\Events\ChainViolationDetected` | der Verifier hat Verstöße gefunden | siehe oben |

Dieselbe Information steht im Log als Audit-Aktionen (`tenant.access_denied`,
`tenant.scope_bypassed`, `tenant.membership.<change>`, `tenant.switched`),
sodass eine Abfrage über `audit_events` den Verlauf ohne eine
Metrik-Pipeline liefert:

```sql
-- denied requests per tenant, last 24 h
SELECT tenant_id, COUNT(*) FROM audit_events
WHERE action = 'tenant.access_denied' AND occurred_at > NOW() - INTERVAL '24 hours'
GROUP BY tenant_id ORDER BY 2 DESC;
```

## 4. Metriken, die sich zu exportieren lohnen

| Metrik | Quelle | Alarm wenn |
| --- | --- | --- |
| Alter des neuesten Ankers pro Kette | `audit_anchors` (`MAX(anchored_at)` pro `chain_key`) oder der `anchor_freshness`-Check des Doctors | älter als das Doppelte des Anker-Intervalls |
| Alter der letzten intakten vollständigen Verifikation pro Kette | `audit_verification_runs WHERE intact AND source = 'audit:verify'` | älter als das Verify-Intervall + Puffer |
| Verifikationsläufe mit `intact = false` | `audit_verification_runs` | jedes Vorkommen |
| Audit-Schreibvorgänge pro Minute pro Kette | `AuditEventRecorded` oder `COUNT(*)` über `occurred_at` | null bei einer Kette, die normalerweise stark genutzt wird |
| `tenant.access_denied` pro Tenant / pro Akteur | Audit-Log | über einer Baseline pro Tenant |
| `tenant.scope_bypassed` | Audit-Log | jedes Auftreten mit einem unerwarteten `reason` |
| Doctor `healthy` | `security:doctor --json` | `false` |

Anker und Verifikationsläufe sind gewöhnliche Tabellen; jeder Exporter, der
SQL ausführen kann, kann sie lesen. Die Heads-Tabelle
(`audit_chain_heads.updated_at`) verrät Ihnen, wann sich eine Kette zuletzt
bewegt hat.

## 5. Logs

- Der Audit-Writer protokolliert erfolgreiche Schreibvorgänge nicht (die
  Zeile ist der Nachweis).
- `InsecureConfiguration` beim Boot, `InvalidSigningKey` und verweigerte
  Konfiguration sind Exceptions, keine Log-Zeilen; sie erreichen Ihren
  Exception-Handler und stoppen den Prozess.
- Der Anker-Store `log` schreibt pro Anker eine strukturierte Zeile in den
  von Ihnen konfigurierten Kanal (`AUDIT_ANCHOR_LOG_CHANNEL`). Wird dieser
  Kanal an ein SIEM oder einen unveränderlichen Log-Dienst weitergeleitet,
  erhalten Sie eine dritte, unabhängige Kopie jedes Kettenkopfs — nur
  schreibend, kombinieren Sie ihn also mit einem lesbaren Store.
- Bei `audit.enforce_append_only=false` schreibt die Migration eine Warnung
  ins Log; der Doctor meldet dies bei jedem Lauf als FAIL.

## 6. Was Monitoring Ihnen nicht sagen kann

- Dass der Anker-Bucket *unveränderlich* ist. Der Doctor prüft, dass der
  Store antwortet und dass die Anker aktuell sind; Object Lock ist eine
  Bucket-Eigenschaft, die Sie einmal verifizieren (versuchen Sie, einen
  Anker zu löschen) und über die Konfigurationsprüfungen Ihres
  Cloud-Anbieters auditieren.
- Dass sich Schlüssel und Datenbank in unterschiedlichen Händen befinden.
  Das ist eine Eigenschaft des Deployments; siehe
  [Vertrauensgrenzen](../diagrams/trust-boundaries.md).
- Dass Ihr eigener Code tenant-gebundene Tabellen nur über Eloquent abfragt.
  Das ist Sache des Code-Reviews; die zusammengesetzten Fremdschlüssel sind
  die letzte Verteidigungslinie.

Verwandte Themen: [Produktionsleitfaden](../guides/production.md) ·
[Incident Response](incident-response.md) ·
[Referenz: Events](../reference/events.md) ·
[Artisan-Befehle](../reference/artisan-commands.md)
