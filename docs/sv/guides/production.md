<!-- source: docs/en/guides/production.md sha256: 8f9787574c71baea2e5e47ccb5aa3ff1d41773fd81af358a5926e69d2a9158e8 -->
# Produktionsguide

*Svensk utgåva — den [engelska versionen](../../en/guides/production.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Den kompletta produktionsuppsättningen, redo att kopiera: nycklar, databas,
ankare på objektlagring, schemaläggare, övervakning och
`security:doctor --strict` som deployment-gate. Inget här kräver en
publicerad konfigurationsfil; allt är miljövariabler och de två block med PHP
som visas.

Läs detta tillsammans med [checklistan för integration](../integration-checklist.md)
(bocka av den) och [hotmodellen](../threat-model.md) (känn till vad som återstår).

## Måltillståndet

| Garanti | Produktionsvillkor | Hur du bevisar det |
| --- | --- | --- |
| Audit-poster kan inte ändras obemärkt | PostgreSQL/MySQL/MariaDB; append-only-triggers (endast tillägg) installerade; signeringsnyckel i en hemlighetshanterare (secret manager); ankare på objektlagring med Object Lock, framtvingat av `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` | `security:doctor --strict` avslutar med 0 |
| Kedjor verifieras och ankras kontinuerligt | schemaläggaren kör `audit:anchor` med några minuters mellanrum och `audit:verify` dagligen | doctor kontrollerar `anchor_freshness`, `verification_recency` |
| Någon upptäcker det | `ChainViolationDetected` och doctor-fel når en person | ett larmtest |
| Tenants förblir isolerade under belastning | tenant-cache på redis/memcached/databas, inte `array` | doctor-kontrollen `tenant_cache` |

## 1. Hemligheter

```bash
php artisan audit:key:generate --id=k1
```

Lägg de två raderna i din **hemlighetshanterare (secret manager)**, inte i en
`.env`-fil på servern, och aldrig på samma sökväg i valvet som
databasuppgifterna — en angripare som har åtkomst på databasnivå och
dessutom nyckeln kan skriva om historiken konsekvent. Injicera dem som
`AUDIT_SIGNING_KEYS` och `AUDIT_SIGNING_ACTIVE_KEY` vid driftsättning. Ge vid
behov pseudonymiseringspepparen en egen hemlighet (`AUDIT_PSEUDONYM_KEY`, 32+
slumpmässiga byte); annars härleds den från `APP_KEY`.

## 2. Databas

- PostgreSQL 14+, MySQL 8.0/8.4 eller MariaDB 10.11+. SQLite vägrar starta i
  produktion.
- **Migrationsanvändaren** behöver `CREATE TRIGGER`; på MySQL/MariaDB med
  binär loggning även `SUPER` eller `log_bin_trust_function_creators=1`. Kör
  migrationer med den användaren; applikationsanvändaren behöver den inte.
- Valfri privilegieseparation: `AUDIT_DB_CONNECTION=audit` som pekar på en
  anslutning vars användare bara har `SELECT, INSERT` på `audit_events`,
  `audit_anchors`, `audit_verification_runs` och `SELECT, INSERT, UPDATE` på
  `audit_chain_heads`. Du förlorar atomicitet mellan affärsskrivningar och
  audit-skrivningar (doctor säger det); besluta medvetet.
- Håll applikationens databasanvändare utan rättigheterna `DROP TRIGGER`/`ALTER`
  på audit-tabellerna. Triggrarna är det första lagret, inte det enda.

## 3. Ankare på objektlagring

1. En bucket med **Object Lock** i compliance-läge och en lagringsperiod som
   är minst lika lång som din audit-lagringsperiod, samt **egna
   autentiseringsuppgifter** som databasvärden inte har. `filesystem`-lagret
   behöver tre behörigheter och får inte ha en fjärde: det *skriver* ett
   objekt per ankare (`PutObject`), *listar* en kedjas prefix och *läser* det
   senaste ankaret (`ListBucket` på prefixet, `GetObject`) — så jämför
   verifieraren och doctor kedjan med den externa kopian — och det raderar
   aldrig. Object Lock hindrar även en stulen behörighet med `DeleteObject`
   från att ta bort ett objekt inom lagringsperioden; policyn ser till att
   applikationen aldrig har den behörigheten från början:

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

   Anpassa bucketnamn och prefix (`AUDIT_ANCHOR_PATH`); andra leverantörers
   policyer motsvarar detta ett till ett (skriva, lista prefix, läsa; neka
   radering och ändringar av lagringsperioden). Ramverket kan inte göra
   lagring oföränderlig; det kan bara använda lagring som redan är det.
2. En disk:

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

3. Miljön:

   ```dotenv
   AUDIT_ANCHOR_STORES=filesystem,database     # external store first; AUDIT_ANCHOR_DISK alone changes nothing
   AUDIT_ANCHOR_DISK=audit-anchors
   AUDIT_ANCHOR_PATH=audit-anchors/billing     # one prefix per application if several share a bucket
   AUDIT_REQUIRE_EXTERNAL_ANCHOR=true          # production refuses to boot without the external store
   ```

Ett skrivbart, icke-läsbart (`log`-)lager (`AUDIT_ANCHOR_STORES=filesystem,log,database`,
`AUDIT_ANCHOR_LOG_CHANNEL=siem`) är en bra tredje kopia om du redan skickar
loggar till en oföränderlig mottagare (sink); det ersätter aldrig det läsbara
externa lagret.

## 4. Produktionsmiljöblocket

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

`.env.example` i ramverkets repository dokumenterar alla andra variabler.

## 5. Schemaläggare

```php
// routes/console.php
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->daily()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
```

`onOneServer()` spelar roll: två ankringskörningar samtidigt är ofarligt men
slösaktigt; två verifieringar samtidigt fördubblar belastningen.
`audit.doctor.max_anchor_age_minutes` (standard 60) bör vara flera multiplar
av ankringsintervallet.

## 6. Deployment-gate

```bash
php artisan migrate --force
php artisan security:doctor --strict        # exit 1 stops the deployment
```

`--strict` är högsäkerhetsprofilen: varningar räknas som fel, och kontrollen
`anchor_stores` misslyckas om inte `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` är
satt — ett externt lager som bara är konfigurerat kan tas bort av nästa
driftsättning utan att något vägrar starta; kravet måste framtvingas vid
uppstart. Lägg in kommandot i pipelinen efter varje utrullning och stoppa vid
en avslutningskod som inte är noll. `--json` ger samma utslag (`healthy`,
`strict`) för verktyg.

Förväntat vid en korrekt installation:

```
  [OK] Environment .......... APP_ENV=production, debug off, fail-fast on.
  [OK] Signing key .......... 1 key(s) in the ring, active: k1.
  [OK] Database driver ...... Connection "pgsql": pgsql 16.4.
  [OK] Append-only triggers . All 4 triggers present on audit_events and audit_anchors.
  [OK] Anchor stores ........ Configured: filesystem, database (readable: filesystem, database; external: filesystem, required).
  …
  OK / WARN / FAIL / SKIP ... 13 / 0 / 0 / 0
```

## 7. Övervakning

Tre signaler, i prioritetsordning, mest brådskande först:

| Signal | Källa | Åtgärd |
| --- | --- | --- |
| Händelsen `ChainViolationDetected` / `audit:verify` avslutar med 1 | verifieraren | larma; starta [incidenthantering](../operations/incident-response.md) |
| `security:doctor` FAIL | schemaläggaren (`emailOutputOnFailure`) eller din övervakning som anropar `--json` | åtgärda innan nästa driftsättning; en FAIL innebär att en garanti för närvarande inte upprätthålls |
| `security:doctor` WARN | samma | planera åtgärden; med `--strict` gör en WARN redan att deployment-gate:n fallerar |

```php
Event::listen(ChainViolationDetected::class, fn ($e) => Alert::page('audit-integrity', $e->report->chainKey->toString(), $e->report->toArray()));
```

Användbara mätvärden att exportera: `audit_verification_runs` (senaste
intakta körning per kedja), ankare per timme, händelser av typen
`tenant.access_denied` per tenant (en topp är antingen en bugg eller en
attack). Detaljer i [operations/monitoring.md](../operations/monitoring.md).

## 8. Anteckningar om driftsättningstopologi

- **Laravel Octane/långlivade workers**: stöds; tenant-kontexten rensas vid
  slutet av varje request/jobb. Inget att konfigurera.
- **Flera applikationer, en bucket**: separata `AUDIT_ANCHOR_PATH`-prefix och
  separata `SECURITY_SERVICE_NAME`-värden.
- **Läsreplikor**: peka audit-anslutningen mot den primära noden; skrivaren
  behöver `SELECT … FOR UPDATE`.
- **Blue/green**: båda färgerna kan ankra; ankare är append-only och
  idempotenta per kedjehuvud.
- **Säkerhetskopior**: audit-databasen och ankarbucketen säkerhetskopieras
  och återställs *tillsammans*; en återställd databas utan sina ankare
  verifierar korrekt men kan inte bevisa att den inte trunkerades före
  återställningen.

## 9. Rotation och förändring

- Nyckelrotation: [operations/key-rotation.md](../operations/key-rotation.md).
- Byte av ankarlager: lägg till det nya lagret *först* i
  `AUDIT_ANCHOR_STORES`, kör `audit:anchor --force`, verifiera, och ta sedan
  bort det gamla efter dess lagringsperiod. Ankare i det gamla lagret förblir
  giltiga bevis för den period de täcker.
- Uppgraderingar av ramverket: läs CHANGELOG för varje överhoppad version,
  `migrate`, `security:doctor --strict`. Migrationer är additiva inom en
  huvudversion.

## 10. Checklista inför driftsättning (kortversion)

- [ ] `security:doctor --strict` avslutar med 0 i produktionsmiljön
- [ ] nycklar i hemlighetshanteraren, inte på servern; databasuppgifter på
      annan plats
- [ ] Object Lock verifierat genom att försöka radera ett ankarobjekt **med
      en administrativ identitet som har `DeleteObject`** (det måste misslyckas
      med ett Object Lock-fel — med applikationens behörighet misslyckas
      försöket redan i IAM och bevisar ingenting om låset)
- [ ] schemaläggarposter driftsatta och observerade köra (`audit_anchors`
      växer)
- [ ] `ChainViolationDetected` kopplad till larmning och testad en gång med
      en falsk rapport
- [ ] applikationsanvändare utan `DROP TRIGGER` på audit-tabellerna
- [ ] hela [checklistan för integration](../integration-checklist.md) avbockad
