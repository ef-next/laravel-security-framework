<!-- source: docs/en/reference/artisan-commands.md sha256: a1ebce3024e2a9db2c24048dd5c66c0435148b2da63cf07e59bf6cf13c651252 -->
# Artisan-Befehle

*Deutsche Ausgabe — die [englische Fassung](../../en/reference/artisan-commands.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Fünf Befehle; vier aus dem Audit-Paket, einer aus dem Tenant-Paket. Alle geben
bei Erfolg `0` zurück und bei einem Fehler `1`; `tenant:run` gibt den
Exit-Code des Befehls zurück, den es umschließt.

## `security:doctor`

```
security:doctor [--strict] [--json] [--no-verify]
```

Prüft, ob diese Installation weiterhin die im README versprochenen Garantien
einhält. Führen Sie es nach jedem Deployment aus und binden Sie es ins
Monitoring ein.

| Option | Wirkung |
| --- | --- |
| `--strict` | Das High-Security-Profil / Deployment-Gate: Warnungen zählen als Fehlschläge, und die Prüfung `anchor_stores` schlägt fehl, sofern nicht `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` gesetzt ist. |
| `--json` | Maschinenlesbarer Bericht: `{healthy, strict, counts: {ok, warn, fail, skip}, checks: [{id, status, title, detail, hint}]}`. `healthy` ist das Urteil unter dem Modus, nach dem der Lauf bewertet wurde. |
| `--no-verify` | Überspringt die Live-Verifikation der letzten `audit.doctor.tail_events` Ereignisse pro Kette (schneller, kein Durchlauf der Datenbank). |

Exit-Code `1`, wenn eine Prüfung `FAIL` ist, oder mit `--strict`, wenn eine
Prüfung `WARN` ist; andernfalls `0`.

| Check-ID | Schlägt fehl, wenn | Warnt, wenn |
| --- | --- | --- |
| `environment` | — | Produktion mit `APP_DEBUG=true`; Produktion mit `AUDIT_FAIL_FAST=false` |
| `app_key` | `APP_KEY` leer | — |
| `signing_key` | Schlüsselbund leer oder ungültig | — |
| `database_driver` | Audit-Connection ist SQLite oder nicht pgsql/mysql/mariadb; Connection nicht erreichbar | — |
| `append_only_config` | `audit.enforce_append_only` ist false | — |
| `append_only_triggers` | Ein Trigger fehlt auf `audit_events` oder `audit_anchors`; nicht unterstützter Treiber | — |
| `anchor_stores` | Kein lesbarer Store; ein Store wirft eine Exception; externer Anker erforderlich, aber nicht erfüllt oder nicht lesbar; `--strict`, während die Anforderung aus ist | nur der Datenbank-Store ist lesbar |
| `separate_connection` | — | Audit-Connection weicht von der Anwendungs-Connection ab |
| `anchor_freshness` | Köpfe nicht lesbar; eine Kette länger als `max_anchor_age_minutes` unverankert; externer Anker erforderlich, aber keiner vorhanden | — (skip: keine Ketten) |
| `verification_recency` | Der letzte vollständige Lauf einer Kette fand Verstöße | kein intakter vollständiger Lauf innerhalb von `max_verification_age_hours` (skip: keine Ketten) |
| `tail_verification` | Der Tail einer Kette verifiziert nicht | — (skip: `--no-verify` oder keine Ketten) |
| `middleware_priority` | `ResolveTenant` ist nicht vor `SubstituteBindings` eingeordnet | — |
| `tenant_cache` | — | Produktion mit einem `array`/`null` Tenant-Cache-Store |

Jede Nicht-OK-Zeile trägt einen Hinweis, der die Behebung benennt. Jede
Tail-Prüfung wird in `audit_verification_runs` mit der Quelle
`security:doctor` aufgezeichnet.

## `audit:verify`

```
audit:verify [--chain=*] [--from=1] [--to=] [--json] [--fail-fast]
```

Durchläuft die Hash-Ketten und meldet jeden Verstoß (Hash-Mismatch,
Sequenzlücke, gebrochenes Glied, Signaturproblem, Abweichung bei Kopf oder
Anker, nicht unterstützte Hash-Version, …). Planen Sie es mindestens täglich
ein.

| Option | Wirkung |
| --- | --- |
| `--chain` | Wiederholbar: eine Tenant-ID, `tenant:<id>` oder `global`. Standard: jede Kette, die eine Kopf-Zeile hat. |
| `--from`, `--to` | Sequenzfenster (Standard: vom ersten Ereignis bis zum Kopf). |
| `--json` | Ein Berichtsobjekt pro Kette. |
| `--fail-fast` | Stoppt bei der ersten Kette mit Verstößen. |

Ohne Signaturschlüssel gibt es eine Warnung aus (`hashes are verified,
signatures are not`) und fährt fort. Jeder Lauf wird in
`audit_verification_runs` mit der Quelle `audit:verify` aufgezeichnet; eine
Kette mit Verstößen löst `ChainViolationDetected` aus. Exit-Code `1`, wenn
eine Kette Verstöße hat.

Ausgabe pro Kette: `tenant:<id> intact — <n> events, signatures: valid,
anchor: #<seq>` oder `tenant:<id> VIOLATIONS`, gefolgt von einer Zeile pro
Verstoß (`<reason> at sequence <n> {details}`).

## `audit:anchor`

```
audit:anchor [--chain=*] [--force]
```

Speichert den aktuellen Kopf (letzte Sequenz und letzter Hash) jeder Kette in
dem/den konfigurierten Anker-Store(s), signiert mit dem aktiven Schlüssel.
Planen Sie es alle paar Minuten ein. Ketten, deren Kopf sich seit dem letzten
Anker nicht bewegt hat, werden als `unchanged` gemeldet, sofern nicht
`--force` angegeben wird. Die Fußzeile nennt den Store (`filesystem+database`
bei einer Kombination) und die Anzahl der geschriebenen Anker.

Der Exit-Code ist immer `0`; ein Store, in den nicht geschrieben werden kann
(nicht erreichbare Disk), löst eine Exception aus, die Artisan in einen Exit
ungleich null umwandelt.

## `audit:key:generate`

```
audit:key:generate [--id=k1]
```

Gibt einen frischen 32-Byte-HMAC-Schlüssel in dem Format aus, das
`AUDIT_SIGNING_KEYS` erwartet, sowie die passende Zeile für
`AUDIT_SIGNING_ACTIVE_KEY`. Schlüssel-IDs müssen `[A-Za-z0-9_.-]{1,32}`
entsprechen; eine ungültige ID beendet mit `1`. Kein Datenbankzugriff. Hängen
Sie bei einer Rotation den neuen Eintrag an die bestehende Liste an und
verschieben Sie den aktiven Schlüssel.

## `tenant:run`

```
tenant:run {tenant} {artisan} [--allow-inactive]
```

Führt einen anderen Artisan-Befehl innerhalb eines Tenant-Kontexts aus, z. B.
`php artisan tenant:run acme "reports:generate --month=2026-09"`. Der Tenant
wird als ID, Public ID, Global Object ID oder Slug angegeben. Ein
suspendierter oder archivierter Tenant wird abgewiesen (Exit `1`), sofern
nicht `--allow-inactive` gesetzt ist; ein unbekannter Tenant beendet mit `1`.
Andernfalls ist der Exit-Code der des inneren Befehls; der vorherige Kontext
wird danach wiederhergestellt.
