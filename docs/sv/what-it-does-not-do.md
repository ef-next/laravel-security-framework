<!-- source: docs/en/what-it-does-not-do.md sha256: 370b4e7766b0ea08f339a8ef6e93f6cd3b254ed23216482d5700abdf7aab92c9 -->
# Vad ramverket inte gör

*Svensk utgåva — den [engelska versionen](../en/what-it-does-not-do.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Detta är den viktigaste sidan i dokumentationen. Varje punkt här är en
medveten gräns, inte en bugg. Din applikation måste täcka den.

## Tenant-isolering

- **Den filtrerar inte query builder.** `DB::table('invoices')`, `DB::select()`, rå SQL och frågor i databasvyer eller lagrade procedurer berörs inte. Endast Eloquent-modeller som använder `BelongsToTenant` avgränsas. De sammansatta främmande nycklarna från `$table->tenantForeignId()` är den enda spärr som håller under Eloquent: de stoppar tenant-övergripande *relationer*, inte tenant-övergripande *läsningar*.
- **Den stoppar inte `withoutGlobalScope(TenantScope::class)`.** Det är Eloquents egen flyktväg. Behandla varje förekomst som ett fynd vid kodgranskning och använd `Tenancy::withoutTenant('reason', …)` i stället; den senare skickar ett event och skrivs till audit-loggen.
- **Den rapporterar inte eventlösa skrivningar den blockerat.** En modell som laddats i en annan tenants kontext och skrivs med events aktiverade kastar `TenantMismatch`. Utan events (`saveQuietly()`, `withoutEvents()`) körs samma kontroll från sparfrågan och kastar också, utom när tenant-kolumnen inte valdes ut; då avgränsas `UPDATE`/`DELETE` till den aktiva tenanten och påverkar helt enkelt ingen rad, och Eloquent rapporterar ändå framgång. Välj ut tenant-kolumnen (den är med som standard) om du behöver exceptionen.
- **Den avgränsar inte `fresh()`, `refresh()` eller köåterställning av en modellinstans du redan har.** De laddas om via primärnyckel utan tenant-filtret; du får bara tillbaka posten du redan hade. Att skriva den är skyddad som ovan.
- **Den avgränsar inte upsert-konfliktdetektering.** `upsert()` stämplar den aktiva tenanten på raderna den infogar och uppdaterar aldrig tenant-kolumnen vid konflikt, men databasen matchar konflikter mot vilket unikt index som helst. Gör de unika indexen på tenant-bundna tabeller tenant-kvalificerade (`unique(['tenant_id', 'number'])`) och skicka en tenant-kvalificerad `$uniqueBy`. `insertUsing()` (subquery-infogningar) är inte skyddad alls.
- **Den vet inte vilka modeller som är tenant-bundna.** En modell utan traiten är global. Lägg till traiten, eller lägg till arkitekturtestet från `tests/Arch` i din egen svit för att explicit lista globala modeller.
- **Den avgränsar inte relationer till globala modeller.** Om `Invoice` (avgränsad) tillhör `Currency` (global) är valutan synlig för alla, som avsett. Om `Invoice` tillhör `Customer` och `Customer` saknar traiten är det en bugg i din modell, inte något ramverket kan se.
- **Den auktoriserar inte handlingar inom en tenant.** Medlemskap och roller svarar på "får den här användaren agera för denna tenant över huvud taget?". Om en medlem får radera just denna faktura är din policys jobb (Gates, Policies).
- **Den binder inte sessioner till tenants av sig själv.** Sessionsresolvern läser bara ett värde som `SessionTenant::switchTo()` skrev efter en medlemskapskontroll. Om du skriver sessionsnyckeln själv kringgår du den kontrollen.
- **Den skyddar inte mot en komprometterad applikationsserver.** Kod som körs inuti din process kan anropa `Tenancy::withoutTenant()`; ramverket gör det synligt, inte omöjligt.
- **Den flyttar inte data mellan tenants.** Tenant-kolumnen är oföränderlig. Migreringar mellan tenants är en explicit, granskad operation du skriver själv med `Tenancy::withoutTenant()` och rå insert.

## Audit-logg

- **Den förhindrar inte manipulation; den gör manipulation upptäckbar.** Databastriggrar stoppar tillfälliga `UPDATE`/`DELETE`. En databassuperanvändare kan ta bort dem. Hashkedjan, HMAC-signaturen och de externa ankarna finns så att detta lämnar bevis när `audit:verify` körs. Kör den.
- **Den är bara lika manipulationsdetekterande som nyckeln och ankarna är separerade från databasen.** Om `AUDIT_SIGNING_KEYS` finns på samma ställe som databasuppgifterna, eller ankarna bara lagras i databasen, kan en angripare med båda skriva om historiken. Placera nyckeln i en hemlighetshanterare (secret manager) och ankra till objektlagring med object lock eller till en extern loggmottagare.
- **Den signerar eller ankrar inte automatiskt.** Signering sker vid varje skrivning så snart en nyckel är konfigurerad; ankring sker när `php artisan audit:anchor` körs. Schemalägg den.
- **Den registrerar inget du inte säger till den.** Förutom tenant-paketets säkerhetshändelser (nekanden, kringgåenden, medlemskapsändringar, byten) är varje affärshändelse ett explicit `Audit::action(…)->record()`-anrop. Model-observers är ditt val.
- **Den gör inte audit-skrivningen atomisk med din affärsskrivning av sig själv.** På samma anslutning, wrappa båda i ett `Audit::transaction(fn () => …)` när audit-posten måste finnas om och endast om affärsförändringen gör det — det är `DB::transaction()` plus de återförsöks- och backoff-regler kedjan behöver, så använd den och inte det bara Laravel-anropet; utanför en transaktion commitas audit-posten på egen hand. Med `AUDIT_DB_CONNECTION` pekande på en separat anslutning finns det ingen delad transaktion alls, så en krasch mellan de två skrivningarna kan lämna en utan den andra.
- **Den garanterar inte metadatas sekretess.** Saneraren redigerar bort vanliga hemliga nycklar och hemlighetsformade värden och pseudonymiserar identifierare. Den kan inte känna igen varje hemlighet. Skicka inte `$request->all()`.
- **Den löser inte GDPR-radering.** Metadata är oföränderlig. Personuppgifter hör hemma i de pseudonymiserade fälten (nyckelbaserad hash) eller utanför loggen, refererade via publikt id. Att radera en person betyder då att radera den refererade posten, inte audit-händelsen. Att rotera `AUDIT_PSEUDONYM_KEY` efter en lagringsperiod är det närmaste kryptografisk strimling designen erbjuder; planera lagringstid innan du går live.
- **Den lagrar inte resursens tidigare tillstånd.** Lägg diffen i metadata om du behöver det.
- **Den verifierar inte kedjor vid varje skrivning.** Verifiering är en schemalagd, uppdelad genomgång (`audit:verify`); `security:doctor` verifierar bara svansen av varje kedja på plats.
- **Den gör inte dina transaktioner idempotenta.** `Audit::transaction()` kör om hela closuren vid samtidighetskonflikter. Sidoeffekter som inte är del av databastransaktionen (mejl, HTTP-anrop, könedstoppningar utanför transaktionen) skulle upprepas; håll dem utanför closuren.
- **Den ersätter inte övervakning.** `security:doctor` talar om för dig om installationen fortfarande håller garantierna; någon måste köra den och läsa resultatet. En saknad signeringsnyckel eller en borttagen trigger är tyst tills dess, utom vid start i produktion (fail-fast).

## Publika identifierare

- **De är ingen auktorisering.** Ett ogissningsbart id stoppar enumerering. Det stoppar inte någon som fått tag i id:t från en logg, en referrer-header, ett supportärende eller en export. Varje route behöver fortfarande tenant-scopet och dina policyer.
- **De tilldelas inte vid bulk-infogningar.** `Model::insert()` och `upsert()` kringgår Eloquent-events. Generera id:n med `PublicIdGenerator` först.
- **De är inte unika utan ett index.** `generateUnique()` är check-then-insert. `$table->publicId()` lägger till det unika indexet; ta inte bort det.

## Drift

- **Ingen hastighetsbegränsning.** 404:or för okända tenant-identifierare och 403:or för nekade medlemskap är billiga, men en scanner kan ändå generera dem. Hastighetsbegränsa vid kanten.
- **Ingen multi-databas-tenancy.** En databas, ett schema, en tenant-kolumn. Databas-per-tenant är en annan produkt.
- **Inget UI för tenant-provisionering, inbjudningar, fakturering eller SSO.** Medlemskap är en tabell och ett API.
- **Inget stöd för MySQL/MariaDB utan triggerbehörigheter.** Migrationen behöver `CREATE TRIGGER` (med binär loggning: `SUPER` eller `log_bin_trust_function_creators=1`). Den misslyckas högljutt hellre än att migrera utan skydd.
