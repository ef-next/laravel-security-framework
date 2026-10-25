<!-- source: docs/en/reference/api.md sha256: 082e0327e056f0e3d46555e64cd58a8e24057201b3d37db5f0b5b94185195a20 -->
# PHP-API

*Svensk utgåva — den [engelska versionen](../../en/reference/api.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Ytan som en applikation kommer i kontakt med: två facades, de traits som
läggs på dina modeller och användare, schema-makrona, jobbkontraktet och de
interface du kan implementera. Allt som inte listas här är internt och kan
ändras mellan minor-versioner.

## `Tenancy`-facaden (`Efnext\Security\Tenant\Facades\Tenancy`)

| Metod | Betydelse |
| --- | --- |
| `current(): ?TenantContext` / `currentId(): ?TenantId` / `has(): bool` | Den aktiva kontexten, dess id, om en är aktiv. |
| `require(): TenantContext` / `requireId(): TenantId` | Samma, eller `TenantContextMissing`. |
| `is(TenantId\|string $tenantId): bool` | Om den aktiva tenanten är just den (false utan kontext). |
| `run(TenantContext\|TenantId\|string $tenant, Closure $callback, Source $source = Source::Manual): mixed` | Kör callbacken som den tenanten; den tidigare kontexten (och en eventuell bypass) återställs efteråt. Skickar `TenantContextActivated`. |
| `withoutTenant(string $reason, Closure $callback): mixed` | Medvetet tenant-överskridande block (underhåll, aggregering). Anledningen (reason) är obligatorisk och loggas tillsammans med den anropande filen och raden (`TenantScopeBypassed`). Använd `Model::forTenant($id)` inuti för att förbli explicit. |
| `isBypassed(): bool` | Inuti ett `withoutTenant()`-block. |
| `activate(TenantContext $context): void` / `clear(): void` | Lägre nivå: sätter eller tar bort kontexten för resten av arbetsenheten (unit of work). Middleware och kö-integrationen anropar dessa; applikationskod behöver sällan göra det. |

`TenantContext::for(TenantId|string $tenantId, Source $source =
Source::Manual, ?PublicId $publicId = null)` bygger en kontext för hand.
`Source`-värden: `Header`, `Route`, `Session`, `Subdomain`, `Queue`,
`Console`, `Manual`.

## `Audit`-facaden (`Efnext\Security\Audit\Facades\Audit`)

| Metod | Betydelse |
| --- | --- |
| `action(string $action): AuditBuilder` | Startar en händelse; action är `dotted.lower_snake` (`invoice.paid`), ≤ 191 tecken. |
| `transaction(Closure $callback, int $attempts = 10): mixed` | `DB::transaction()` på audit-anslutningen plus nya försök med jitter-baserad backoff vid samtidighetsfel (InnoDB-deadlocks, PostgreSQL-serialiseringsfel, MariaDB snapshot-isolation-konflikter) när ingen transaktion är öppen; körs en gång inuti en befintlig. **Closuren kan köras mer än en gång** — inga e-postmeddelanden eller API-anrop däri. |
| `prepareChain(TenantId\|string\|null $tenantId): void` | Skapar kedjans huvudrad innan dess första händelse (anropa den när en tenant skapas; `null` = den globala kedjan). |
| `record(AuditEntry $entry): AuditEvent` | Skriver en färdigbyggd post. |
| `verifyTenant($tenantId, int $from = 1, ?int $to = null)`, `verifyGlobal(...)`, `verify(ChainKey $chain, ...)`: `VerificationReport` | Verifierar en kedja i kod (kommandona använder samma verifierare). |

### `AuditBuilder`

```php
Audit::action('invoice.paid')
    ->on($invoice)                        // ExternallyAddressable model, or ->on('invoice', 'INV-1')
    ->with(['amount' => 1250.0, 'currency' => 'EUR'])   // merged, sanitised, immutable
    ->allowed()  /  ->denied()  /  ->failed()  /  ->outcome('denied')
    ->by(Actor::service('billing-bot'))   // optional; default: the authenticated user, anonymous, or system('console')
    ->bySystem('scheduler')               // shorthand for ->by(Actor::system(...))
    ->forTenant($tenantId)                // optional; default: the active tenant
    ->global()                            // record on the global chain regardless of policy and context
    ->at($occurredAt)                     // optional; at most 5 minutes in the future
    ->record();                           // returns the immutable AuditEvent
```

`entry()` returnerar den validerade `AuditEntry` utan att skriva den.
Resurstyp `[a-z][a-z0-9_-]{0,62}`, resurs-id 1–191 skrivbara tecken,
metadata en associativ array. `Actor::user($id, $label)`,
`Actor::service($id, $label)`, `Actor::system($component)`,
`Actor::anonymous()`.

Den returnerade `AuditEvent`-modellen är skrivskyddad (att uppdatera eller
ta bort kastar `AuditEventImmutable`); hjälpmetoderna `chainKey()`,
`actor()`, `outcome()`, scopes `forChain(ChainKey)` och
`forResource(string $type, string $id)`.

## Traits för dina modeller

### `BelongsToTenant` (`Efnext\Security\Tenant\Eloquent`)

Lägger till den fail closed `TenantScope`, fyller i tenant-kolumnen vid
create, vägrar flytta en post mellan tenants, kontrollerar ägarskap vid
update/delete (även på SQL-nivå, så `saveQuietly()` och `withoutEvents()`
kan inte undkomma det), skyddar tenant-kolumnen mot mass assignment om du
inte själv listar den i `$fillable`, och returnerar en
`TenantAwareBuilder` vars `insert*()`, `update()`, `increment()`,
`decrement()` och `upsert()` skyddas på samma sätt.

| Metod | Betydelse |
| --- | --- |
| `tenantId(): ?TenantId` / `belongsToTenant($tenantId): bool` | Ägaren av posten. |
| `tenant(): BelongsTo` | Relation till tenant-modellen. |
| `Model::forTenant($tenantId)` (scope) | Explicit filter för användning inuti `withoutTenant()`-block. |
| `tenantColumn(): string` | Egenskapen `$tenantColumn` eller `tenant.column`. |

En anpassad Eloquent-builder måste använda `GuardsTenantColumn`-traiten;
varje annan anpassad builder avvisas med en `LogicException`.

### `HasTenantMemberships` (på användarmodellen)

| Metod | Betydelse |
| --- | --- |
| `tenantMemberships(): HasMany` / `tenants(): BelongsToMany` | Relationer (pivot: `role`, `status`, `expires_at`). |
| `activeTenantIds(): list<TenantId>` / `isMemberOf($tenantId): bool` | Aktiva, icke-utgångna medlemskap. |
| `tenantMembership($tenantId): ?Membership` | Värdeobjekt med `role`, `status`, `expiresAt`, `grantsAccess()`. |
| `hasTenantRole($tenantId, string\|list $roles): bool` | Rollkontroll som tar hänsyn till hierarkin. |
| `grantTenantMembership($tenantId, ?string $role = null, ?DateTimeInterface $expiresAt = null): Membership` | Upsert med status `active`; standardroll från konfigurationen; skickar `MembershipChanged`. |
| `changeTenantMembershipStatus($tenantId, MembershipStatus $status): void` | `active`, `invited`, `suspended`. |
| `revokeTenantMembership($tenantId): void` | Tar bort medlemskapet. |

### `HasPublicId` och `HasGlobalObjectId` (`Efnext\Security\Addressing\Eloquent`)

`HasPublicId` kräver `public static function publicIdPrefix(): string`
(`[a-z][a-z0-9]{1,11}`). Den tilldelar id:t vid `creating`, vägrar ändringar
efteråt, skyddar kolumnen mot mass assignment, och gör att route model
binding använder det publika id:t — ett felformat id returnerar 404 utan en
fråga. Metoder: `publicId(): PublicId`, `publicIdColumn(): string`, scope
`wherePublicId(PublicId|string)`.

`HasGlobalObjectId` kräver dessutom `public static function
resourceType(): string` och är tänkt att användas tillsammans med
`implements ExternallyAddressable`. Metoder: `globalObjectId():
GlobalObjectId`, `static serviceName(): string`, scope
`whereGlobalObjectId(GlobalObjectId|string)` (inga rader för en annan
tjänst eller resurstyp).

Värdeobjekt: `PublicId::of($prefix, $body)`, `::fromString()`,
`::tryFromString()`, `::isValid()`, `->toString()`; `GlobalObjectId::of($service,
$type, PublicId)`, `::fromString()`, `::tryFromString()`, `->is($service, ?$type)`.

## Schema-makron

```php
$table->tenantId();                                        // foreignUuid tenant_id + index + FK to tenants (cascadeOnUpdate, restrictOnDelete) + UNIQUE(tenant_id, id)
$table->tenantId('org_id', constrained: true, compositeKey: true, primaryKey: 'id');
$table->tenantForeignId('invoice_id', 'invoices');         // foreignId + composite FK (tenant_id, invoice_id) -> invoices(tenant_id, id) + index
$table->tenantForeignUuid('customer_id', 'customers', references: 'id', nullable: true);
$table->tenantForeignUlid('order_id', 'orders');
$table->publicId();                                        // string(80) unique, column from config
```

`tenantForeign*()` returnerar en `TenantForeignKeyDefinition`: kedja
`cascadeOnDelete()`, `restrictOnDelete()`, `noActionOnDelete()`,
`onUpdate`-varianterna; `nullOnDelete()`/`nullOnUpdate()` (och `SET
DEFAULT`) kastar en `LogicException` eftersom en sammansatt nyckel inte kan
nolla enbart en av sina kolumner.

## Jobb

Jobb som skickas ut inuti en tenant-kontext bär med sig den automatiskt
(`tenant.queue.propagate`). Ett jobb som avgör sin egen tenant implementerar
`Efnext\Security\Tenant\Queue\TenantAware` (`tenantId(): TenantId`) och
returnerar `[new TenantContextMiddleware]` från `middleware()`; det har
företräde framför den propagerade kontexten.

## Interface du kan implementera

| Interface | Metoder | Kopplas in via |
| --- | --- | --- |
| `Tenant\Contracts\TenantResolver` | `resolve(Request): ?Candidate`, `describe(): string` | klassnamn i `tenant.resolution.chain` |
| `Audit\Contracts\AnchorStore` | `store(Anchor): void`, `latest(ChainKey, ?int $atOrBelowSequence = null): ?Anchor`, `name(): string` | klassnamn i `AUDIT_ANCHOR_STORES`; räknas som *externt* om det inte utökar database-, log- eller null-lagret |
| `Audit\Contracts\AuditActor` | `toAuditActor(): Actor` | på din användar-/tjänstekontomodell; resolvern föredrar den framför auth-identifieraren |
| `Addressing\Contracts\ExternallyAddressable` | `publicId()`, `static resourceType()`, `globalObjectId()` | på dina modeller, tillhandahålls av de två traitsen |
| `Tenant\Queue\TenantAware` | `tenantId(): TenantId` | på dina jobb |
| `Tenant\Contracts\TenantLocator`, `MembershipChecker`; `Audit\Contracts\Signer`, `MetadataSanitizer`, `ActorResolver`; `Addressing\Contracts\PublicIdGenerator` | se respektive interface | endast container-rebinding (t.ex. en KMS-baserad `Signer`); ingen konfigurationsnyckel |

`Tenant` (`Efnext\Security\Tenant\Eloquent\Tenant`, prefix `ten`, resurstyp
`tenant`) och `TenantMembership` är vanliga, icke-final Eloquent-modeller;
`tenant.model` och `tenant.memberships.model` accepterar subklasser.
