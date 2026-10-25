# PHP API

The surface an application touches: two facades, the traits that go on your
models and users, the schema macros, the job contract and the interfaces you
can implement. Anything not listed here is internal and may change between
minor versions.

## `Tenancy` facade (`Efnext\Security\Tenant\Facades\Tenancy`)

| Method | Meaning |
| --- | --- |
| `current(): ?TenantContext` / `currentId(): ?TenantId` / `has(): bool` | The active context, its id, whether one is active. |
| `require(): TenantContext` / `requireId(): TenantId` | Same, or `TenantContextMissing`. |
| `is(TenantId\|string $tenantId): bool` | Whether the active tenant is that one (false without context). |
| `run(TenantContext\|TenantId\|string $tenant, Closure $callback, Source $source = Source::Manual): mixed` | Run the callback as that tenant; the previous context (and any bypass) is restored afterwards. Dispatches `TenantContextActivated`. |
| `withoutTenant(string $reason, Closure $callback): mixed` | Deliberate cross-tenant block (maintenance, aggregation). The reason is mandatory and audited together with the calling file and line (`TenantScopeBypassed`). Inside, use `Model::forTenant($id)` to stay explicit. |
| `isBypassed(): bool` | Inside a `withoutTenant()` block. |
| `activate(TenantContext $context): void` / `clear(): void` | Lower-level: set or drop the context for the rest of the unit of work. Middleware and the queue integration call these; application code rarely needs them. |

`TenantContext::for(TenantId|string $tenantId, Source $source = Source::Manual, ?PublicId $publicId = null)`
builds a context by hand. `Source` values: `Header`, `Route`, `Session`,
`Subdomain`, `Queue`, `Console`, `Manual`.

## `Audit` facade (`Efnext\Security\Audit\Facades\Audit`)

| Method | Meaning |
| --- | --- |
| `action(string $action): AuditBuilder` | Start an event; the action is `dotted.lower_snake` (`invoice.paid`), ≤ 191 chars. |
| `transaction(Closure $callback, int $attempts = 10): mixed` | `DB::transaction()` on the audit connection plus retries with jittered back-off on concurrency errors (InnoDB deadlocks, PostgreSQL serialization failures, MariaDB snapshot-isolation conflicts) when no transaction is open; runs once inside an existing one. **The closure may run more than once** — no e-mails or API calls inside. |
| `prepareChain(TenantId\|string\|null $tenantId): void` | Create the head row of a chain before its first event (call it when a tenant is created; `null` = global chain). |
| `record(AuditEntry $entry): AuditEvent` | Write a pre-built entry. |
| `verifyTenant($tenantId, int $from = 1, ?int $to = null)`, `verifyGlobal(...)`, `verify(ChainKey $chain, ...)`: `VerificationReport` | Verify a chain in code (the commands use the same verifier). |

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

`entry()` returns the validated `AuditEntry` without writing it. Resource type
`[a-z][a-z0-9_-]{0,62}`, resource id 1–191 printable characters, metadata an
associative array. `Actor::user($id, $label)`, `Actor::service($id, $label)`,
`Actor::system($component)`, `Actor::anonymous()`.

The returned `AuditEvent` model is read-only (updating or deleting throws
`AuditEventImmutable`); helpers `chainKey()`, `actor()`, `outcome()`, scopes
`forChain(ChainKey)` and `forResource(string $type, string $id)`.

## Traits for your models

### `BelongsToTenant` (`Efnext\Security\Tenant\Eloquent`)

Adds the fail-closed `TenantScope`, fills the tenant column on create, refuses
to move a record between tenants, checks ownership on update/delete (also at
SQL level, so `saveQuietly()` and `withoutEvents()` cannot escape), guards the
tenant column against mass assignment unless you list it in `$fillable`
yourself, and returns a `TenantAwareBuilder` whose `insert*()`, `update()`,
`increment()`, `decrement()` and `upsert()` are guarded the same way.

| Method | Meaning |
| --- | --- |
| `tenantId(): ?TenantId` / `belongsToTenant($tenantId): bool` | Owner of the record. |
| `tenant(): BelongsTo` | Relation to the tenant model. |
| `Model::forTenant($tenantId)` (scope) | Explicit filter for use inside `withoutTenant()` blocks. |
| `tenantColumn(): string` | `$tenantColumn` property or `tenant.column`. |

A custom Eloquent builder must use the `GuardsTenantColumn` trait; any other
custom builder is refused with a `LogicException`.

### `HasTenantMemberships` (on the user model)

| Method | Meaning |
| --- | --- |
| `tenantMemberships(): HasMany` / `tenants(): BelongsToMany` | Relations (pivot: `role`, `status`, `expires_at`). |
| `activeTenantIds(): list<TenantId>` / `isMemberOf($tenantId): bool` | Active, unexpired memberships. |
| `tenantMembership($tenantId): ?Membership` | Value object with `role`, `status`, `expiresAt`, `grantsAccess()`. |
| `hasTenantRole($tenantId, string\|list $roles): bool` | Hierarchy-aware role check. |
| `grantTenantMembership($tenantId, ?string $role = null, ?DateTimeInterface $expiresAt = null): Membership` | Upsert with status `active`; default role from config; dispatches `MembershipChanged`. |
| `changeTenantMembershipStatus($tenantId, MembershipStatus $status): void` | `active`, `invited`, `suspended`. |
| `revokeTenantMembership($tenantId): void` | Deletes the membership. |

### `HasPublicId` and `HasGlobalObjectId` (`Efnext\Security\Addressing\Eloquent`)

`HasPublicId` requires `public static function publicIdPrefix(): string`
(`[a-z][a-z0-9]{1,11}`). It assigns the id on `creating`, refuses changes
afterwards, guards the column against mass assignment, and makes route model
binding use the public id — a malformed id returns 404 without a query.
Methods: `publicId(): PublicId`, `publicIdColumn(): string`, scope
`wherePublicId(PublicId|string)`.

`HasGlobalObjectId` additionally requires `public static function resourceType(): string`
and is meant together with `implements ExternallyAddressable`. Methods:
`globalObjectId(): GlobalObjectId`, `static serviceName(): string`, scope
`whereGlobalObjectId(GlobalObjectId|string)` (no rows for another service or
resource type).

Value objects: `PublicId::of($prefix, $body)`, `::fromString()`,
`::tryFromString()`, `::isValid()`, `->toString()`; `GlobalObjectId::of($service,
$type, PublicId)`, `::fromString()`, `::tryFromString()`, `->is($service, ?$type)`.

## Schema macros

```php
$table->tenantId();                                        // foreignUuid tenant_id + index + FK to tenants (cascadeOnUpdate, restrictOnDelete) + UNIQUE(tenant_id, id)
$table->tenantId('org_id', constrained: true, compositeKey: true, primaryKey: 'id');
$table->tenantForeignId('invoice_id', 'invoices');         // foreignId + composite FK (tenant_id, invoice_id) -> invoices(tenant_id, id) + index
$table->tenantForeignUuid('customer_id', 'customers', references: 'id', nullable: true);
$table->tenantForeignUlid('order_id', 'orders');
$table->publicId();                                        // string(80) unique, column from config
```

`tenantForeign*()` return a `TenantForeignKeyDefinition`: chain
`cascadeOnDelete()`, `restrictOnDelete()`, `noActionOnDelete()`, the `onUpdate`
variants; `nullOnDelete()`/`nullOnUpdate()` (and `SET DEFAULT`) throw a
`LogicException` because a composite key cannot clear only one of its columns.

## Jobs

Jobs dispatched inside a tenant context carry it automatically
(`tenant.queue.propagate`). A job that decides its own tenant implements
`Efnext\Security\Tenant\Queue\TenantAware` (`tenantId(): TenantId`) and returns
`[new TenantContextMiddleware]` from `middleware()`; that takes precedence over
the propagated context.

## Interfaces you can implement

| Interface | Methods | Plugged in through |
| --- | --- | --- |
| `Tenant\Contracts\TenantResolver` | `resolve(Request): ?Candidate`, `describe(): string` | class name in `tenant.resolution.chain` |
| `Audit\Contracts\AnchorStore` | `store(Anchor): void`, `latest(ChainKey, ?int $atOrBelowSequence = null): ?Anchor`, `name(): string` | class name in `AUDIT_ANCHOR_STORES`; counts as *external* unless it extends the database, log or null store |
| `Audit\Contracts\AuditActor` | `toAuditActor(): Actor` | on your user / service-account model; the resolver prefers it over the auth identifier |
| `Addressing\Contracts\ExternallyAddressable` | `publicId()`, `static resourceType()`, `globalObjectId()` | on your models, provided by the two traits |
| `Tenant\Queue\TenantAware` | `tenantId(): TenantId` | on your jobs |
| `Tenant\Contracts\TenantLocator`, `MembershipChecker`; `Audit\Contracts\Signer`, `MetadataSanitizer`, `ActorResolver`; `Addressing\Contracts\PublicIdGenerator` | see the interfaces | container rebinding only (e.g. a KMS-backed `Signer`); no config key |

`Tenant` (`Efnext\Security\Tenant\Eloquent\Tenant`, prefix `ten`, resource
type `tenant`) and `TenantMembership` are ordinary, non-final Eloquent models;
`tenant.model` and `tenant.memberships.model` accept subclasses.
