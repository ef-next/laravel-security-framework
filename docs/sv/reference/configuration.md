<!-- source: docs/en/reference/configuration.md sha256: 6fa8b447781a1b0c9b6cd38e3933860ddd0723d99bd01e2d8ac9380a33040dd6 -->
# Konfigurationsreferens

*Svensk utgåva — den [engelska versionen](../../en/reference/configuration.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Varje nyckel i de tre publicerade konfigurationsfilerna, med sitt
standardvärde, den miljövariabel som matar den (om någon finns) och vad den
gör med säkerhetsgarantierna. Guiderna förklarar uppgifter; den här sidan
förklarar reglagen.

- `config/addressing.php` — 5 nycklar
- `config/tenant.php` — 25 nycklar
- `config/audit.php` — 32 nycklar

Konventioner: ”env `X`, default …” betyder `env('X', …)`; en tom sträng i
`.env` behandlas som ”inte satt” för varje strängnyckel (connection, store,
kolumn …), vilket är varför `.env.example` kan lista `AUDIT_DB_CONNECTION=`.
Booleska env-värden för fail-fast-, append-only- och external-anchor-
brytarna tolkas med `filter_var(FILTER_VALIDATE_BOOL)`, så `true/false`,
`1/0`, `on/off` och `yes/no` fungerar alla; den bokstavliga `true`/`false`
är minst förvånande. `.env.example` i repots rot listar varje miljövariabel
nedan med sitt standardvärde.

## `addressing.php`

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `addressing.service_name` | `app` | `SECURITY_SERVICE_NAME` | Första segmentet i varje globalt objekt-id (`<service>:<type>:<public id>`); gemener, siffror, bindestreck; unikt över dina tjänster. | Id:n från andra tjänster går aldrig att slå upp här. **Ändra den aldrig efter att id:n har delats ut** — varje utfärdat globalt id skulle sluta gå att slå upp. |
| `addressing.public_ids.column` | `public_id` | `SECURITY_PUBLIC_ID_COLUMN` | Standardkolumn för `HasPublicId`-modeller, `$table->publicId()`, route model binding och skydd mot mass assignment; åsidosätt per modell med `publicIdColumn()`. | — |
| `addressing.public_ids.encoding` | `base32` | `SECURITY_PUBLIC_ID_ENCODING` | `base32` (Crockford, 16 tecken för 10 byte) eller `hex`. | Alla andra värden kastar ett undantag vid den första id-genereringen. |
| `addressing.public_ids.entropy_bytes` | `10` | `SECURITY_PUBLIC_ID_ENTROPY_BYTES` | Slumpmässiga byte per id (80 bitar som standard). | Under 8 byte vägrar generatorn (`InvalidPublicId`): frameworket utfärdar inga gissningsbara id:n. |
| `addressing.public_ids.max_attempts` | `5` | `SECURITY_PUBLIC_ID_MAX_ATTEMPTS` | Nya försök vid en (praktiskt taget omöjlig) kollision; meningsfullt endast tillsammans med det `UNIQUE`-index som `publicId()` skapar. | Efter det sista försöket kastas `PublicIdGenerationFailed`, aldrig ett återanvänt id. |

## `tenant.php`

### Sanningskälla

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `tenant.model` | `Efnext\Security\Tenant\Eloquent\Tenant` | — | Tenant-modell för relationer och medlemskap (en subklass går bra). | — |
| `tenant.table` | `tenants` | — | Tenants-tabell (migrering, modell, locator, sammansatta främmande nycklar). | Om den ändras efter `migrate` går de `tenantForeignId()`-villkor som refererar till den sönder. |
| `tenant.connection` | `null` | `TENANT_DB_CONNECTION` | Anslutning för tenants och medlemskap; tom = standard. | — |
| `tenant.column` | `tenant_id` | — | Kolumn på tenant-bundna tabeller; åsidosätt per modell med `$tenantColumn`. | Kolumnen som `TenantScope` filtrerar på och som frameworket fyller i från kontexten. Aldrig i `$fillable`. |

### Identifiering — var identifieraren kommer ifrån

Resolvers körs i den ordning de listas; den första som hittar en
identifierare vinner; identifieraren slås sedan upp (nästa avsnitt) och
måste matcha en befintlig tenant, annars får requesten ett 404-svar.

| Nyckel | Standard | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- |
| `tenant.resolution.chain` | `['header', 'route', 'session']` | Ordnade resolvers: `header`, `route`, `session`, `subdomain`, eller en klass som implementerar `TenantResolver`. | En tom kedja eller en okänd post kastar ett undantag vid uppstart. `subdomain` ingår inte i standardkedjan och kräver `base_domains`. |
| `tenant.resolution.header` | `['X-Tenant-Id']` | Headers som läses av `header`-resolvern; den första icke-tomma vinner. | Headern är en *identifierare*, aldrig ett bevis: medlemskap kontrolleras därefter av `tenant.member`. |
| `tenant.resolution.route_parameters` | `['tenant']` | Route-parametrar som läses av `route`-resolvern (en bunden modell reduceras till sin nyckel). | — |
| `tenant.resolution.session_key` | `efnext.tenant` | Sessionsnyckel som läses av `session`-resolvern och skrivs av `SessionTenant::switchTo()`. | Ett byte skickar `TenantSwitched`, vilket loggas i audit-loggen. |
| `tenant.resolution.subdomain.base_domains` | `[]` | Basdomäner för `subdomain`-resolvern; den enda etiketten till vänster om en basdomän är identifieraren. | Värdjämförelsen är skiftlägesokänslig (gemener) och kräver punkten, så `evilexample.test` matchar inte `example.test`; nästlade etiketter ignoreras. |

### Uppslagning — hur en identifierare blir en tenant

| Nyckel | Standard | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- |
| `tenant.lookup.by` | `['id', 'public_id', 'global_object_id', 'slug']` | Strategier som provas i tur och ordning: UUID/ULID-id, `ten_…` publikt id, `<service>:tenant:ten_…` (endast denna tjänst), slug. | Varje strategi validerar *formen* innan den frågar databasen; identifierare längre än 255 tecken avvisas direkt. Okända strateginamn ignoreras. |
| `tenant.lookup.slug_column` | `slug` | Kolumn för `slug`-strategin. | Endast `^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$` accepteras. |
| `tenant.lookup.status_column` | `status` | Kolumn som ger tenantens status. | Fail closed (nekar hellre än släpper igenom): en okänd eller saknad status är `suspended`, aldrig `active`; pekar den mot fel kolumn låses alla tenants ute (403). |

### Cache

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `tenant.cache.enabled` | `true` | `TENANT_CACHE_ENABLED` | Omsluter locatorn med `CachedTenantLocator`. | Att stänga av den stänger även av den negativa cachen som försvårar uppräkning (enumeration) av identifierare. |
| `tenant.cache.store` | `null` | `TENANT_CACHE_STORE` | Cache-store; tom = applikationens standard. | I produktion varnar doctor när den faktiska storen är `array` eller `null`: använd redis, memcached eller database. |
| `tenant.cache.ttl` | `60` | `TENANT_CACHE_TTL` | Antal sekunder som en uppslagen tenant (id, status, publikt id, slug, namn) cachas. | Avstängningar som görs utanför `Tenant`-modellen slår igenom inom denna tid; att spara modellen tömmer cachen omedelbart. |
| `tenant.cache.negative_ttl` | `5` | `TENANT_CACHE_NEGATIVE_TTL` | Antal sekunder som en okänd identifierare kommas ihåg. | `0` stänger av negativ cachning. En tenant som testas innan den skapats är ”okänd” under upp till denna tid. |
| `tenant.cache.prefix` | `efnext:tenant:` | — | Prefix för cache-nycklar; nycklarna är `prefix + sha256(identifier)`. | Råa identifierare förekommer aldrig i cache-nycklar. |

### Medlemskap och roller

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `tenant.memberships.model` | `…\TenantMembership` | — | Medlemskapsmodell (en subklass går bra). | — |
| `tenant.memberships.table` | `tenant_memberships` | — | Medlemskapstabell. | — |
| `tenant.memberships.user_key_type` | `unsignedBigInteger` | `TENANT_USER_KEY_TYPE` | Typ för `users.id`: `unsignedBigInteger`, `uuid`, `ulid`, `string`. | **Läses endast vid migreringstillfället.** Måste matcha users-tabellen innan den första `migrate`. |
| `tenant.memberships.roles` | `['owner', 'admin', 'member', 'viewer']` | — | Rollvokabulär, högst först. | En roll utanför denna lista kan inte beviljas och uppfyller aldrig en kontroll; en tom lista kastar ett undantag vid uppstart. |
| `tenant.memberships.hierarchical` | `true` | — | En krävd roll uppfylls av den rollen eller av en högre roll. | `false` begränsar åtkomsten: `tenant.role:admin` avvisar då även owners. |
| `tenant.memberships.default_role` | `member` | — | Roll som ges av `grantTenantMembership()` utan en uttrycklig roll. | Håll den lågprivilegierad; måste vara en av `roles`. |

### Kö

| Nyckel | Standard | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- |
| `tenant.queue.propagate` | `true` | Bäddar in den aktiva tenanten i varje köat jobbs payload och återställer den innan jobbet körs. | Med `false` körs jobb utan tenant och träffar på fail closed (nekar hellre än släpper igenom) så fort tenant-bunden data berörs — det är det avsedda resultatet; gör tenanten uttrycklig med `TenantAware`. En `withoutTenant()`-bypass propageras medvetet **inte**. |

### API-tokens (Sanctum, valfritt)

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `tenant.tokens.require_binding` | `false` | `TENANT_TOKENS_REQUIRE_BINDING` | Om `tenant.token` avvisar tokens som inte har någon `tenant:<id>`-ability. | Standard är tillåtande. Oavsett detta måste en token som *faktiskt* har tenant-abilities innehålla den aktiva tenanten (jämförelse i konstant tid); cookie-sessioner påverkas aldrig. Kontrolleras endast på routes som använder `tenant.token`. |

## `audit.php`

### Lagring

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `audit.connection` | `null` | `AUDIT_DB_CONNECTION` | Dedikerad anslutning för audit-tabellerna; tom = standard. Minimala rättigheter: `SELECT, INSERT` på `audit_events`, `audit_anchors`, `audit_verification_runs`; `SELECT, INSERT, UPDATE` på `audit_chain_heads`. | Drivrutinen för denna anslutning avgör för produktion: SQLite eller något annat än pgsql/mysql/mariadb vägrar starta. En anslutning som skiljer sig från applikationens är en WARN från doctor: `Audit::transaction()` är då inte atomär över båda. |
| `audit.table` | `audit_events` | — | Hashkedjade händelser. | Append-only-triggers installeras här. |
| `audit.heads_table` | `audit_chain_heads` | — | En rad per kedja (`last_sequence`, `last_hash`), låst med `FOR UPDATE` vid varje skrivning. | Den enda audit-tabell som legitimt uppdateras; ingen trigger. |
| `audit.anchors_table` | `audit_anchors` | — | `database`-ankarlagret. | Triggers installerade; ankare här räknas aldrig som *externa*. |
| `audit.verification_runs_table` | `audit_verification_runs` | — | Körningar av `audit:verify` och doctors tail-kontroller. | Läses av doctors `verification_recency`. |

### Framtvingande av append-only

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `audit.enforce_append_only` | `true` | `AUDIT_ENFORCE_APPEND_ONLY` | Installerar `BEFORE UPDATE/DELETE`-triggers på events och anchors (PostgreSQL, MySQL, MariaDB, SQLite; andra drivrutiner avbryter migreringen). | **Läses vid migreringstillfället**; `false` loggar en tydlig varning och installerar ingenting, och att slå på den igen kräver migreringen på nytt. I produktion vägrar `false` att starta och är ett FAIL i doctor. Sätt den aldrig till false bara för att få en miljö att fungera. |

### Tenant-policy

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `audit.tenant_policy` | `required` | `AUDIT_TENANT_POLICY` | `required`: varje händelse behöver en tenant (från posten eller den aktiva kontexten); `nullable`: händelser utan tenant bildar `global`-kedjan. | Fail closed: alla värden utom det bokstavliga `nullable` beter sig som `required`. `Audit::action()->global()` och tenant-paketets säkerhetshändelser hamnar alltid i den globala kedjan oavsett policy. |

### Skrivning

| Nyckel | Standard | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- |
| `audit.write.attempts` | `5` | Nya försök av writerns egen transaktion vid deadlocks/serialiseringsfel, med växande jitter. | Endast när writern äger transaktionen; inuti din egen körs den bara en gång — vilket är varför affärsskrivningar och audit-skrivningar hör hemma i `Audit::transaction()`. |

### Signering

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `audit.signing.keys` | `''` | `AUDIT_SIGNING_KEYS` | `k1:base64:<32+ byte>[,k2:base64:…]`; generera med `audit:key:generate`. | Tom → händelser hashas men **signeras inte**; produktion vägrar starta, FAIL i doctor. Felformade poster, id:n utanför `[A-Za-z0-9_.-]{1,32}` eller nycklar under 32 byte kastar `InvalidSigningKey`. Håll nycklarna i en hemlighetshanterare (secret manager), aldrig bredvid databasens autentiseringsuppgifter. |
| `audit.signing.active_key` | `''` | `AUDIT_SIGNING_ACTIVE_KEY` | Nyckel som används för nya signaturer; de övriga används endast för verifiering. | Rotation = lägg till den nya nyckeln, peka denna mot den, låt den gamla stå kvar i listan. En aktiv nyckel som inte finns i nyckelringen kastar ett undantag. |

### Ankring

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `audit.anchoring.stores` | `['database']` | `AUDIT_ANCHOR_STORES` (kommaseparerad) | Ankarlager som `audit:anchor` skriver till, externt lager först: `database`, `filesystem`, `log`, eller klassnamnet på ditt eget `AnchorStore`. Ett tomt värde faller tillbaka på `database`. | `log` och `NullAnchorStore` (och dess subklasser) är endast skrivbara och räknas aldrig som läsbara; utan ett läsbart lager vägrar produktion att starta. Enbart database ger WARN i doctor, FAIL under `require_external_anchor` eller `--strict`. **Att peka `AUDIT_ANCHOR_DISK` mot en bucket gör ingenting i sig själv**; `filesystem` måste finnas i denna lista. |
| `audit.anchoring.filesystem.disk` | `local` | `AUDIT_ANCHOR_DISK` | Laravel-disk för `filesystem`-lagret (ett JSON-dokument per ankare). | `local` ligger på applikationsvärden, vilket inte är oberoende av databasvärden. Det dokumenterade målet är objektlagring med Object Lock (compliance mode, retention ≥ audit-retentionen). Under `require_external_anchor` måste disken finnas och kunna instansieras vid uppstart. |
| `audit.anchoring.filesystem.path` | `audit-anchors` | `AUDIT_ANCHOR_PATH` | Katalogprefix på disken. | — |
| `audit.anchoring.log.channel` | `null` | `AUDIT_ANCHOR_LOG_CHANNEL` | Loggkanal för det skrivbara `log`-lagret; tom = standardloggern. | Kan inte uppfylla kravet på läsbarhet eller externt lager; vidarebefordra den till ett SIEM. |

### Verifiering

| Nyckel | Standard | Syfte |
| --- | --- | --- |
| `audit.verification.chunk_size` | `1000` | Händelser som laddas per fråga vid genomgång av en kedja. Endast prestanda. |

### Tröskelvärden för `security:doctor`

| Nyckel | Standard | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- |
| `audit.doctor.max_anchor_age_minutes` | `60` | En kedja vars huvud inte är förankrat och vars nyaste händelse är äldre än detta → FAIL `anchor_freshness`. | Sätt den till några multiplar över intervallet för `audit:anchor`. Under `require_external_anchor` räknas endast externa ankare. |
| `audit.doctor.max_verification_age_hours` | `24` | Ingen intakt fullständig verifiering inom detta fönster → WARN; en senaste körning med avvikelser → FAIL oavsett ålder. | Inte kortare än schemat för `audit:verify`. |
| `audit.doctor.tail_events` | `200` | Händelser per kedja som verifieras på nytt direkt av varje doctor-körning (hoppas över med `--no-verify`). | Kostnaden växer med värdet; den ersätter inte `audit:verify`. |

### Fail-fast i produktion

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `audit.production.fail_fast` | `true` | `AUDIT_FAIL_FAST` | Vid `APP_ENV=production`, vägra starta med en konfiguration som tar bort garantierna (ingen nyckel, SQLite, append-only avstängt, inget läsbart ankarlager) genom att kasta `InsecureConfiguration`. | Ingen effekt utanför produktion. `false` är det medvetna, tillfälliga undantaget och ett WARN i doctor. |
| `audit.production.require_external_anchor` | `false` | `AUDIT_REQUIRE_EXTERNAL_ANCHOR` | Högsäkerhetsprofil: produktion vägrar också starta om inte ett läsbart lager *utanför* audit-databasen är konfigurerat och svarar. | Doctor: enbart database blir FAIL, färskhet räknar endast externa ankare, externa lager läs-testas. `security:doctor --strict` misslyckas på `anchor_stores` medan denna är avstängd — ett lager som bara är konfigurerat kan senare tas bort utan att något vägrar starta. |

### Request-kontext

| Nyckel | Standard | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- |
| `audit.request.enrich` | `true` | Bifogar request-id, correlation-id, klient-IP och user agent till varje händelse. | De fyra fälten ingår i hashen. Skrivningar från konsol och kö tillskrivs aldrig Laravels platshållar-request. Värdena är längdbegränsade (64/64/45/255) och rensade från kontrolltecken. |
| `audit.request.request_id_header` | `X-Request-Id` | Header som lagras som `request_id`. | Kontext som anges av klienten, inte autentisering. |
| `audit.request.correlation_id_header` | `X-Correlation-Id` | Header som lagras som `correlation_id`. | Detsamma. |

### Sanering av metadata

Nycklar tokeniseras (`passwordConfirmation` → `password`, `confirmation`;
`x-api-key` → `x`, `api`, `key`); en nyckel redigeras bort (redactas) när
ett av mönstren förekommer som en sammanhängande sekvens av tokens, så
`author` och `session_count` överlever medan `session_id` och `apiKey` inte
gör det. Värden skannas också efter hemlighetsliknande former (JWT:er,
bearer-strängar, PEM-nycklar, kortnummer, cloud-åtkomstnycklar).

| Nyckel | Standard | Env | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- | --- |
| `audit.sanitizer.marker` | `[REDACTED]` | — | Ersättning för redigerade (redactade) nycklar och hemlighetsliknande värden. | Varje borttagning lämnar en markör, så loggen visar att något fanns där. |
| `audit.sanitizer.redact_key_patterns` | 34 patterns (`password`, `secret`, `token`, `api key`, `private key`, `authorization`, `cookie`, `otp`, `iban`, `card number`, `cvv`, `pin`, …) | — | Nycklar vars tokensekvens matchar ersätts med markören. | Att ta bort poster utökar vad som når den oföränderliga loggen. Metadata saneras, inte krypteras. |
| `audit.sanitizer.pseudonymize_key_patterns` | `email`, `e mail`, `mail`, `username`, `user name`, `login`, `phone`, `mobile`, `identifier` | — | Strängvärden under matchande nycklar blir `<key>_hash` = HMAC-SHA256 med pepparen. | Endast strängvärden; utan en peppar blir värdet markören, aldrig en hash som går att ordboksattackera i klartext. |
| `audit.sanitizer.allow_keys` | `token_type`, `email_verified`, `email_verified_at`, `login_count`, `mail_driver` | — | Exakta nycklar som aldrig redigeras bort eller hashas *baserat på nyckeln*. | Värden skannas ändå efter hemlighetsliknande former. Varje tillägg är ett medvetet hål. |
| `audit.sanitizer.max_depth` | `6` | — | Djupare arrayer blir `['_depth_limited' => true]`. | Begränsar payloadens storlek. |
| `audit.sanitizer.max_keys` | `100` | — | Nycklar per array / poster per lista; resten släpps och märks `_truncated`. | Detsamma. |
| `audit.sanitizer.max_string_length` | `1024` | — | Längre strängar klipps av och får suffixet `…[truncated]`. | Detsamma; begränsar hur mycket av en oavsiktligt loggad blob som sparas. |
| `audit.sanitizer.pseudonym_key` | `null` | `AUDIT_PSEUDONYM_KEY` | Peppar; tom = en nyckel härledd från `APP_KEY` (HKDF). | Att rotera den är det närmaste loggen kommer crypto-shredding. Förvara den tillsammans med signeringsnycklarna. |

### Tenant-säkerhetshändelser

| Nyckel | Standard | Syfte | Säkerhetseffekt |
| --- | --- | --- | --- |
| `audit.record_tenant_events` | `true` | Loggar automatiskt `TenantAccessDenied`, `TenantScopeBypassed`, `MembershipChanged` och `TenantSwitched` som `tenant.access_denied`, `tenant.scope_bypassed`, `tenant.membership.*`, `tenant.switched`. | Med `false` loggas kringgåenden via `Tenancy::withoutTenant()` inte längre — den egenskap som frameworket förlitar sig på när det tillåter tenant-överskridande arbete. Händelser utan tenant hamnar i den globala kedjan oavsett policy. |

## Nycklar utan miljövariabel

Ändra dessa endast i den publicerade konfigurationsfilen: `tenant.model`,
`tenant.table`, `tenant.column`, `tenant.resolution.*`, `tenant.lookup.*`,
`tenant.cache.prefix`,
`tenant.memberships.{model,table,roles,hierarchical,default_role}`,
`tenant.queue.propagate`,
`audit.{table,heads_table,anchors_table,verification_runs_table}`,
`audit.write.attempts`, `audit.verification.chunk_size`, `audit.doctor.*`,
`audit.request.*`, `audit.sanitizer.*` (utom `pseudonym_key`),
`audit.record_tenant_events`.
