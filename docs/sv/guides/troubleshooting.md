<!-- source: docs/en/guides/troubleshooting.md sha256: 7e0ed631e9264a8f20f5d3f788df4cbd487226a91502ee3c4be9e7c37c825688 -->
# Felsökning

*Svensk utgåva — den [engelska versionen](../../en/guides/troubleshooting.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Efter symptom. Varje meddelande från ramverket anger vad som är fel och, där
det är meningsfullt, vad du ska göra; den här sidan lägger till sammanhanget.
Är du osäker, kör `php artisan security:doctor` — dess utdata är det första
du ska bifoga i ett issue.

## Förfrågningar

| Symptom | Trolig orsak | Diagnostisera | Åtgärd |
| --- | --- | --- | --- |
| `400 No tenant identifier found in the request (checked: …)` | klienten skickar ingen `X-Tenant-Id` (eller route-parameter/session/subdomän, beroende på vad kedjan listar) | meddelandet listar de källor som kontrollerades | skicka identifieraren; för routes som kan köras utan en tenant, använd `tenant.resolve:optional` |
| `404 Tenant not found.` | okänd identifierare, eller en giltig identifierare i ett format som uppslagningen inte accepterar, eller en slug-kolumn som inte heter `slug` | `php artisan tinker` → `app(TenantLocator::class)->locate('acme')` | kontrollera `tenant.lookup.by` och `slug_column`; identifieraren måste vara UUID, `ten_…`, `<service>:tenant:ten_…` eller en slug |
| `403 Tenant is suspended.` / `archived` | tenantens status är inte `active` — eller statuskolumnen innehåller ett värde som ramverket inte känner till (fail closed, dvs. nekar hellre än släpper igenom → suspended) | titta på raden | sätt `status = active`; kontrollera `tenant.lookup.status_column` |
| `403 You are not a member of this tenant.` | korrekt beteende för icke-medlemmar; även för ett medlemskap med fel typ på `user_id` | `$user->tenantMembership($tenantId)` | bevilja medlemskapet; kontrollera att `TENANT_USER_KEY_TYPE` matchade `users.id` vid migreringstillfället |
| `403 Your membership … has expired / is not active` | `expires_at` i det förflutna eller status `invited`/`suspended` | som ovan | förnya eller aktivera |
| `403 Your role … does not permit this action.` | `tenant.role:x` med en roll som inte är `x` eller högre | `hasTenantRole()` | bevilja rollen; kontrollera `memberships.hierarchical` |
| `403 This API token is not valid for this tenant.` | token bunden till en annan tenant (ability `tenant:<uuid>`) | avkoda tokenens abilities | utfärda en token för denna tenant; ability måste ha UUID:t, inte sluggen |
| `403 This API token is not bound to a tenant.` | `TENANT_TOKENS_REQUIRE_BINDING=true` och en obunden token | — | utfärda bundna tokens; se audit-loggen för vem som fortfarande använder obundna |
| `401` på en tenant-route | oautentiserad, eller att `auth` saknas före `tenant` | — | `['auth:sanctum', 'tenant']` |
| `404` för ett id som existerar | felformaterat publikt id (ingen fråga görs), fel prefix, eller att posten tillhör en annan tenant (korrekt) | `PublicId::tryFromString($value)` | använd id:t från API-svaret; kontrollera den aktiva tenanten |
| `500 TenantContextMissing` i en controller | `tenant.resolve` saknas, eller att en anpassad middleware-ordning placerar route model binding före den | `security:doctor` → `middleware_priority`; routens middleware-lista | använd gruppen `tenant`; ändra inte Laravels middleware-prioritet för hand |

## Kod och tester

| Symptom | Trolig orsak | Åtgärd |
| --- | --- | --- |
| `TenantContextMissing: cannot query <Model> without an active tenant` i ett jobb, kommando eller test | kodväg utan en tenant | `Tenancy::run()`, ett `TenantAware`-jobb, `tenant:run`, eller ett motiverat `Tenancy::withoutTenant('reason', …)` — fånga aldrig undantaget |
| `TenantMismatch: Refusing to update <Model> owned by tenant A while tenant B is active` | en modell som laddats i en kontext och skrivs i en annan (köad modell, cachad instans, test-hjälpare) | håll arbetsenheten inom en enda `Tenancy::run()` |
| `TenantColumnImmutable` | koden försöker flytta en post mellan tenants, eller ett `update()` rör vid `tenant_id` | återskapa i måltenanten; ta bort kolumnen från update-anropet |
| `LogicException: … does not guard the tenant column` | en anpassad Eloquent-builder på en `BelongsToTenant`-modell | `use GuardsTenantColumn` i buildern |
| `LogicException: A composite tenant foreign key cannot use ON DELETE SET NULL` | `nullOnDelete()` på `tenantForeign*()` | `nullable: true` tillsammans med `cascadeOnDelete()`/`restrictOnDelete()`; koppla loss genom update |
| `MissingPublicId: <Model> has no public_id yet` | `publicId()` före `save()` | skapa (spara) först |
| `InvalidPublicId … with the wrong prefix` | ett i förväg satt publikt id från en annan modelltyp | låt ramverket tilldela det |
| `InvalidAuditEntry: a tenant is required (tenant policy "required")` | audit-skrivning utanför en tenant-kontext | `->global()` för händelser som verkligen saknar tenant, `->forTenant()`, eller kör inom en kontext |
| `InvalidAuditEntry: call ->on(...)` | buildern används utan en resurs | `->on($model)` eller `->on('type', 'id')` |
| `CanonicalizationFailed` | objekt/resurser/NaN i metadata | skicka skalärer, arrayer, DateTimes |
| `AuditEventImmutable` | koden försöker uppdatera eller radera en `AuditEvent` | — (avsiktligt) |
| deadlockar / `could not serialize access` / MariaDB-fel 1020 kring audit-skrivningar | affärstransaktion med `DB::transaction()` | `Audit::transaction()`; `Audit::prepareChain()` för nya tenants |

## Migrationer och databas

| Symptom | Trolig orsak | Åtgärd |
| --- | --- | --- |
| `SQLSTATE[HY000]: … Access denied; you need (at least one of) the SUPER privilege(s) … TRIGGER` (MySQL/MariaDB) | binär loggning på, migrationsanvändare utan `SUPER` | `SET GLOBAL log_bin_trust_function_creators = 1` eller kör migrationen som en priviligierad användare |
| `UnsupportedDatabaseDriver: Append-only triggers are not implemented for the "…" driver` | SQL Server, Oracle, … | använd PostgreSQL, MySQL eller MariaDB |
| `Foreign key constraint is incorrectly formed` (errno 150) | en sammansatt tenant-nyckel med `SET NULL`, eller att föräldratabellen saknar `UNIQUE (tenant_id, id)` (skapad med ett vanligt `foreignUuid` istället för `tenantId()`) | se ovan; skapa föräldrar med `$table->tenantId()` |
| `Duplicate entry` på `number` mellan tenants | unikt index utan tenant-kolumnen | `$table->unique(['tenant_id', 'number'])` |
| `NOT NULL constraint failed: invoices.public_id` vid bulk-insert | `insert()` kringgår Eloquent-händelser | skapa via modellen eller generera id:n med `PublicIdGenerator` |
| `tenant_memberships.user_id` typ-mismatch / främmande nyckel till users misslyckas | `TENANT_USER_KEY_TYPE` matchade inte `users.id` när migrationen kördes | ställ in den och kör om medlemskapsmigrationen på ett rent schema |

## Uppstart och konfiguration

| Symptom | Trolig orsak | Åtgärd |
| --- | --- | --- |
| `InsecureConfiguration: Refusing to boot the audit log in production …` | `APP_ENV=production` utan nyckel / SQLite / append-only avstängt / inget läsbart ankarlager / externt ankare krävs men saknas | konfigurera det som de listade raderna anger; **sätt inte** `AUDIT_FAIL_FAST=false` för att komma runt det |
| `InvalidSigningKey` | felformaterad `AUDIT_SIGNING_KEYS`, nyckel kortare än 32 byte, aktiv nyckel finns inte i listan | `php artisan audit:key:generate`; formatet `k1:base64:<key>` |
| `security:doctor --strict` misslyckas på `anchor_stores` trots att ankare fungerar | `AUDIT_REQUIRE_EXTERNAL_ANCHOR` är avstängd — strict kräver att profilen är *framtvingad*, inte bara konfigurerad | sätt den till `true` |
| doctor: `Only the database store is readable` | `AUDIT_ANCHOR_STORES` saknar `filesystem` (att bara sätta `AUDIT_ANCHOR_DISK` gör ingenting) | `AUDIT_ANCHOR_STORES=filesystem,database` |
| doctor: `External store "filesystem" is not readable` | disken felkonfigurerad, fel autentiseringsuppgifter, bucket-policyn nekar `ListBucket`/`GetObject` | testa disken med `Storage::disk('audit-anchors')->put('probe', '1')` |
| doctor: `anchor_freshness` FAIL | schemaläggaren körs inte, eller `max_anchor_age_minutes` är lägre än ankringsintervallet | kontrollera `schedule:run`; justera tröskelvärdet |
| doctor: `verification_recency` WARN | `audit:verify` är inte schemalagd eller misslyckas | schemalägg den; läs dess utdata |
| doctor: `Store "array" … negative cache ineffective` (WARN) | tenant-cache på array-drivrutinen i produktion | `TENANT_CACHE_STORE=redis` |
| `InvalidArgumentException: audit.anchoring.stores entry "…" is neither database, filesystem, log nor a class` | felstavning i `AUDIT_ANCHOR_STORES` | rätta namnet; ett anpassat lager anges som ett fullt kvalificerat klassnamn |
| `InvalidArgumentException: tenant.resolution.chain must contain at least one resolver` | kedjan tömd i den publicerade konfigurationen | lista åtminstone `header` |

## Verifieringsfel

`audit:verify` avslutar med 1 och namnger varje avvikelse med en orsak och en
sekvens:

| Orsak | Betydelse | Var du ska titta |
| --- | --- | --- |
| `event_hash_mismatch` | radens innehåll skiljer sig från det som hashades | den raden ändrades |
| `previous_hash_mismatch` / `missing_predecessor` / `sequence_gap` | en rad togs bort eller ersattes före den här | raderna mellan den senaste korrekta och denna sekvens |
| `signature_invalid` / `signature_missing` / `signature_key_unknown` | omskriven utan nyckeln, eller en nyckel togs bort från listan | nyckellistan och rotationshistoriken |
| `head_mismatch` / `anchor_ahead` | kedjan trunkerades efter att kedjehuvudet eller ankaret skrevs | jämför med de externa ankarna |
| `anchor_mismatch` | omskriven konsekvent (med nyckeln) men före det senaste ankaret | ankaret är förtroendegränsen; starta incidenthantering |
| `unsupported_hash_version` | en händelse anger en hashversion som ramverket inte känner till | en nedgradering, eller en manipulerad rad |

Sedan: [operations/incident-response.md](../operations/incident-response.md).

## Få hjälp

- `php artisan security:doctor --json` och ramverkets version
  (`composer show efnext/security-framework`) i varje rapport.
- Sårbarheter: privat, se `SECURITY.md` — inte i ett issue.
