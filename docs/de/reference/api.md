<!-- source: docs/en/reference/api.md sha256: 082e0327e056f0e3d46555e64cd58a8e24057201b3d37db5f0b5b94185195a20 -->
# PHP-API

*Deutsche Ausgabe — die [englische Fassung](../../en/reference/api.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Die Oberfläche, mit der eine Anwendung in Berührung kommt: zwei Facades, die
Traits, die auf Ihre Models und Benutzer kommen, die Schema-Makros, der
Job-Contract und die Interfaces, die Sie implementieren können. Alles, was
hier nicht aufgeführt ist, ist intern und kann sich zwischen Minor-Versionen
ändern.

## `Tenancy`-Facade (`Efnext\Security\Tenant\Facades\Tenancy`)

| Methode | Bedeutung |
| --- | --- |
| `current(): ?TenantContext` / `currentId(): ?TenantId` / `has(): bool` | Der aktive Kontext, seine ID, ob einer aktiv ist. |
| `require(): TenantContext` / `requireId(): TenantId` | Dasselbe, oder `TenantContextMissing`. |
| `is(TenantId\|string $tenantId): bool` | Ob der aktive Tenant dieser ist (false ohne Kontext). |
| `run(TenantContext\|TenantId\|string $tenant, Closure $callback, Source $source = Source::Manual): mixed` | Führt den Callback als dieser Tenant aus; der vorherige Kontext (und jede Umgehung) wird danach wiederhergestellt. Löst `TenantContextActivated` aus. |
| `withoutTenant(string $reason, Closure $callback): mixed` | Bewusster tenant-übergreifender Block (Wartung, Aggregation). Der Grund ist verpflichtend und wird zusammen mit der aufrufenden Datei und Zeile auditiert (`TenantScopeBypassed`). Verwenden Sie darin `Model::forTenant($id)`, um explizit zu bleiben. |
| `isBypassed(): bool` | Innerhalb eines `withoutTenant()`-Blocks. |
| `activate(TenantContext $context): void` / `clear(): void` | Low-Level: Setzt oder verwirft den Kontext für den Rest der Arbeitseinheit. Middleware und die Queue-Integration rufen diese auf; Anwendungscode benötigt sie selten. |

`TenantContext::for(TenantId|string $tenantId, Source $source = Source::Manual, ?PublicId $publicId = null)`
baut einen Kontext von Hand. Werte von `Source`: `Header`, `Route`, `Session`,
`Subdomain`, `Queue`, `Console`, `Manual`.

## `Audit`-Facade (`Efnext\Security\Audit\Facades\Audit`)

| Methode | Bedeutung |
| --- | --- |
| `action(string $action): AuditBuilder` | Beginnt ein Ereignis; die action ist `dotted.lower_snake` (`invoice.paid`), ≤ 191 Zeichen. |
| `transaction(Closure $callback, int $attempts = 10): mixed` | `DB::transaction()` auf der Audit-Connection plus Wiederholungen mit Jitter-Backoff bei Concurrency-Fehlern (InnoDB-Deadlocks, PostgreSQL-Serialisierungsfehler, MariaDB-Snapshot-Isolation-Konflikte), wenn keine Transaktion offen ist; läuft einmal innerhalb einer bestehenden. **Die Closure kann mehr als einmal laufen** — keine E-Mails oder API-Aufrufe darin. |
| `prepareChain(TenantId\|string\|null $tenantId): void` | Erstellt die Kopf-Zeile einer Kette vor ihrem ersten Ereignis (aufrufen, wenn ein Tenant erstellt wird; `null` = globale Kette). |
| `record(AuditEntry $entry): AuditEvent` | Schreibt einen vorgefertigten Eintrag. |
| `verifyTenant($tenantId, int $from = 1, ?int $to = null)`, `verifyGlobal(...)`, `verify(ChainKey $chain, ...)`: `VerificationReport` | Verifiziert eine Kette im Code (die Befehle verwenden denselben Verifier). |

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

`entry()` liefert den validierten `AuditEntry`, ohne ihn zu schreiben.
Resource type `[a-z][a-z0-9_-]{0,62}`, resource id 1–191 druckbare Zeichen,
metadata ein assoziatives Array. `Actor::user($id, $label)`,
`Actor::service($id, $label)`, `Actor::system($component)`,
`Actor::anonymous()`.

Das zurückgegebene `AuditEvent`-Model ist read-only (Aktualisieren oder
Löschen wirft `AuditEventImmutable`); Helfer `chainKey()`, `actor()`,
`outcome()`, Scopes `forChain(ChainKey)` und `forResource(string $type, string $id)`.

## Traits für Ihre Models

### `BelongsToTenant` (`Efnext\Security\Tenant\Eloquent`)

Fügt den fail-closed `TenantScope` hinzu, befüllt die Tenant-Spalte beim
Erstellen, verweigert das Verschieben eines Datensatzes zwischen Tenants,
prüft die Eigentümerschaft bei Update/Delete (auch auf SQL-Ebene, sodass
`saveQuietly()` und `withoutEvents()` nicht entkommen können), schützt die
Tenant-Spalte vor Mass Assignment, sofern Sie sie nicht selbst in `$fillable`
aufführen, und liefert einen `TenantAwareBuilder`, dessen `insert*()`,
`update()`, `increment()`, `decrement()` und `upsert()` auf dieselbe Weise
geschützt sind.

| Methode | Bedeutung |
| --- | --- |
| `tenantId(): ?TenantId` / `belongsToTenant($tenantId): bool` | Eigentümer des Datensatzes. |
| `tenant(): BelongsTo` | Relation zum Tenant-Model. |
| `Model::forTenant($tenantId)` (scope) | Expliziter Filter zur Verwendung innerhalb von `withoutTenant()`-Blöcken. |
| `tenantColumn(): string` | `$tenantColumn`-Property oder `tenant.column`. |

Ein benutzerdefinierter Eloquent-Builder muss das Trait `GuardsTenantColumn`
verwenden; jeder andere benutzerdefinierte Builder wird mit einer
`LogicException` abgewiesen.

### `HasTenantMemberships` (auf dem User-Model)

| Methode | Bedeutung |
| --- | --- |
| `tenantMemberships(): HasMany` / `tenants(): BelongsToMany` | Relationen (Pivot: `role`, `status`, `expires_at`). |
| `activeTenantIds(): list<TenantId>` / `isMemberOf($tenantId): bool` | Aktive, nicht abgelaufene Mitgliedschaften. |
| `tenantMembership($tenantId): ?Membership` | Value Object mit `role`, `status`, `expiresAt`, `grantsAccess()`. |
| `hasTenantRole($tenantId, string\|list $roles): bool` | Hierarchie-bewusste Rollenprüfung. |
| `grantTenantMembership($tenantId, ?string $role = null, ?DateTimeInterface $expiresAt = null): Membership` | Upsert mit Status `active`; Standardrolle aus der Konfiguration; löst `MembershipChanged` aus. |
| `changeTenantMembershipStatus($tenantId, MembershipStatus $status): void` | `active`, `invited`, `suspended`. |
| `revokeTenantMembership($tenantId): void` | Löscht die Mitgliedschaft. |

### `HasPublicId` und `HasGlobalObjectId` (`Efnext\Security\Addressing\Eloquent`)

`HasPublicId` benötigt `public static function publicIdPrefix(): string`
(`[a-z][a-z0-9]{1,11}`). Es weist die ID bei `creating` zu, verweigert
Änderungen danach, schützt die Spalte vor Mass Assignment und sorgt dafür,
dass Route-Model-Binding die Public ID verwendet — eine fehlerhaft gebildete
ID liefert 404 ohne Abfrage. Methoden: `publicId(): PublicId`,
`publicIdColumn(): string`, Scope `wherePublicId(PublicId|string)`.

`HasGlobalObjectId` benötigt zusätzlich `public static function resourceType(): string`
und ist zusammen mit `implements ExternallyAddressable` gedacht. Methoden:
`globalObjectId(): GlobalObjectId`, `static serviceName(): string`, Scope
`whereGlobalObjectId(GlobalObjectId|string)` (keine Zeilen für einen anderen
Service oder Resource type).

Value Objects: `PublicId::of($prefix, $body)`, `::fromString()`,
`::tryFromString()`, `::isValid()`, `->toString()`; `GlobalObjectId::of($service,
$type, PublicId)`, `::fromString()`, `::tryFromString()`, `->is($service, ?$type)`.

## Schema-Makros

```php
$table->tenantId();                                        // foreignUuid tenant_id + index + FK to tenants (cascadeOnUpdate, restrictOnDelete) + UNIQUE(tenant_id, id)
$table->tenantId('org_id', constrained: true, compositeKey: true, primaryKey: 'id');
$table->tenantForeignId('invoice_id', 'invoices');         // foreignId + composite FK (tenant_id, invoice_id) -> invoices(tenant_id, id) + index
$table->tenantForeignUuid('customer_id', 'customers', references: 'id', nullable: true);
$table->tenantForeignUlid('order_id', 'orders');
$table->publicId();                                        // string(80) unique, column from config
```

`tenantForeign*()` liefern eine `TenantForeignKeyDefinition`: verketten Sie
`cascadeOnDelete()`, `restrictOnDelete()`, `noActionOnDelete()`, die
`onUpdate`-Varianten; `nullOnDelete()`/`nullOnUpdate()` (und `SET DEFAULT`)
werfen eine `LogicException`, weil ein zusammengesetzter Schlüssel nicht nur
eine seiner Spalten leeren kann.

## Jobs

Jobs, die innerhalb eines Tenant-Kontexts versendet werden, tragen ihn
automatisch mit (`tenant.queue.propagate`). Ein Job, der seinen eigenen
Tenant bestimmt, implementiert `Efnext\Security\Tenant\Queue\TenantAware`
(`tenantId(): TenantId`) und gibt `[new TenantContextMiddleware]` aus
`middleware()` zurück; das hat Vorrang vor dem propagierten Kontext.

## Interfaces, die Sie implementieren können

| Interface | Methoden | Eingebunden über |
| --- | --- | --- |
| `Tenant\Contracts\TenantResolver` | `resolve(Request): ?Candidate`, `describe(): string` | Klassenname in `tenant.resolution.chain` |
| `Audit\Contracts\AnchorStore` | `store(Anchor): void`, `latest(ChainKey, ?int $atOrBelowSequence = null): ?Anchor`, `name(): string` | Klassenname in `AUDIT_ANCHOR_STORES`; zählt als *extern*, sofern er nicht den Database-, Log- oder Null-Store erweitert |
| `Audit\Contracts\AuditActor` | `toAuditActor(): Actor` | auf Ihrem User-/Service-Account-Model; der Resolver bevorzugt es gegenüber dem Auth-Identifier |
| `Addressing\Contracts\ExternallyAddressable` | `publicId()`, `static resourceType()`, `globalObjectId()` | auf Ihren Models, bereitgestellt durch die beiden Traits |
| `Tenant\Queue\TenantAware` | `tenantId(): TenantId` | auf Ihren Jobs |
| `Tenant\Contracts\TenantLocator`, `MembershipChecker`; `Audit\Contracts\Signer`, `MetadataSanitizer`, `ActorResolver`; `Addressing\Contracts\PublicIdGenerator` | siehe die Interfaces | nur Container-Rebinding (z. B. ein KMS-gestützter `Signer`); kein Konfigurationsschlüssel |

`Tenant` (`Efnext\Security\Tenant\Eloquent\Tenant`, Präfix `ten`, Resource
type `tenant`) und `TenantMembership` sind gewöhnliche, nicht-finale
Eloquent-Models; `tenant.model` und `tenant.memberships.model` akzeptieren
Subklassen.
