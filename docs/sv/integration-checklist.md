<!-- source: docs/en/integration-checklist.md sha256: 18810320758af195a9f8bdc338a11123c08b41dfe93e32c09726a33bce118feb -->
# Checklista inför driftsättning

*Svensk utgåva — den [engelska versionen](../en/integration-checklist.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Gå igenom detta före den första driftsättningen och igen före varje release
som rör tenancy eller auditing.

## Installation

- [ ] `composer require "efnext/security-framework:^1.0@beta"` (förhandsversion: stabilitetsflaggan krävs, se README); de tre providers upptäcks automatiskt.
- [ ] Konfiguration publicerad och granskad: `tenant.php`, `audit.php`, `addressing.php`.
- [ ] `SECURITY_SERVICE_NAME` satt till denna tjänsts namn (globala objekt-id:n).
- [ ] `tenant.memberships.user_key_type` matchar din users-tabells primärnyckel.
- [ ] Migrationer körda i varje miljö; audit-migrationen lyckades (den avbryter vid databasmotorer som inte stöds eller saknade triggerbehörigheter).
- [ ] Utdata från `php artisan audit:key:generate` sparad i hemlighetshanteraren; `AUDIT_SIGNING_KEYS` och `AUDIT_SIGNING_ACTIVE_KEY` finns i varje miljö.
- [ ] `audit.anchoring.stores` inkluderar ett läsbart lager utanför databasen (`filesystem` på en object locked bucket), listat först; `log` är ett bra tillägg men endast skrivbart.
- [ ] `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` i produktion så snart det lagret finns, så att en driftsättning utan det inte kan starta.
- [ ] `php artisan security:doctor --strict` går igenom i varje miljö som är avsedd att vara produktionslik, och driftsättningspipelinen kör den efter varje utrullning och stoppar vid exit 1 (strict = high-security-profilen: varningar är fel och `AUDIT_REQUIRE_EXTERNAL_ANCHOR` måste vara på).

## Modeller

- [ ] Varje tenant-bunden modell använder `BelongsToTenant`; dess tabell skapades med `$table->tenantId()`.
- [ ] Relationer mellan tenant-bundna tabeller använder `$table->tenantForeignId()` / `tenantForeignUuid()` / `tenantForeignUlid()` (sammansatta främmande nycklar), inte vanlig `foreignId()`.
- [ ] Valfria relationer är `tenantForeignId(..., nullable: true)` med `cascadeOnDelete()`/`restrictOnDelete()`; ingen `nullOnDelete()` på en sammansatt tenant-nyckel (nekas vid migrationstillfället).
- [ ] Varje modell som medvetet är global listas i en kommentar eller ett arkitekturtest.
- [ ] Ingen tenant-bunden modell listar tenant-kolumnen i `$fillable`.
- [ ] Modeller som exponeras i URL:er använder `HasPublicId` och `$table->publicId()`.
- [ ] Användarmodellen använder `HasTenantMemberships`.

## Routes

- [ ] Varje route som läser eller skriver tenant-data ligger inom `Route::middleware(['auth…', 'tenant'])`.
- [ ] Privilegierade routes lägger till `tenant.role:<roles>`.
- [ ] API-routes som betjänas med Sanctum-tokens lägger till `tenant.token` (och överväg `tenant.tokens.require_binding=true`).
- [ ] Tenant-väljare/-växlare använder `SessionTenant::switchTo()`; inget annat skriver sessionsnyckeln.
- [ ] Health checks, webhooks och andra tenant-lösa routes rör inte tenant-bundna modeller (de skulle få `TenantContextMissing`).

## Bakgrundsarbete

- [ ] Jobb som måste köras för en fast tenant implementerar `TenantAware` och använder `TenantContextMiddleware`.
- [ ] Schemalagda kommandon som rör tenant-data körs via `tenant:run <tenant> <command>` eller kapslar in sin logik i `Tenancy::run()`.
- [ ] Tenant-övergripande underhåll använder `Tenancy::withoutTenant('reason', …)` med en meningsfull anledning; blocket är så litet som möjligt.
- [ ] Octane: inget annat registrerar ett långlivat tenant-tillstånd; providers rensar kontexten vid requestens avslut.

## Auditing

- [ ] Säkerhetsrelevanta affärshandlingar anropar `Audit::action(…)->record()` (skapande/uppdatering/radering av viktiga resurser, exporter, behörighetsändringar, inloggningar och misslyckanden).
- [ ] Audit-skrivningar som måste vara atomiska med affärsförändringen körs inom `Audit::transaction()`; closuren innehåller inga mejl, API-anrop eller andra icke-transaktionella sidoeffekter.
- [ ] Tenants provisioneras med `Audit::prepareChain($tenantId)` när deras första audit-händelser skrivs inom större transaktioner under belastning.
- [ ] Metadata innehåller aldrig rå request-indata; hemligheter skickas inte in; personuppgifter går via pseudonymiserade nycklar eller refereras via publikt id.
- [ ] `audit:verify` schemalagd (t.ex. varje timme), `audit:anchor` schemalagd (t.ex. var femte minut) och `security:doctor` schemalagd eller inkopplad i övervakningen; en misslyckad körning larmar någon.
- [ ] `ChainViolationDetected` har en lyssnare som larmar.
- [ ] Policy för lagringstid och radering dokumenterad (se "Vad ramverket inte gör").

## Regler för kodgranskning

- [ ] Grepa efter `withoutGlobalScope(` och `TenantScope::class`: varje träff behöver en motivering.
- [ ] Grepa efter `DB::table(` och `DB::select(` mot tenant-bundna tabeller: varje träff behöver ett explicit tenant-filter.
- [ ] Grepa efter `withoutTenant(`: varje träff har en specifik anledningssträng och en liten closure.
- [ ] Grepa efter `->forceFill(` på tenant-bundna modeller: ingen rör tenant-kolumnen.

## Tester du bör äga

- [ ] En request från en medlem av tenant B till en resurs hos tenant A returnerar 403/404 och genererar en audit-händelse.
- [ ] Ett kommando som körs utan `tenant:run` misslyckas med `TenantContextMissing` (ja, ett misslyckat test är poängen).
- [ ] Ett manipulerat `actor_id` i `audit_events` rapporteras av `audit:verify`.
