<!-- source: docs/en/reference/compatibility.md sha256: a91c74d2b5080d54f8ec53c7ed58ec80dbb0d5371ea1f0c30a78b8cf2748f3aa -->
# Kompatibilitet

*Svensk utgåva — den [engelska versionen](../../en/reference/compatibility.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

## Supportmatris

| | Stöds — hårda CI-gates |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 13 |
| PostgreSQL | 14, 16, 17 |
| MySQL | 8.0, 8.4 |
| MariaDB | 10.11, 11.4, 11 (senaste) |
| SQLite | endast tester och lokal utveckling — produktion vägrar starta med den |

”Stöds” betyder att kombinationen är ett obligatoriskt jobb i CI-gates
(`.github/workflows/gates.yml`) och att en release inte taggas medan det är
rött. Det finns inga experimentella kombinationer: Composer-villkoren tillåter
endast Laravel 13, och en ny PHP-, Laravel- eller databasversion blir stödd
genom att bli en gate. Composer utesluter inte PHP-versioner över 8.5; de är
otestade tills de är en gate. En applikation på Laravel 12 uppgraderar först
till Laravel 13.

Endast den senaste minor-releasen får rättningar (`SECURITY.md`).

## Vad varje databas behöver

| Databas | Krav | Varför |
| --- | --- | --- |
| PostgreSQL | inget särskilt; triggers använder `plpgsql` | append-only-triggers |
| MySQL 8.x | migreringsanvändaren har `CREATE TRIGGER`; med binär loggning även `SUPER` eller servervariabeln `log_bin_trust_function_creators=1` | InnoDB-triggers under binlog |
| MariaDB 10.11+ | samma som MySQL; MariaDB 11.6+ har `innodb_snapshot_isolation=ON` som standard, vilket gör att huvudradskonflikter blir fel 1020 — `Audit::transaction()` gör om dem | triggers, snapshot isolation |
| SQLite | `pdo_sqlite`; används av testsviten | inga radlås över anslutningar som writern förlitar sig på — aldrig för audit-loggar i produktion |

Alla tre produktionsdatabaser: audit-tabellerna behöver rad-nivå `SELECT …
FOR UPDATE` (writern serialiserar tillägg per kedja på huvudraden) och
tidsstämplar med mikrosekundsprecision (`datetime(6)`/`timestamp(6)`-
kolumner; skapas av migreringen).

## PHP-tillägg

`pdo` med den drivrutin du använder, `json`, `mbstring`, `openssl`
(slumpmässiga byte, HMAC via `hash_hmac` som är en del av core), och för
testsviten `pcntl` (forkade samtidighetstester) och en coverage-drivrutin
(`pcov` eller `xdebug`) för mutationstestning.

## Laravel-funktioner som utnyttjas

Middleware-prioritet (`Kernel::addToMiddlewarePriorityBefore`/routerns
prioritetslista), Eloquents globala scopes och modellhändelser,
`Queue::createPayloadUsing()` och jobbets livscykelhändelser,
`Storage`-diskar för filesystem-ankarlagret, Sanctums
`currentAccessToken()`-abilities (valfritt). Laravel Octane stöds:
tenant-kontexten rensas vid varje `*Terminated`-händelse.

## Uppgradering

Semantisk versionering för alla paket tillsammans. Patch: ingen ändring av
schema eller beteende. Minor: endast tillägg av migreringar. Major: allt
som ändrar vad som hashas (`HashableRecord::VERSION`), tar bort
konfigurationsnycklar eller ändrar ett fail closed-standardvärde — med en
migreringsguide. Kedjor skrivna av en äldre patch eller minor verifieras
under den nyare; en major fortsätter att verifiera gamla kedjor via det
sparade `hash_version`. Detaljer i `docs/maintainers/releasing.md`.
