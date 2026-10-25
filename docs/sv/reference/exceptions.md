<!-- source: docs/en/reference/exceptions.md sha256: 0acd1797c23f0fddfc8e83221070e8add7baa976c1806aaf70d9c9c679cd369a -->
# Undantag

*Svensk utgåva — den [engelska versionen](../../en/reference/exceptions.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Två familjer. **Request-fel** är `HttpException`er som Laravel renderar som
ett 4xx-svar; frameworket kastar dem från middleware och din applikation
förväntas inte fånga dem. **Programmerings- och konfigurationsfel** är
`LogicException`/`InvalidArgumentException`/`RuntimeException`er som
betyder att koden eller driftsättningen är fel; att fånga dem döljer
problemet — åtgärda den orsak som meddelandet anger. Varje meddelande säger
vad som är fel och, där det är relevant, vad man ska göra.

## Tenant — `Efnext\Security\Tenant\Exceptions` (alla implementerar `TenantException`)

| Undantag | HTTP | Kastas när | Meddelande | Åtgärd |
| --- | --- | --- | --- | --- |
| `TenantIdentifierMissing` | **400** | `tenant.resolve` hittar ingen identifierare (header, route-parameter, session, subdomain) | `No tenant identifier found in the request (checked: …)` | klienten skickar identifieraren; eller `tenant.resolve:optional` för routes som får köras utan en tenant |
| `TenantNotFound` | **404** | identifieraren matchar ingen tenant | `Tenant not found.` (identifieraren hålls utanför svarskroppen) | — |
| `TenantSuspended` | **403** | tenantens status inte är `active` (en okänd status räknas som avstängd) | `Tenant is suspended.` / `Tenant is archived.` | återaktivera tenanten |
| `TenantAccessDenied` | **403** | `tenant.member`, `tenant.role`, `tenant.token` eller ett sessionsbyte nekar åtkomst; `reason()` returnerar `DenialReason` | `You are not a member of this tenant.`, `Your membership … is not active.`, `… has expired.`, `Your role … does not permit this action.`, `This API token is not bound to a tenant.`, `… is not valid for this tenant.` | bevilja medlemskapet/rollen, förnya token |
| `TenantContextMissing` | 500 | en tenant-bunden modell frågas, skapas, uppdateras eller tas bort utan en aktiv tenant; `Tenancy::require()` utan kontext | `No tenant context: cannot query <Model> without an active tenant.` + ledtråd | lägg till `tenant`-middleware, omslut koden i `Tenancy::run()`, eller deklarera en tenant-överskridande operation med `Tenancy::withoutTenant('reason', …)`. **Fånga det aldrig.** |
| `TenantMismatch` | 500 | en modell som laddats eller skapats för en tenant skrivs medan en annan tenant är aktiv | `Refusing to create/update/delete <Model> owned by tenant "…" while tenant "…" is active.` | koden blandar kontexter; använd `Tenancy::run()` per tenant |
| `TenantColumnImmutable` | 500 | tenant-kolumnen för en sparad post ändras (modell eller query builder) | `<Model>: tenant_id of a persisted record must not change. Moving data between tenants is not supported.` | återskapa posten i målets tenant |
| `InvalidTenantId` | 500 | `TenantId::fromString()` med ett felformat värde | `Invalid tenant id "…": expected 1-191 printable characters …` | använd `TenantId::tryFromString()` för otillförlitlig indata |

Kastas även av tenant-paketet: `Illuminate\Auth\AuthenticationException`
(401) från `tenant.member` för icke autentiserade requester;
`InvalidArgumentException` för `withoutTenant('')`, en okänd roll i
`grantTenantMembership()`, en tom rollista, eller en felaktig
`tenant.resolution.chain`-post; `LogicException` för `nullOnDelete()` på en
sammansatt tenant-nyckel, en anpassad Eloquent-builder som inte använder
`GuardsTenantColumn`, `TenantContextMiddleware` på ett jobb som inte är
`TenantAware`, samt medlemskapshjälpare på en osparad användare.

## Audit — `Efnext\Security\Audit\Exceptions` (alla implementerar `AuditException`)

Inget av dem är en `HttpException`; ofångade i HTTP dyker de upp som 500.

| Undantag | Kastas när | Meddelande | Åtgärd |
| --- | --- | --- | --- |
| `InvalidAuditEntry` | action, resurstyp/id eller metadata validerar inte; `->on()` saknas; ingen tenant under policyn `required` | `Invalid audit entry: …` / `… a tenant is required (tenant policy "required") and none was given or active.` | åtgärda anropet; `->global()` eller `->forTenant()` där policyn tillåter det |
| `AuditEventImmutable` | `AuditEvent` uppdateras eller tas bort via Eloquent | `Audit records are append-only; they can neither be updated nor deleted.` | — (avsiktligt, by design) |
| `InvalidSigningKey` | `AUDIT_SIGNING_KEYS`/`AUDIT_SIGNING_ACTIVE_KEY` felformad, nyckel < 32 byte, aktiv nyckel inte i nyckelringen | `Invalid audit signing key configuration: … Expected AUDIT_SIGNING_KEYS="k1:base64:<key>,…"` | `php artisan audit:key:generate` |
| `InsecureConfiguration` | uppstart i `APP_ENV=production` med `AUDIT_FAIL_FAST` på och en konfiguration som tar bort en garanti (ingen nyckel, SQLite, append-only avstängt, inget läsbart ankarlager, externt ankare krävs men saknas) | `Refusing to boot the audit log in production with an insecure configuration:` + en rad per problem | konfigurera det som raderna anger; `AUDIT_FAIL_FAST=false` endast som ett medvetet, tillfälligt undantag |
| `UnsupportedDatabaseDriver` | migreringen eller doctor möter en drivrutin utan append-only-triggers | `Append-only triggers are not implemented for the "<driver>" driver (supported: pgsql, mysql, mariadb, sqlite).` | använd en databas som stöds |
| `CanonicalizationFailed` | metadata kan inte kanoniseras (objekt, resurser, NaN/Inf) | `Audit record cannot be canonicalised: …` | skicka in skalärer, arrayer, DateTimes, Stringables |

Även: `InvalidArgumentException` för en okänd `AUDIT_ANCHOR_STORES`-post;
`RuntimeException` om en kedjas huvudrad försvinner mitt i en skrivning.

## Addressing — `Efnext\Security\Addressing\Exceptions` (utökar `AddressingException`)

Route binding kastar aldrig något för ett felformat id:
`resolveRouteBinding()` returnerar `null`, vilket blir ett 404.

| Undantag | Kastas när | Åtgärd |
| --- | --- | --- |
| `InvalidPublicId` | `PublicId::of()/fromString()` med ett felaktigt prefix eller body; ett förinställt id med fel prefix vid `creating`; entropi under 8 byte | prefix `[a-z][a-z0-9]{1,11}`, body `[a-z0-9]{8,64}`; använd `tryFromString()` för indata; håll `entropy_bytes ≥ 8` |
| `InvalidGlobalObjectId` | `GlobalObjectId::of()/fromString()` med felformade segment | `<service>:<type>:<public id>`, segment `[a-z][a-z0-9-]{0,62}`; `tryFromString()` för indata |
| `MissingPublicId` | `publicId()` på en modell som ännu inte har skapats | skapa modellen först |
| `PublicIdImmutable` | den publika id-kolumnen för en sparad modell ändras | — (avsiktligt, by design) |
| `PublicIdGenerationFailed` | inget unikt id efter `max_attempts` (praktiskt taget ouppnåeligt vid 80 bitar) | kontrollera entropiinställningen och systemets slumpkälla |

Även: `InvalidArgumentException('Unknown public id encoding …')` för en
felaktig `addressing.public_ids.encoding`.
