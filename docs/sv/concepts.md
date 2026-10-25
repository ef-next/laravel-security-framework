<!-- source: docs/en/concepts.md sha256: 07a994b2b1ad95a9ac507c2b3d25af1a88c8a2318f4c1a6b8953930efef0e322 -->
# Koncept

*Svensk utgåva — den [engelska versionen](../en/concepts.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Idéerna bakom de tre paketen, i den ordning du möter dem. Varje avsnitt
avslutas med vad konceptet *inte* gör; den fullständiga listan finns i
[Vad det inte gör](what-it-does-not-do.md).

## Fail closed

Ramverkets enda designregel: när en säkerhetsförutsättning saknas nekas
operationen, den försämras aldrig bara. En fråga på en tenant-bunden modell
utan en tenant kastar i stället för att returnera allt; produktion vägrar
starta utan en signeringsnyckel i stället för att skriva osignerade
händelser; ett felaktigt bildat publikt id ger 404 innan någon
databasåtkomst sker; en sammansatt främmande nyckel som inte skulle vara
portabel nekas när migrationen skrivs, inte när den körs på en annan databas.

Konsekvensen för dig: felen är högljudda och tidiga. Ett `TenantContextMissing`
i ett test är inte något att fånga upp — det pekar på en kodväg som skulle
köras utan en tenant i produktion.

## Tenant-kontext

En **tenant-kontext (tenantkontext)** är den tenant som den aktuella
arbetsenheten — HTTP-request, köat jobb, konsolkommando, closure — agerar
för. Den etableras exakt en gång per arbetsenhet och rensas efteråt:

- av middlewaren `tenant.resolve` från en *identifierare* i requesten (header
  `X-Tenant-Id`, routeparameter, session, subdomän — i den konfigurerade
  ordningen);
- av köintegrationen, från payloaden hos ett jobb som skickades inom en
  kontext;
- av `Tenancy::run($tenant, fn () => …)` eller `php artisan tenant:run`,
  explicit.

En identifierare är inte ett bevis. Upplösningen svarar bara på "vilken
tenant handlar denna request om"; `tenant.member` svarar sedan på "får den
här användaren agera i den" (aktivt, icke-utgånget medlemskap; en roll om det
krävs). Båda sker före route model binding, så `{invoice}` i en URL löses upp
*inom* tenanten.

Tenant-bundna modeller använder `BelongsToTenant`. En global scope filtrerar
varje fråga efter den aktiva tenanten; utan en kontext kastar scopet. Att
skapa stämplar tenant-kolumnen; att uppdatera och radera kontrollerar att
posten tillhör den aktiva tenanten, i PHP och igen i SQL:ens `WHERE`, så att
`saveQuietly()` eller en borttagen event-lyssnare inte förändrar något. Att
flytta en post till en annan tenant nekas.

Tenant-övergripande arbete är möjligt men explicit: `Tenancy::withoutTenant('nightly
aggregation', fn () => …)` lyfter scopet inuti closuren, skickar ett event
med orsaken och den anropande kodplatsen, och audit-paketet registrerar det.
Inuti ett sådant block håller `Model::forTenant($id)` frågorna explicita.

*Gör inte:* avgränsar `DB::table()`, rå SQL, eller modeller utan traiten;
avgör vad en medlem får göra *inom* en tenant — det är ditt policylager.

## Sammansatta främmande nycklar

`$table->tenantId()` lägger till `UNIQUE (tenant_id, id)` på en tenant-bunden
tabell; `$table->tenantForeignId('invoice_id', 'invoices')` på en barntabell
skapar en främmande nyckel på `(tenant_id, invoice_id)` som refererar till
`(tenant_id, id)`. Databasen själv nekar då en rad som pekar på en annan
tenants faktura — oavsett vad som skrev den. Detta är den enda spärr som
håller under Eloquent.

*Gör inte:* stoppa tenant-övergripande *läsningar* via rå SQL; tillåta
`ON DELETE SET NULL` (en sammansatt nyckel kan inte rensa bara en kolumn;
använd `nullable: true` med cascade eller restrict och koppla loss genom
uppdatering).

## Publika och globala id:n

Interna auto-increment-id:n läcker volym och inbjuder till enumerering.
`HasPublicId` ger en modell ett ogissningsbart, läsbart id med ett
typprefix (`inv_7f3k9zq2m4x8c1bt`, 80 bitar slumpmässighet), tilldelat vid
skapande, oföränderligt, används automatiskt av route model binding.
`HasGlobalObjectId` utökar det till ett namn som är unikt över tjänster:
`billing:invoice:inv_7f3k9zq2m4x8c1bt` — den form andra tjänster,
revisionsspår och supportärenden bör använda.

*Gör inte:* auktorisera något. Att känna till ett id är inte behörighet;
tenant-middlewaren och dina policyer avgör fortfarande.

## Manipulationsdetekterande audit-logg

Varje audit-händelse hör till en **kedja** — en per tenant plus en `global`-kedja
— och bär ett sekvensnummer, hashen för sin föregångare, sin egen hash
över en kanonisk JSON-kodning av allt som beskriver den (aktör, action,
resurs, utfall, metadata, request-kontext, tidsstämpel), och en
HMAC-signatur av den hashen med en nyckel som lever utanför databasen.

Tre lager gör förändringar synliga:

1. **Databastriggrar** nekar `UPDATE` och `DELETE` på händelser och ankare. En
   superanvändare kan ta bort dem — vilket är varför de inte är det enda
   lagret.
2. **Hashkedja och signatur.** Att skriva om en händelse bryter hashen för
   varje senare händelse; att räkna om alla hashar är möjligt för någon med
   databasåtkomst, men signaturerna kräver nyckeln. `audit:verify` går
   igenom kedjorna och rapporterar varje inkonsekvens.
3. **Ankare.** `audit:anchor` skriver det aktuella huvudet för varje kedja
   (sekvens och hash) till ett ankarlager. Ett lager *utanför* databasen —
   objektlagring med Object Lock, en WORM-logg — är det som gör att
   fullständiga omskrivningar och trunkering blir upptäckbara: verifieraren
   jämför kedjan mot ankaret.

Verifieraren och `security:doctor` är hur du *tar reda på det*. Koppla
`ChainViolationDetected` till din larmning.

*Gör inte:* förhindra manipulation (den gör den upptäckbar); signera eller
ankra utan schemaläggaren; hålla metadata privat (den saneras, krypteras
inte); lösa radering (händelser är oföränderliga — håll personuppgifter
borta från dem eller pseudonymisera).

## Aktörer, metadata, sanering

Aktören är den autentiserade användaren (eller modellens egen
`toAuditActor()`), `anonymous` för oautentiserade requester, `system('console')`
utanför HTTP, eller vad `->by()` säger. Metadata är vad du än skickar till
`->with()` — efter att saneraren har redigerat bort nycklar och värden som
ser hemliga ut (`password`, `token`, JWT-former, kortnummer…), pseudonymiserat
identifierare (`email` → `email_hash`) och begränsat djup, storlek och
strängängd. Skicka de fält händelsen handlar om, aldrig `$request->all()`.

## Garanti → villkor → kvarstående risk → diagnos

Varje löfte i denna dokumentation har den formen, eftersom en
säkerhetsegenskap som håller "för det mesta" inte är en egenskap.
README-filens tabell över garantier namnger villkoret för varje;
`security:doctor` kontrollerar de villkor den kan kontrollera; hotmodellen
namnger vad som återstår. När du utökar ramverket, behåll formen.

## Ordlista

| Term | Betydelse |
| --- | --- |
| tenant-kontext | tenanten den aktuella arbetsenheten agerar för |
| resolver | hittar en tenant-*identifierare* i en request |
| locator | mappar en identifierare till en tenant-post (id, status, publikt id, slug) |
| medlemskap | en användares relation till en tenant: roll, status, utgångsdatum |
| kedja | sekvensen av audit-händelser för en tenant (eller `global`) |
| huvud | den sista sekvensen och hashen i en kedja, sparad i `audit_chain_heads` |
| ankare | en kopia av ett huvud, signerad, lagrad (helst) utanför databasen |
| verifieringskörning | en registrerad genomgång av `audit:verify` eller doctorn över en kedja |
| publikt id | `prefix_body`, ogissningsbar identifierare per modell |
| globalt objekt-id | `service:type:public_id`, unikt över tjänster |
| strikt profil | `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` framtvingat vid start, `security:doctor --strict` som deployment-gate |
