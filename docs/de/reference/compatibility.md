<!-- source: docs/en/reference/compatibility.md sha256: a91c74d2b5080d54f8ec53c7ed58ec80dbb0d5371ea1f0c30a78b8cf2748f3aa -->
# Kompatibilität

*Deutsche Ausgabe — die [englische Fassung](../../en/reference/compatibility.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

## Support-Matrix

| | Unterstützt — feste CI-Gates |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 13 |
| PostgreSQL | 14, 16, 17 |
| MySQL | 8.0, 8.4 |
| MariaDB | 10.11, 11.4, 11 (aktuellste) |
| SQLite | nur Tests und lokale Entwicklung — Produktion verweigert damit den Start |

„Unterstützt“ bedeutet, dass die Kombination ein erforderlicher Job in den
CI-Gates ist (`.github/workflows/gates.yml`) und ein Release nicht getaggt
wird, solange er rot ist. Experimentelle Kombinationen gibt es nicht: Die
Composer-Constraints lassen nur Laravel 13 zu, und eine neue PHP-, Laravel-
oder Datenbankversion wird unterstützt, indem sie ein Gate wird. PHP-Versionen
über 8.5 schließt Composer nicht aus; sie sind ungetestet, bis sie ein Gate
sind. Eine Anwendung auf Laravel 12 aktualisiert zuerst auf Laravel 13.

Nur das jeweils neueste Minor-Release erhält Fixes (`SECURITY.md`).

## Was jede Datenbank benötigt

| Datenbank | Anforderung | Warum |
| --- | --- | --- |
| PostgreSQL | nichts Besonderes; Trigger verwenden `plpgsql` | Append-Only-Trigger |
| MySQL 8.x | der Migrations-User hat `CREATE TRIGGER`; bei aktiviertem Binary Logging zusätzlich `SUPER` oder die Server-Variable `log_bin_trust_function_creators=1` | InnoDB-Trigger unter Binlog |
| MariaDB 10.11+ | wie MySQL; MariaDB 11.6+ setzt standardmäßig `innodb_snapshot_isolation=ON`, wodurch Kopf-Zeilen-Konflikte zu Fehler 1020 werden — `Audit::transaction()` wiederholt sie | Trigger, Snapshot-Isolation |
| SQLite | `pdo_sqlite`; von der Testsuite verwendet | keine Row Locks über Connections hinweg, auf die sich der Writer verlässt — niemals für produktive Audit-Logs |

Alle drei Produktionsdatenbanken: Die Audit-Tabellen benötigen zeilenweises
`SELECT … FOR UPDATE` (der Writer serialisiert Anfügungen pro Kette auf der
Kopf-Zeile) und Mikrosekunden-Zeitstempel (`datetime(6)` / `timestamp(6)`-Spalten;
von der Migration erstellt).

## PHP-Erweiterungen

`pdo` mit dem von Ihnen verwendeten Treiber, `json`, `mbstring`, `openssl`
(Zufalls-Bytes, HMAC über `hash_hmac`, das zum Core gehört), sowie für die
Testsuite `pcntl` (geforkte Concurrency-Tests) und ein Coverage-Treiber
(`pcov` oder `xdebug`) für Mutation Testing.

## Genutzte Laravel-Features

Middleware-Priority (`Kernel::addToMiddlewarePriorityBefore` / die
Priority-Liste des Routers), Eloquent Global Scopes und Model-Events,
`Queue::createPayloadUsing()` und die Job-Lifecycle-Events, `Storage`-Disks
für den Filesystem-Anker-Store, die `currentAccessToken()`-Abilities von
Sanctum (optional). Laravel Octane wird unterstützt: Der Tenant-Kontext wird
bei jedem `*Terminated`-Ereignis geleert.

## Upgrades

Semantic Versioning für alle Pakete zusammen. Patch: keine Schema- oder
Verhaltensänderung. Minor: nur additive Migrationen. Major: alles, was
ändert, was gehasht wird (`HashableRecord::VERSION`), Konfigurationsschlüssel
entfernt oder einen fail-closed Standardwert ändert — mit einem
Migrationsleitfaden. Ketten, die von einem älteren Patch oder Minor
geschrieben wurden, verifizieren unter dem neueren; ein Major verifiziert
alte Ketten weiterhin über das gespeicherte `hash_version`. Details in
`docs/maintainers/releasing.md`.
