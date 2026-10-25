<!-- source: docs/en/operations.md sha256: 4ecd18f6d8f836fde59198416e56615529f23c7348ccfb1fac151480ca424141 -->
# Drift

*Svensk utgåva — den [engelska versionen](../en/operations.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Referensen för den driftansvariga (operatören) för en installation i drift.
De löpande rutinerna har egna sidor:

| Runbook | När du behöver den |
| --- | --- |
| [Övervakning](operations/monitoring.md) | vad du ska bevaka, vad varje signal betyder, doctor-JSON, mätvärden |
| [Nyckelrotation](operations/key-rotation.md) | schemalagd rotation, pensionera en nyckel, komprometterad nyckel, `APP_KEY` |
| [Incidenthantering](operations/incident-response.md) | en avvikelse från verifieraren eller en doctor-FAIL: bevara, avgränsa, begränsa, dokumentera |

[Produktionsguiden](guides/production.md) är den ordnade vägen från en
fungerande utvecklingsmiljö till en härdad driftsättning; den här sidan är
referensen du återvänder till.

## Doctor

```bash
php artisan security:doctor            # human-readable, exit 1 on FAIL
php artisan security:doctor --strict   # high-security profile / deployment gate: exit 1 on WARN as well, and AUDIT_REQUIRE_EXTERNAL_ANCHOR must be on
php artisan security:doctor --json     # monitoring
```

Kontroller: miljö (debug, fail-fast), `APP_KEY`, signeringsnyckel,
databasdrivrutin och nåbarhet, append-only-konfiguration (endast tillägg)
**och installerade triggers**, ankarlager (läsbara, externa), separat
audit-anslutning, ankarfärskhet per kedja (`audit.doctor.max_anchor_age_minutes`),
senaste intakta fullständiga verifiering per kedja
(`audit.doctor.max_verification_age_hours`), en direktverifiering av de
senaste `audit.doctor.tail_events` händelserna i varje kedja,
tenant-middlewarens prioritet, tenant-cache-lagret. Kör den efter varje
driftsättning och från övervakningen. `--strict` är deployment-gate för
högsäkerhetsprofilen: varningar räknas som fel, och kontrollen
`anchor_stores` misslyckas om inte `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` är
satt — ett externt lager som bara är konfigurerat räcker inte, eftersom
inget skulle hindra en senare driftsättning från att ta bort det igen;
kravet måste framtvingas av uppstartsvakten (boot guard). En pipeline som
kör `security:doctor --strict` efter varje utrullning kan därför inte skicka
en produktion utan ett framtvingat externt ankare, en debug-flagga som
lämnats på, eller en saknad signeringsnyckel. Utan `--strict` installeras och
körs ramverket med enbart databasankare (en `WARN`), vilket är det avsiktliga
standardvalet för ett bibliotek.

`audit:verify` och doctor registrerar sina körningar i
`audit_verification_runs` (kedja, intervall, resultat, tid). Den tabellen är
bokföring för färskhetskontrollen, inte bevis.

Med `APP_ENV=production` vägrar providern själv att starta när
konfigurationen saknar signeringsnyckel, använder SQLite, stänger av
append-only-framtvingandet eller saknar ett läsbart ankarlager
(`InsecureConfiguration`). `AUDIT_FAIL_FAST=false` stänger av den vakten för
ett medvetet, tillfälligt undantag; doctor fortsätter att rapportera
problemet.

`AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` är den strikta produktionsprofilen:
vakten kräver då också minst ett läsbart ankarlager utanför audit-databasen
(`filesystem` på en bucket med Object Lock, eller en anpassad
`AnchorStore`-klass), kontrollerar att den kan instansieras (disken finns,
klassen implementerar kontraktet), och doctor rapporterar en installation
med enbart databasankare som `FAIL`. Slå på den så snart bucketen finns; utan
den är enbart databasankare en `WARN`, eftersom en angripare som kan skriva
om kedjan också kan skriva om ankare som lagras bredvid den.

## Schemaläggning

```php
// routes/console.php or the scheduler
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->hourly()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
```

`audit:verify` avslutar med 1 när någon kedja har avvikelser och skickar ut
`ChainViolationDetected` för varje berörd kedja. Koppla händelsen till din
larmning.

För stora installationer, verifiera inkrementellt: håll reda på det senast
verifierade sekvensnumret per kedja och anropa
`Audit::verify($chain, $lastVerified + 1)`; verifieraren kontrollerar
föregångarlänken och kedjehuvudet.

## Nycklar

- Generera: `php artisan audit:key:generate --id=k2` (skriver ut, sparar
  inte).
- Rotera: lägg till den nya nyckeln i `AUDIT_SIGNING_KEYS`, driftsätt, sätt
  sedan `AUDIT_SIGNING_ACTIVE_KEY` till den och driftsätt igen. Gamla nycklar
  finns kvar i listan så att gamla signaturer fortsätter verifiera; ta bara
  bort en nyckel efter att varje händelse som signerats med den har lämnat
  lagringsperioden (retention).
- Förvara nycklar i en hemlighetshanterare — aldrig i databasen, aldrig i en
  konfigurationstabell, aldrig på samma sökväg i valvet som
  databasuppgifterna.
- Procedur, checklista och fallet med en komprometterad nyckel:
  [Nyckelrotation](operations/key-rotation.md).

## Ankare

- Lagret `database`: bekvämt, append-only via triggers, men inte oberoende
  av databasen.
- Lagret `filesystem`: peka det mot en S3-bucket (eller kompatibel) med
  Object Lock i compliance-läge och en lagringsperiod som är minst lika lång
  som din audit-lagringsperiod.
- Lagret `log`: endast skrivbart, en strukturerad rad per ankare;
  vidarebefordra kanalen till ett SIEM eller en oföränderlig
  logglagringstjänst. Eftersom det inte kan läsas tillbaka ignorerar
  verifieraren det; kombinera det alltid med ett läsbart lager (`filesystem`
  först, `database` som reserv).

Lista det externa lagret först i `AUDIT_ANCHOR_STORES`; verifieraren
föredrar det första lagret som har ett ankare. Med
`AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` startar inte produktionen utan ett
sådant lager (se Doctor ovan).

### Produktionsrecept: ankare på objektlagring

Allt nedan är redo att kopiera; inget kräver en publicerad konfigurationsfil.

1. **En bucket med Object Lock** (compliance-läge, lagringsperiod minst lika
   lång som din audit-lagringsperiod) och **egna autentiseringsuppgifter** —
   aldrig sådana som kan nå audit-databasen — med exakt det lagret behöver:
   put, lista prefixet, get (verifieraren och doctor läser tillbaka det
   senaste ankaret); ingen radering, inga ändringar av lagringsperioden. Den
   minimala policyn finns i
   [produktionsguiden](guides/production.md#3-ankare-på-objektlagring).
   Ramverket kan inte göra lagring oföränderlig; det kan bara använda lagring
   som redan är det.
2. **En disk för den** i `config/filesystems.php`:

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

3. **`.env`-blocket** (se `.env.example` för alla andra variabler):

   ```dotenv
   AUDIT_SIGNING_KEYS=k1:base64:<value from the secret manager>
   AUDIT_SIGNING_ACTIVE_KEY=k1
   AUDIT_ANCHOR_STORES=filesystem,database
   AUDIT_ANCHOR_DISK=audit-anchors
   AUDIT_REQUIRE_EXTERNAL_ANCHOR=true
   AUDIT_FAIL_FAST=true
   ```

   `AUDIT_ANCHOR_DISK` ensamt ändrar ingenting: lagret `filesystem` måste
   finnas i `AUDIT_ANCHOR_STORES`, med det externa lagret först.
4. **Schemaläggare**: `audit:anchor` med några minuters mellanrum,
   `audit:verify` minst dagligen (se Schemaläggning ovan).
5. **Deployment-gate**: `php artisan security:doctor --strict` efter
   utrullningen; exit 0 betyder att nyckeln finns i nyckelringen, det
   externa lagret svarar, kravet framtvingas vid uppstart och kedjorna
   verifierar. Exit 1 stoppar driftsättningen.

## Databas

- PostgreSQL: inget särskilt. Triggrarna använder `plpgsql`.
- MySQL 8/MariaDB 11: att skapa triggers kräver `CREATE TRIGGER`; med binär
  loggning även `SUPER` eller `log_bin_trust_function_creators=1`. Kör
  audit-migrationen med en priviligierad användare, eller sätt
  servervariabeln.
- SQLite: stöds för utveckling och tester; använd det inte för audit-loggar
  i produktion (inga radlås mellan anslutningar på det sätt som skrivaren
  förväntar sig).
- Separat anslutning: sätt `AUDIT_DB_CONNECTION` för att skriva
  audit-tabellerna till en egen databas/användare. De minsta nödvändiga
  rättigheterna är `SELECT`, `INSERT` på `audit_events`, `audit_anchors` och
  `audit_verification_runs`, samt `SELECT`, `INSERT`, `UPDATE` på
  `audit_chain_heads` (huvudraden flyttas fram vid varje skrivning;
  `SELECT … FOR UPDATE` kräver `UPDATE`). Tillsammans med triggrarna hindrar
  detta applikationsanvändaren från att någonsin ändra eller radera en
  händelse.
- Separat anslutning och atomicitet: en audit-skrivning på sin egen
  anslutning kan inte dela transaktion med din affärsskrivning. Om
  audit-posten måste finnas om och endast om affärsförändringen gör det,
  håll båda på en anslutning och omslut dem i ett enda
  `Audit::transaction()` (nästa punkter); acceptera annars att en krasch
  mellan de två lämnar den ena utan den andra.
- MySQL/MariaDB och samtidighet: skrivaren skapar kedjans huvudrad i en
  auto-commit-sats före sin transaktion, så att samtidiga första skrivare
  inte kan hamna i deadlock. Inuti din egen transaktion är det omöjligt; om
  du skriver de första händelserna för nya kedjor inuti större transaktioner
  under belastning, anropa `Audit::prepareChain($tenantId)` (utanför
  transaktionen, t.ex. när tenanten skapas).
- Audit-skrivningar inuti affärstransaktioner: **använd
  `Audit::transaction()`**, inte `DB::transaction()`. Kedjehuvudet låses vid
  tidpunkten för audit-skrivningen och hålls låst tills din transaktion
  committas, så att samtidiga transaktioner för samma tenant serialiseras
  där. Varje databas kan förvandla det till en konflikt som går att göra om
  (retryable): InnoDB-deadlockar, PostgreSQL `could not serialize access`
  under REPEATABLE READ, och MariaDB 11.6+ (standard
  `innodb_snapshot_isolation=ON`) `ER_CHECKREAD 1020 "Record has changed
  since last read"` närhelst huvudraden ändrades efter din transaktions
  första läsning. Laravel kastar dessa som `DeadlockException`/`QueryException`;
  `DB::transaction($fn, attempts: n)` gör om försöket direkt och tenderar
  att krocka igen vid ihållande belastning. Omslut sådana transaktioner i
  `Audit::transaction(fn () => ..., attempts: 10)`: den gör om hela
  transaktionen med backoff (jitter) enbart vid samtidighetsfel, och kör
  callbacken en gång när en transaktion redan är öppen. Eftersom closuren
  kan köras flera gånger får den inte skicka e-post, anropa externa API:er
  eller orsaka andra icke-transaktionella sidoeffekter; köa dem efter att
  den returnerat, eller gör dem idempotenta. Alternativ: kör anslutningen
  med `'isolation_level' => 'READ COMMITTED'` (MySQL/MariaDB; varje sats ser
  det senast committade tillståndet), eller ta ut audit-skrivningen ur
  affärstransaktionen när strikt atomicitet inte krävs.

## Kvalitetsgrindar

Varje utgåva (release) hålls mot grindarna nedan; `security:doctor` är den
enda som en driftansvarig kör. Resten finns här så att du vet vad en grön
release betyder — hur du kör och utökar dem är dokumentation för
underhållare (`docs/maintainers/testing.md`).

| Grind | Vad den bevisar |
| --- | --- |
| `composer audit` | inga kända säkerhetsvarningar (advisories) i beroendeträdet |
| Pint, PHPStan nivå 8 | stil, typer (inklusive traits i en verklig modellkontext) |
| Testmatris | beteende på SQLite, PostgreSQL, MySQL, MariaDB; PHP 8.3/8.4 × Laravel 12 som hårda grindar, lägsta och högsta beroenden, PostgreSQL 14–17, MySQL 8.0/8.4, MariaDB 10.11–11; radtäckning ≥ 80 % (PHP 8.5 och Laravel 13 observeras i ett separat jobb som får misslyckas) |
| Samtidighets- och lasttester | grenade (forkade) skrivare grenar aldrig en kedja, med och utan affärstransaktioner; dussintals skrivare på två kedjor förblir linjära |
| Slumpmässiga invarianter | kanonisk JSON, hashning, identifierare, tenant-id:n och saniteraren håller för slumpmässig indata |
| Kända testvektorer (known-answer vectors) | hashformatet är byte-identiskt med registrerade vektorer (version, domänsträng, hashade fält, kanonisk JSON, HMAC); en ändring är en ny hashversion, aldrig en tyst redigering, och `packages/audit/tests/Fixtures/hash-vectors.json` låter vem som helst räkna om en hash utan denna kodbas |
| Mutationstestning | testerna märker när säkerhetskärnan ändras (CI misslyckas under en poäng på 70 %; den fullständiga rapporten är en byggartefakt) |

## Prestanda

- Tenant-uppslagning cachas (`tenant.cache`, standard 60 s, negativ cache
  5 s). Statusändringar ogiltigförklarar cachen vid sparning.
- Varje audit-skrivning tar ett radlås på `audit_chain_heads` under
  insättningens varaktighet; skrivare till olika kedjor blockerar inte
  varandra. Räkna med låga ensiffriga millisekunder per skrivning på
  PostgreSQL. Bara kedjans allra första skrivning lägger till en extra
  existenskontroll och insättning; därefter är det en billig `SELECT` före
  transaktionen.
- Verifiering delas upp i block (`audit.verification.chunk_size`, standard
  1000) och strömmas; minnesanvändningen förblir konstant.

## Observerbarhet

Händelser du kan lyssna på:

| Händelse | När |
| --- | --- |
| `Tenant\Events\TenantContextActivated` | en tenant blev aktiv (request, jobb, `run()`) |
| `Tenant\Events\TenantAccessDenied` | kontroll av medlemskap, roll eller token misslyckades |
| `Tenant\Events\TenantScopeBypassed` | `withoutTenant()` anropades |
| `Tenant\Events\MembershipChanged` | beviljande / rolländring / statusändring / återkallande |
| `Tenant\Events\TenantSwitched` | sessionens tenant ändrades |
| `Audit\Events\AuditEventRecorded` | efter varje lyckad audit-skrivning |
| `Audit\Events\ChainViolationDetected` | verifieraren hittade avvikelser |

Audit-paketet prenumererar på de fem första och skriver dem till loggen
(`audit.record_tenant_events`). Händelser utan en tenant (ett
`withoutTenant()` utanför all tenant-kontext, ett nekande för en okänd
tenant) hamnar på kedjan `global` oavsett `audit.tenant_policy`; verifiera
den med `audit:verify` som vilken annan kedja som helst.

Doctor-JSON, kontroll-id:n, mätvärdena som är värda att exportera och
larmreglerna: [Övervakning](operations/monitoring.md).

## Incidenthantering

Kort sagt: bevara databasen och ankarlagret före allt annat; kör
`php artisan audit:verify --json` och `security:doctor --json --strict` från
en betrodd värd och spara utdatan; läs varje avvikelses `reason`
(`event_hash_mismatch` pekar ut ändrade rader; `sequence_gap` och
`previous_hash_mismatch` avgränsar raderingar; `head_mismatch`/`anchor_ahead`
visar trunkering; `signature_*` visar omskrivningar utan nyckeln;
`anchor_mismatch` visar omskrivningar med nyckeln men före det senaste
ankaret); hitta det senaste externa ankaret som kedjan fortfarande matchar
— det är den senaste pålitliga punkten. Den fullständiga proceduren,
inklusive begränsning (containment) och vad som ska dokumenteras:
[Incidenthantering](operations/incident-response.md).
