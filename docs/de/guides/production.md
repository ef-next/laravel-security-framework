<!-- source: docs/en/guides/production.md sha256: 8f9787574c71baea2e5e47ccb5aa3ff1d41773fd81af358a5926e69d2a9158e8 -->
# Produktionsleitfaden

*Deutsche Ausgabe — die [englische Fassung](../../en/guides/production.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Die vollständige Produktionseinrichtung, zum Kopieren: Schlüssel, Datenbank,
Anker auf Objektspeicher, Scheduler, Monitoring und `security:doctor --strict`
als Deployment-Gate. Nichts davon benötigt eine veröffentlichte
Konfigurationsdatei; alles sind Umgebungsvariablen und die beiden gezeigten
PHP-Blöcke.

Lesen Sie dies zusammen mit der [Integrations-Checkliste](../integration-checklist.md)
(abhaken) und dem [Bedrohungsmodell](../threat-model.md) (wissen, was bleibt).

## Der Zielzustand

| Garantie | Produktionsbedingung | Wie Sie es nachweisen |
| --- | --- | --- |
| Audit-Datensätze können nicht unbemerkt verändert werden | PostgreSQL / MySQL / MariaDB; append-only-Trigger installiert; Signaturschlüssel in einem Secret-Manager; Anker auf Objektspeicher mit Object Lock, erzwungen durch `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` | `security:doctor --strict` Exit 0 |
| Ketten werden fortlaufend verifiziert und verankert | Scheduler führt `audit:anchor` alle paar Minuten und `audit:verify` täglich aus | Doctor prüft `anchor_freshness`, `verification_recency` |
| Jemand erfährt davon | `ChainViolationDetected` und Doctor-Fehler erreichen eine Person | ein Alarmtest |
| Tenants bleiben unter Last isoliert | Tenant-Cache auf redis/memcached/database, nicht `array` | Doctor-Check `tenant_cache` |

## 1. Secrets

```bash
php artisan audit:key:generate --id=k1
```

Legen Sie die beiden Zeilen in Ihren **Secret-Manager**, nicht in eine
`.env`-Datei auf dem Host, und niemals in denselben Vault-Pfad wie die
Datenbank-Zugangsdaten — ein Angreifer auf Datenbankebene, der zusätzlich den
Schlüssel besitzt, kann die Historie konsistent umschreiben. Injizieren Sie
sie beim Deployment als `AUDIT_SIGNING_KEYS` und `AUDIT_SIGNING_ACTIVE_KEY`.
Optional können Sie dem Pseudonymisierungs-Pepper ein eigenes Secret geben
(`AUDIT_PSEUDONYM_KEY`, 32+ zufällige Bytes); andernfalls wird er aus
`APP_KEY` abgeleitet.

## 2. Datenbank

- PostgreSQL 14+, MySQL 8.0/8.4 oder MariaDB 10.11+. SQLite verweigert den
  Start in Produktion.
- Der **Migrations**-Benutzer benötigt `CREATE TRIGGER`; bei MySQL/MariaDB mit
  aktiviertem Binary Logging zusätzlich `SUPER` oder
  `log_bin_trust_function_creators=1`. Führen Sie Migrationen mit diesem
  Benutzer aus; der Anwendungsbenutzer benötigt es nicht.
- Optionale Rechtetrennung: `AUDIT_DB_CONNECTION=audit`, das auf eine
  Verbindung zeigt, deren Benutzer nur `SELECT, INSERT` auf `audit_events`,
  `audit_anchors`, `audit_verification_runs` sowie `SELECT, INSERT, UPDATE`
  auf `audit_chain_heads` hat. Sie verlieren dadurch die Atomarität zwischen
  Business- und Audit-Schreibvorgängen (der Doctor weist darauf hin);
  entscheiden Sie das bewusst.
- Halten Sie den Datenbankbenutzer der Anwendung ohne `DROP TRIGGER`-/`ALTER`-
  Rechte auf den Audit-Tabellen. Die Trigger sind die erste Schutzschicht,
  nicht die einzige.

## 3. Anker auf Objektspeicher

1. Ein Bucket mit **Object Lock** im Compliance-Modus und einer
   Aufbewahrungsfrist, die mindestens so lang ist wie Ihre
   Audit-Aufbewahrung, sowie **eigenen Zugangsdaten**, die der Datenbank-Host
   nicht besitzt. Der `filesystem`-Store braucht drei Berechtigungen und darf
   eine vierte nicht haben: er *schreibt* ein Objekt pro Anker (`PutObject`),
   *listet* das Präfix einer Kette auf und *liest* den neuesten Anker
   (`ListBucket` auf dem Präfix, `GetObject`) — so vergleichen Verifier und
   Doctor die Kette mit der externen Kopie — und er löscht nie. Object Lock
   hindert selbst eine gestohlene Credential mit `DeleteObject` daran, ein
   Objekt innerhalb der Aufbewahrungsfrist zu entfernen; die Policy sorgt
   dafür, dass die Anwendung diese Berechtigung gar nicht erst hat:

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       { "Effect": "Allow", "Action": ["s3:PutObject", "s3:GetObject"],
         "Resource": "arn:aws:s3:::audit-anchors-prod/audit-anchors/billing/*" },
       { "Effect": "Allow", "Action": "s3:ListBucket",
         "Resource": "arn:aws:s3:::audit-anchors-prod",
         "Condition": { "StringLike": { "s3:prefix": "audit-anchors/billing/*" } } },
       { "Effect": "Deny",
         "Action": ["s3:DeleteObject", "s3:DeleteObjectVersion", "s3:PutObjectRetention",
                    "s3:PutObjectLegalHold", "s3:BypassGovernanceRetention", "s3:PutBucketObjectLockConfiguration"],
         "Resource": ["arn:aws:s3:::audit-anchors-prod", "arn:aws:s3:::audit-anchors-prod/*"] }
     ]
   }
   ```

   Bucket-Name und Präfix (`AUDIT_ANCHOR_PATH`) anpassen; die Policies anderer
   Anbieter lassen sich eins zu eins übertragen (schreiben, Präfix auflisten,
   lesen; Löschen und Änderungen der Aufbewahrung verweigern). Das Framework
   kann Speicher nicht unveränderlich machen; es kann nur Speicher nutzen, der
   es bereits ist.
2. Ein Disk-Eintrag:

   ```php
   // config/filesystems.php
   'audit-anchors' => [
       'driver' => 's3',
       'key' => env('AUDIT_ANCHOR_AWS_KEY'),
       'secret' => env('AUDIT_ANCHOR_AWS_SECRET'),
       'region' => env('AUDIT_ANCHOR_AWS_REGION'),
       'bucket' => env('AUDIT_ANCHOR_AWS_BUCKET'),
       'throw' => true,
   ],
   ```

3. Die Umgebung:

   ```dotenv
   AUDIT_ANCHOR_STORES=filesystem,database     # external store first; AUDIT_ANCHOR_DISK alone changes nothing
   AUDIT_ANCHOR_DISK=audit-anchors
   AUDIT_ANCHOR_PATH=audit-anchors/billing     # one prefix per application if several share a bucket
   AUDIT_REQUIRE_EXTERNAL_ANCHOR=true          # production refuses to boot without the external store
   ```

Ein nur schreibender `log`-Store (`AUDIT_ANCHOR_STORES=filesystem,log,database`,
`AUDIT_ANCHOR_LOG_CHANNEL=siem`) ist eine gute dritte Kopie, wenn Sie Logs
bereits an eine unveränderliche Senke ausliefern; er ersetzt niemals den
lesbaren externen Store.

## 4. Der Produktionsumgebungsblock

```dotenv
APP_ENV=production
APP_DEBUG=false

SECURITY_SERVICE_NAME=billing
TENANT_USER_KEY_TYPE=unsignedBigInteger
TENANT_CACHE_STORE=redis

AUDIT_SIGNING_KEYS=k1:base64:<from the secret manager>
AUDIT_SIGNING_ACTIVE_KEY=k1
AUDIT_TENANT_POLICY=required
AUDIT_ENFORCE_APPEND_ONLY=true

AUDIT_ANCHOR_STORES=filesystem,database
AUDIT_ANCHOR_DISK=audit-anchors
AUDIT_REQUIRE_EXTERNAL_ANCHOR=true
AUDIT_FAIL_FAST=true
```

`.env.example` im Framework-Repository dokumentiert jede weitere Variable.

## 5. Scheduler

```php
// routes/console.php
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->daily()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
```

`onOneServer()` ist wichtig: Zwei gleichzeitige Anker-Läufe sind harmlos,
aber verschwenderisch; zwei gleichzeitige Verifikationen verdoppeln die
Last. `audit.doctor.max_anchor_age_minutes` (Standard 60) sollte ein
Vielfaches des Anker-Intervalls betragen.

## 6. Das Deployment-Gate

```bash
php artisan migrate --force
php artisan security:doctor --strict        # exit 1 stops the deployment
```

`--strict` ist das High-Security-Profil: Warnungen zählen als Fehler, und
die Prüfung `anchor_stores` schlägt fehl, sofern nicht
`AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` gesetzt ist — ein externer Store, der
nur konfiguriert ist, könnte beim nächsten Deployment entfernt werden, ohne
dass irgendetwas den Start verweigert; die Anforderung muss beim Boot
erzwungen werden. Nehmen Sie den Befehl nach jedem Rollout in die Pipeline
auf und brechen Sie bei einem Exit-Code ungleich null ab. `--json` liefert
für Tooling dasselbe Urteil (`healthy`, `strict`).

Bei einer korrekten Installation zu erwarten:

```
  [OK] Environment .......... APP_ENV=production, debug off, fail-fast on.
  [OK] Signing key .......... 1 key(s) in the ring, active: k1.
  [OK] Database driver ...... Connection "pgsql": pgsql 16.4.
  [OK] Append-only triggers . All 4 triggers present on audit_events and audit_anchors.
  [OK] Anchor stores ........ Configured: filesystem, database (readable: filesystem, database; external: filesystem, required).
  …
  OK / WARN / FAIL / SKIP ... 13 / 0 / 0 / 0
```

## 7. Monitoring

Drei Signale, nach Dringlichkeit geordnet:

| Signal | Quelle | Maßnahme |
| --- | --- | --- |
| `ChainViolationDetected`-Ereignis / `audit:verify` Exit 1 | Verifier | alarmieren; [Incident Response](../operations/incident-response.md) starten |
| `security:doctor` FAIL | Scheduler (`emailOutputOnFailure`) oder Ihr Monitoring über `--json` | vor dem nächsten Deployment beheben; ein FAIL bedeutet, dass eine Garantie aktuell nicht eingehalten wird |
| `security:doctor` WARN | dasselbe | die Behebung einplanen; unter `--strict` lässt ein WARN das Gate bereits scheitern |

```php
Event::listen(ChainViolationDetected::class, fn ($e) => Alert::page('audit-integrity', $e->report->chainKey->toString(), $e->report->toArray()));
```

Nützliche Metriken zum Export: `audit_verification_runs` (letzter intakter
Lauf pro Kette), Anker pro Stunde, `tenant.access_denied`-Ereignisse pro
Tenant (ein Ausschlag ist entweder ein Bug oder ein Angriff). Details in
[operations/monitoring.md](../operations/monitoring.md).

## 8. Hinweise zur Deployment-Topologie

- **Laravel Octane / langlaufende Worker**: unterstützt; der Tenant-Kontext
  wird am Ende jedes Requests/Jobs geleert. Nichts zu konfigurieren.
- **Mehrere Anwendungen, ein Bucket**: unterschiedliche
  `AUDIT_ANCHOR_PATH`-Präfixe und unterschiedliche `SECURITY_SERVICE_NAME`s.
- **Lesereplikate**: die Audit-Verbindung auf den Primärserver richten; der
  Writer benötigt `SELECT … FOR UPDATE`.
- **Blue/Green**: beide Farben dürfen ankern; Anker sind append-only und pro
  Kopf idempotent.
- **Backups**: die Audit-Datenbank und der Anker-Bucket werden *gemeinsam*
  gesichert und wiederhergestellt; eine wiederhergestellte Datenbank ohne
  ihre Anker verifiziert zwar, kann aber nicht beweisen, dass sie vor der
  Wiederherstellung nicht gekürzt wurde.

## 9. Rotation und Änderung

- Schlüsselrotation: [operations/key-rotation.md](../operations/key-rotation.md).
- Anker-Store wechseln: den neuen Store *zuerst* in `AUDIT_ANCHOR_STORES`
  eintragen, `audit:anchor --force` ausführen, verifizieren, dann den alten
  nach Ablauf seiner Aufbewahrungsfrist entfernen. Anker im alten Store
  bleiben für den von ihnen abgedeckten Zeitraum gültiger Nachweis.
- Framework-Upgrades: das CHANGELOG für jede übersprungene Version lesen,
  `migrate`, `security:doctor --strict`. Migrationen sind innerhalb einer
  Hauptversion additiv.

## 10. Go-live-Checkliste (Kurzform)

- [ ] `security:doctor --strict` beendet sich mit 0 in der Produktionsumgebung
- [ ] Schlüssel im Secret-Manager, nicht auf dem Host; Datenbank-Zugangsdaten
      anderswo
- [ ] Object Lock geprüft, indem versucht wird, ein Anker-Objekt **mit einem
      administrativen Principal zu löschen, der `DeleteObject` hat** (das muss
      mit einem Object-Lock-Fehler scheitern — mit der Credential der Anwendung
      scheitert der Versuch schon an IAM und beweist nichts über die Sperre)
- [ ] Scheduler-Einträge deployed und beim Laufen beobachtet (`audit_anchors`
      wächst)
- [ ] `ChainViolationDetected` an das Alerting angebunden und einmal mit
      einem fingierten Report getestet
- [ ] Anwendungsbenutzer ohne `DROP TRIGGER` auf den Audit-Tabellen
- [ ] die vollständige [Integrations-Checkliste](../integration-checklist.md)
      abgehakt
