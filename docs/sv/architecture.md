<!-- source: docs/en/architecture.md sha256: 08a1a85bfd031a7376725ee2f87cb641ee7423240763c5bcc9dff18b30e1c383 -->
# Arkitektur

*Svensk utgåva — den [engelska versionen](../en/architecture.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Tre paket, en beroenderiktning: `addressing <- tenant <- audit`. Arkitekturtestet i
`tests/Arch` fäller bygget när den riktningen bryts eller när ett paket refererar till
klasser i värdapplikationen.

```
Efnext\Security\Addressing        identifiers, generation, Eloquent traits
Efnext\Security\Tenant            context, resolution, locating, membership, HTTP, queue, console
Efnext\Security\Audit             records, hashing, signing, sanitizing, writing, verification, anchoring
```

## Tenant: request-livscykel

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

Viktiga beslut:

- **Scopet kastar (throws) istället för att returnera allt.** `TenantContextMissing`
  ärver `LogicException`, renderas aldrig som en 4xx, och namnger de tre legitima
  lösningarna i sitt meddelande.
- **Resolution och uppslag (lookup) är separata.** Resolvers hittar bara en sträng.
  Locatorn omvandlar den till en existerande tenant-post med en status. Felaktigt
  formaterade identifierare når aldrig en fråga (UUID/ULID-kontroll för `id`,
  `PublicId`-parser för `public_id`, strikt reguljärt uttryck för `slug`).
- **Kontexten är ett värdeobjekt med en `source`.** Vid forensik går det att skilja en
  tenant (en tenant, tenanten) som identifierats via header från en som förts vidare via
  kön.
- **`withoutTenant()` kräver en anledning (reason)** och utlöser `TenantScopeBypassed` med
  anroparens file:line; audit-paketet registrerar det.
- **Köpropagering använder jobbets payload**, inte jobb-egenskaper, så varje jobb får
  dispatcherns tenant utan kodändringar. `TenantAware`-jobb kan åsidosätta det. Managern
  pausar och återupptar tillstånd per jobb så att sync-drivrutinen beter sig som en
  riktig worker.
- **Städning (cleanup) vid request-gränser** (`RequestHandled`, Octanes
  terminated-händelser) och vid jobbgränser, så att en långkörande worker inte kan läcka
  en tenant till nästa arbetsenhet.

## Audit: skrivväg

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

Vad som hashas (hash-version 2): id, chain key, tenant id, sekvensnummer, previous hash,
occurred_at i mikrosekunder, actor type/id/label, action, resource type/id, outcome,
metadata, request id, correlation id, ip, user agent. Hashas inte: `recorded_at`
(informativ servertid; ordning och manipulationsdetekteringen vilar på `sequence` och
`occurred_at_us`), `hash_version`, `event_hash`, `key_id`, `signature`. En ändring av
`recorded_at` upptäcks därför inte av verifieraren; append-only-triggrarna är det enda
skyddet för den kolumnen.

Varför `Audit::transaction()` finns: inuti anroparens transaktion tas huvudlåset (head
lock) vid tidpunkten för audit-skrivningen, efter anroparens egna läsningar. Vid
samtidighet (contention) kan varje databas då avbryta transaktionen (InnoDB deadlock,
PostgreSQL serialization failure, MariaDB ≥ 11.6 snapshot-isolation-fel 1020).
`Audit::transaction()` gör om hela affärstransaktionen med jittrad back-off vid
samtidighetsfel; det är den rekommenderade omslutningen (wrapper) för transaktioner som
innehåller audit-skrivningar.

Varför huvudraden (head row) skapas utanför transaktionen: på InnoDB gör
`INSERT … IGNORE` följt av `SELECT … FOR UPDATE` på samma nya rad inom en och samma
transaktion att varje samtidig förstaskrivare håller ett delat lås och sedan begär ett
exklusivt; InnoDB dödar alla utom en (deadlock). Med raden skapad i sitt eget
auto-commit-uttryck tar `FOR UPDATE` ett vanligt radlås och skrivarna köar. När `record()`
körs inuti anroparens transaktion, går huvudinsättningen (head insert) med i den av
nödvändighet; anropa `Audit::prepareChain($tenantId)` när du skapar en tenant om dina
första audit-händelser skrivs inuti större transaktioner under samtidighet. Deadlocks som
ändå uppstår görs om med jitter (`audit.write.attempts`) när writern äger transaktionen,
och propageras annars till anroparens transaktion.

Varför en heads-tabell: `MAX(sequence)+1` under samtidiga transaktioner är en kapplöpning
(race) på varje isoleringsnivå under serialiserbar. En låst rad per kedja är billig,
portabel mellan PostgreSQL/MySQL/MariaDB, och ger verifieraren en andra bedömning av var
kedjan borde sluta.

Varför sekvensnummer istället för tidsstämplar: två applikationsservrar med några
millisekunders klockskillnad (clock skew) får inte producera larm om ”manipulation”.
Tidsstämplar registreras och hashas; de används inte för ordning.

## Audit: verifiering

`HashChainVerifier::verify(chain, from, to)` går igenom kedjan i sekvensordning i block
(chunks) och rapporterar:

| Kontroll | Fångar |
| --- | --- |
| sammanhängande sekvens | borttagna eller injicerade rader |
| `previous_hash`-länkning | omordnade eller utbytta rader |
| omräknad `event_hash` | varje ändring av en hashad kolumn (alla kolumner utom `recorded_at`, `hash_version`, `key_id`, `signature`) |
| HMAC-signatur | konsekventa omskrivningar av någon utan nyckeln |
| överensstämmelse med kedjehuvudet | en trunkerad svans |
| överensstämmelse med senaste ankaret | en trunkerad svans eller omskrivning utförd tillsammans med huvudet |

`ChainViolationDetected` skickas ut för varje kedja med fynd; `audit:verify` avslutar med
en kod skild från noll.

## Audit: ankring

`audit:anchor` tar en ögonblicksbild (snapshot) av `(chain, sequence, hash, signature,
time)` till ett eller flera lager: `database` (bekvämt, inte oberoende), `filesystem`
(vilken Laravel-disk som helst; use object lock), `log` (endast skrivbar, för SIEM:ar).
Verifieraren läser det senaste ankaret från det första lagret som har ett; konfigurera
det externa lagret först. En konfiguration med enbart `log` ger verifieraren inget att
jämföra mot: para ihop den med `filesystem` (eller `database`) så att ankare kan läsas
tillbaka.

## Addressing

`PublicId` och `GlobalObjectId` är kanoniska-eller-ogiltiga värdeobjekt: endast gemener,
ingen trimning, strikta reguljära uttryck. `HasPublicId` tilldelar vid skapande, nekar
ändringar, håller kolumnen utanför mass assignment, och gör att route model binding
hoppar över databasen för felaktigt formaterade värden. `$table->publicId()` skapar den
unika kolumnen.

## Operativa självkontroller

`security:doctor` (`Efnext\Security\Audit\Doctor\SecurityDoctor`) kör oberoende
kontroller och rapporterar OK/WARN/FAIL/SKIP: konfiguration (nycklar, drivrutin,
append-only (endast tillägg)), installerade triggrar (`AppendOnly::missingTriggers()`),
ankarlager, ankarfärskhet och verifieringsålder per kedja (från
`audit_verification_runs`, skrivet av `audit:verify` och doctorn), en direkt
svansverifiering (live tail verification), tenant-middlewarens prioritet och
cache-lager. `ProductionGuard` innehåller den konfigurationsbara delmängden och tillämpas
vid uppstart i `APP_ENV=production` (`InsecureConfiguration`).

## Schema-hjälpare

`$table->tenantId()` lägger till tenant-kolumnen, dess FK till `tenants`, ett index och
`UNIQUE(tenant_id, id)`. `$table->tenantForeignId('invoice_id', 'invoices')` (och
uuid/ulid-varianterna) lägger till kolumnen plus en sammansatt främmande nyckel
`(tenant_id, invoice_id) → invoices(tenant_id, id)`, så att databasen själv nekar en
relation mellan tenants — på PostgreSQL, MySQL, MariaDB och SQLite. Hjälparna returnerar
en `TenantForeignKeyDefinition` som accepterar `cascadeOnDelete()`, `restrictOnDelete()`
och `noActionOnDelete()` men kastar undantag vid `nullOnDelete()`/`nullOnUpdate()` (och
`SET DEFAULT`): en sammansatt nyckel kan inte nollställa en av sina kolumner, så
`SET NULL` skulle även behöva nollställa den NOT NULL-satta tenant-kolumnen —
MySQL/MariaDB nekar att skapa ett sådant villkor (errno 150), PostgreSQL/SQLite skapar
det och nekar sedan borttagningen av föräldern (parent delete). En valfri relation är
`tenantForeignId('invoice_id', 'invoices', nullable: true)` med cascade eller restrict;
att koppla loss (detach) är en uppdatering i applikationskoden.

## Konfigurationsyta

Allt finns i `config/tenant.php`, `config/audit.php` och `config/addressing.php`; varje
inställning är dokumenterad på plats. Bindningar är singletons som slås upp (resolved)
från konfigurationen en gång per process; tester som ändrar konfigurationen löser om den
berörda bindningen explicit.
