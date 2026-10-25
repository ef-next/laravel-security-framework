# Configuration reference

Every key of the three published config files, with its default, the
environment variable that feeds it (if any) and what it does to the security
guarantees. Guides explain tasks; this page explains the knobs.

- `config/addressing.php` — 5 keys
- `config/tenant.php` — 25 keys
- `config/audit.php` — 32 keys

Conventions: "env `X`, default …" means `env('X', …)`; an empty string in
`.env` is treated as "not set" for every string key (connection, store,
column…), which is why `.env.example` can list `AUDIT_DB_CONNECTION=`. Boolean
env values for the fail-fast, append-only and external-anchor switches are
parsed with `filter_var(FILTER_VALIDATE_BOOL)`, so `true/false`, `1/0`,
`on/off` and `yes/no` all work; the literal `true`/`false` is the least
surprising. `.env.example` at the repository root lists every environment
variable below with its default.

## `addressing.php`

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `addressing.service_name` | `app` | `SECURITY_SERVICE_NAME` | First segment of every global object id (`<service>:<type>:<public id>`); lowercase letters, digits, hyphens; unique across your services. | Ids of other services never resolve here. **Never change it after ids have been handed out** — every issued global id would stop resolving. |
| `addressing.public_ids.column` | `public_id` | `SECURITY_PUBLIC_ID_COLUMN` | Default column for `HasPublicId` models, `$table->publicId()`, route binding and mass-assignment guarding; override per model with `publicIdColumn()`. | — |
| `addressing.public_ids.encoding` | `base32` | `SECURITY_PUBLIC_ID_ENCODING` | `base32` (Crockford, 16 chars for 10 bytes) or `hex`. | Any other value throws on the first id generation. |
| `addressing.public_ids.entropy_bytes` | `10` | `SECURITY_PUBLIC_ID_ENTROPY_BYTES` | Random bytes per id (80 bits by default). | Below 8 bytes the generator refuses (`InvalidPublicId`): the framework does not issue guessable ids. |
| `addressing.public_ids.max_attempts` | `5` | `SECURITY_PUBLIC_ID_MAX_ATTEMPTS` | Retries on a (practically impossible) collision; only meaningful with the `UNIQUE` index `publicId()` creates. | After the last attempt `PublicIdGenerationFailed` is thrown, never a reused id. |

## `tenant.php`

### Source of truth

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `tenant.model` | `Efnext\Security\Tenant\Eloquent\Tenant` | — | Tenant model for relations and memberships (a subclass is fine). | — |
| `tenant.table` | `tenants` | — | Tenants table (migration, model, locator, composite foreign keys). | Changing it after `migrate` breaks the `tenantForeignId()` constraints that reference it. |
| `tenant.connection` | `null` | `TENANT_DB_CONNECTION` | Connection for tenants and memberships; empty = default. | — |
| `tenant.column` | `tenant_id` | — | Column on tenant-owned tables; per-model override with `$tenantColumn`. | The column `TenantScope` filters on and the framework fills from the context. Never in `$fillable`. |

### Resolution — where the identifier comes from

Resolvers run in the order listed; the first one that finds an identifier
wins; the identifier is then looked up (next section) and must match an
existing tenant, otherwise the request gets a 404.

| Key | Default | Purpose | Security effect |
| --- | --- | --- | --- |
| `tenant.resolution.chain` | `['header', 'route', 'session']` | Ordered resolvers: `header`, `route`, `session`, `subdomain`, or a class implementing `TenantResolver`. | An empty chain or an unknown entry throws at boot. `subdomain` is not in the default chain and needs `base_domains`. |
| `tenant.resolution.header` | `['X-Tenant-Id']` | Headers read by the `header` resolver, first non-blank wins. | The header is an *identifier*, never a proof: membership is enforced afterwards by `tenant.member`. |
| `tenant.resolution.route_parameters` | `['tenant']` | Route parameters read by the `route` resolver (a bound model is reduced to its key). | — |
| `tenant.resolution.session_key` | `efnext.tenant` | Session key read by the `session` resolver and written by `SessionTenant::switchTo()`. | A switch dispatches `TenantSwitched`, which is audited. |
| `tenant.resolution.subdomain.base_domains` | `[]` | Base domains for the `subdomain` resolver; the single label left of a base domain is the identifier. | Host comparison is lowercase and requires the dot, so `evilexample.test` does not match `example.test`; nested labels are ignored. |

### Lookup — how an identifier becomes a tenant

| Key | Default | Purpose | Security effect |
| --- | --- | --- | --- |
| `tenant.lookup.by` | `['id', 'public_id', 'global_object_id', 'slug']` | Strategies tried in order: UUID/ULID id, `ten_…` public id, `<service>:tenant:ten_…` (this service only), slug. | Every strategy validates the *shape* before querying; identifiers over 255 chars are rejected outright. Unknown strategy names are ignored. |
| `tenant.lookup.slug_column` | `slug` | Column for the `slug` strategy. | Only `^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$` is accepted. |
| `tenant.lookup.status_column` | `status` | Column that yields the tenant status. | Fail closed: an unknown or missing status is `suspended`, never `active`; pointing this at the wrong column locks every tenant out (403). |

### Cache

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `tenant.cache.enabled` | `true` | `TENANT_CACHE_ENABLED` | Wrap the locator in `CachedTenantLocator`. | Disabling it also disables the negative cache that blunts identifier enumeration. |
| `tenant.cache.store` | `null` | `TENANT_CACHE_STORE` | Cache store; empty = the application default. | In production the doctor warns when the effective store is `array` or `null`: use redis, memcached or database. |
| `tenant.cache.ttl` | `60` | `TENANT_CACHE_TTL` | Seconds a located tenant (id, status, public id, slug, name) is cached. | Suspensions made outside the `Tenant` model propagate within this time; saving the model flushes immediately. |
| `tenant.cache.negative_ttl` | `5` | `TENANT_CACHE_NEGATIVE_TTL` | Seconds an unknown identifier is remembered. | `0` disables negative caching. A tenant probed before it was created is "unknown" for up to this long. |
| `tenant.cache.prefix` | `efnext:tenant:` | — | Cache key prefix; keys are `prefix + sha256(identifier)`. | Raw identifiers never appear in cache keys. |

### Memberships and roles

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `tenant.memberships.model` | `…\TenantMembership` | — | Membership model (a subclass is fine). | — |
| `tenant.memberships.table` | `tenant_memberships` | — | Membership table. | — |
| `tenant.memberships.user_key_type` | `unsignedBigInteger` | `TENANT_USER_KEY_TYPE` | Type of `users.id`: `unsignedBigInteger`, `uuid`, `ulid`, `string`. | **Read at migration time only.** Must match the users table before the first `migrate`. |
| `tenant.memberships.roles` | `['owner', 'admin', 'member', 'viewer']` | — | Role vocabulary, highest first. | A role outside this list cannot be granted and never satisfies a check; an empty list throws at boot. |
| `tenant.memberships.hierarchical` | `true` | — | A required role is satisfied by that role or any higher one. | `false` narrows access: `tenant.role:admin` then rejects owners. |
| `tenant.memberships.default_role` | `member` | — | Role given by `grantTenantMembership()` without an explicit role. | Keep it low-privilege; must be one of `roles`. |

### Queue

| Key | Default | Purpose | Security effect |
| --- | --- | --- | --- |
| `tenant.queue.propagate` | `true` | Embed the active tenant in every queued job payload and restore it before the job runs. | With `false` jobs run without a tenant and hit the fail-closed scopes — the intended outcome; make the tenant explicit with `TenantAware`. A `withoutTenant()` bypass is deliberately **not** propagated. |

### API tokens (Sanctum, optional)

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `tenant.tokens.require_binding` | `false` | `TENANT_TOKENS_REQUIRE_BINDING` | Whether `tenant.token` rejects tokens that carry no `tenant:<id>` ability. | Default is permissive. Independently of it, a token that *does* carry tenant abilities must contain the active tenant (constant-time compare); cookie sessions are never affected. Only enforced on routes that use `tenant.token`. |

## `audit.php`

### Storage

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `audit.connection` | `null` | `AUDIT_DB_CONNECTION` | Dedicated connection for the audit tables; empty = default. Minimal grants: `SELECT, INSERT` on `audit_events`, `audit_anchors`, `audit_verification_runs`; `SELECT, INSERT, UPDATE` on `audit_chain_heads`. | The driver of this connection decides production: SQLite or anything but pgsql/mysql/mariadb refuses to boot. A connection different from the application's is a doctor WARN: `Audit::transaction()` is then not atomic across both. |
| `audit.table` | `audit_events` | — | Hash-chained events. | Append-only triggers are installed here. |
| `audit.heads_table` | `audit_chain_heads` | — | One row per chain (`last_sequence`, `last_hash`), locked `FOR UPDATE` on every write. | The one audit table that is legitimately updated; no trigger. |
| `audit.anchors_table` | `audit_anchors` | — | The `database` anchor store. | Triggers installed; anchors here never count as *external*. |
| `audit.verification_runs_table` | `audit_verification_runs` | — | Runs of `audit:verify` and the doctor's tail checks. | Read by the doctor's `verification_recency`. |

### Append-only enforcement

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `audit.enforce_append_only` | `true` | `AUDIT_ENFORCE_APPEND_ONLY` | Install `BEFORE UPDATE/DELETE` triggers on events and anchors (PostgreSQL, MySQL, MariaDB, SQLite; other drivers abort the migration). | **Read at migration time**; `false` logs a loud warning and installs nothing, and flipping it back needs the migration again. In production `false` refuses to boot and is a doctor FAIL. Never set it to false to get an environment running. |

### Tenant policy

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `audit.tenant_policy` | `required` | `AUDIT_TENANT_POLICY` | `required`: every event needs a tenant (from the entry or the active context); `nullable`: tenant-less events form the `global` chain. | Fail closed: any value other than the literal `nullable` behaves as `required`. `Audit::action()->global()` and the tenant package's security events always reach the global chain whatever the policy. |

### Writing

| Key | Default | Purpose | Security effect |
| --- | --- | --- | --- |
| `audit.write.attempts` | `5` | Retries of the writer's own transaction on deadlocks / serialization failures, with growing jitter. | Only when the writer owns the transaction; inside yours it runs once — which is why business + audit writes belong in `Audit::transaction()`. |

### Signing

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `audit.signing.keys` | `''` | `AUDIT_SIGNING_KEYS` | `k1:base64:<32+ bytes>[,k2:base64:…]`; generate with `audit:key:generate`. | Empty → events are hashed but **not signed**; production refuses to boot, doctor FAIL. Malformed entries, ids outside `[A-Za-z0-9_.-]{1,32}` or keys under 32 bytes throw `InvalidSigningKey`. Keep the keys in a secret manager, never next to the database credentials. |
| `audit.signing.active_key` | `''` | `AUDIT_SIGNING_ACTIVE_KEY` | Key used for new signatures; the others verify only. | Rotation = add the new key, point this at it, keep the old one listed. An active key that is not in the ring throws. |

### Anchoring

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `audit.anchoring.stores` | `['database']` | `AUDIT_ANCHOR_STORES` (comma-separated) | Stores `audit:anchor` writes to, external store first: `database`, `filesystem`, `log`, or the class name of your own `AnchorStore`. An empty value falls back to `database`. | `log` and `NullAnchorStore` (and subclasses) are write-only and never count as readable; without a readable store production refuses to boot. Database-only is a doctor WARN, a FAIL under `require_external_anchor` or `--strict`. **Pointing `AUDIT_ANCHOR_DISK` at a bucket does nothing on its own**; `filesystem` has to be in this list. |
| `audit.anchoring.filesystem.disk` | `local` | `AUDIT_ANCHOR_DISK` | Laravel disk for the `filesystem` store (one JSON document per anchor). | `local` lives on the application host, which is not independent of the database host. The documented target is object storage with Object Lock (compliance mode, retention ≥ audit retention). Under `require_external_anchor` the disk must exist and be instantiable at boot. |
| `audit.anchoring.filesystem.path` | `audit-anchors` | `AUDIT_ANCHOR_PATH` | Directory prefix on the disk. | — |
| `audit.anchoring.log.channel` | `null` | `AUDIT_ANCHOR_LOG_CHANNEL` | Log channel of the write-only `log` store; empty = default logger. | Cannot satisfy the readable or external requirement; forward it to a SIEM. |

### Verification

| Key | Default | Purpose |
| --- | --- | --- |
| `audit.verification.chunk_size` | `1000` | Events loaded per query while walking a chain. Performance only. |

### `security:doctor` thresholds

| Key | Default | Purpose | Security effect |
| --- | --- | --- | --- |
| `audit.doctor.max_anchor_age_minutes` | `60` | A chain whose head is not anchored and whose newest event is older than this → FAIL `anchor_freshness`. | Set it a few multiples above the `audit:anchor` interval. Under `require_external_anchor` only external anchors count. |
| `audit.doctor.max_verification_age_hours` | `24` | No intact full verification within this window → WARN; a last run with violations → FAIL regardless of age. | No shorter than the `audit:verify` schedule. |
| `audit.doctor.tail_events` | `200` | Events per chain re-verified on the spot by every doctor run (skipped with `--no-verify`). | Cost grows with the value; it does not replace `audit:verify`. |

### Production fail-fast

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `audit.production.fail_fast` | `true` | `AUDIT_FAIL_FAST` | In `APP_ENV=production`, refuse to boot with a configuration that removes the guarantees (no key, SQLite, append-only off, no readable anchor store) by throwing `InsecureConfiguration`. | No effect outside production. `false` is the deliberate, temporary exception and a doctor WARN. |
| `audit.production.require_external_anchor` | `false` | `AUDIT_REQUIRE_EXTERNAL_ANCHOR` | High-security profile: production also refuses to boot unless a readable store *outside* the audit database is configured and answers. | Doctor: database-only becomes FAIL, freshness counts external anchors only, external stores are read-tested. `security:doctor --strict` fails `anchor_stores` while this is off — a store that is merely configured can be dropped later without anything refusing to start. |

### Request context

| Key | Default | Purpose | Security effect |
| --- | --- | --- | --- |
| `audit.request.enrich` | `true` | Attach request id, correlation id, client IP and user agent to each event. | The four fields are part of the hash. Console and queue writes are never attributed to Laravel's placeholder request. Values are length-bounded (64/64/45/255) and stripped of control characters. |
| `audit.request.request_id_header` | `X-Request-Id` | Header stored as `request_id`. | Client-supplied context, not authentication. |
| `audit.request.correlation_id_header` | `X-Correlation-Id` | Header stored as `correlation_id`. | Same. |

### Metadata sanitising

Keys are tokenised (`passwordConfirmation` → `password`, `confirmation`;
`x-api-key` → `x`, `api`, `key`); a key is redacted when one of the patterns
occurs as a contiguous token run, so `author` and `session_count` survive while
`session_id` and `apiKey` do not. Values are also scanned for secret shapes
(JWTs, bearer strings, PEM keys, card numbers, cloud access keys).

| Key | Default | Env | Purpose | Security effect |
| --- | --- | --- | --- | --- |
| `audit.sanitizer.marker` | `[REDACTED]` | — | Replacement for redacted keys and secret-shaped values. | Every removal leaves a marker, so the log shows that something was there. |
| `audit.sanitizer.redact_key_patterns` | 34 patterns (`password`, `secret`, `token`, `api key`, `private key`, `authorization`, `cookie`, `otp`, `iban`, `card number`, `cvv`, `pin`, …) | — | Keys whose token run matches are replaced by the marker. | Removing entries widens what reaches the immutable log. Metadata is sanitised, not encrypted. |
| `audit.sanitizer.pseudonymize_key_patterns` | `email`, `e mail`, `mail`, `username`, `user name`, `login`, `phone`, `mobile`, `identifier` | — | String values under matching keys become `<key>_hash` = HMAC-SHA256 with the pepper. | Only string values; without a pepper the value becomes the marker, never a dictionary-attackable plain hash. |
| `audit.sanitizer.allow_keys` | `token_type`, `email_verified`, `email_verified_at`, `login_count`, `mail_driver` | — | Exact keys never redacted or hashed *by key*. | Values are still scanned for secret shapes. Every addition is a deliberate hole. |
| `audit.sanitizer.max_depth` | `6` | — | Deeper arrays become `['_depth_limited' => true]`. | Bounds payload size. |
| `audit.sanitizer.max_keys` | `100` | — | Keys per array / items per list; the rest is dropped and marked `_truncated`. | Same. |
| `audit.sanitizer.max_string_length` | `1024` | — | Longer strings are cut and suffixed `…[truncated]`. | Same; limits how much of an accidentally logged blob is persisted. |
| `audit.sanitizer.pseudonym_key` | `null` | `AUDIT_PSEUDONYM_KEY` | Pepper; empty = a key derived from `APP_KEY` (HKDF). | Rotating it is the closest thing to crypto-shredding the log offers. Keep it with the signing keys. |

### Tenant security events

| Key | Default | Purpose | Security effect |
| --- | --- | --- | --- |
| `audit.record_tenant_events` | `true` | Record `TenantAccessDenied`, `TenantScopeBypassed`, `MembershipChanged` and `TenantSwitched` automatically as `tenant.access_denied`, `tenant.scope_bypassed`, `tenant.membership.*`, `tenant.switched`. | With `false`, `Tenancy::withoutTenant()` bypasses are no longer audited — the property the framework relies on when it permits cross-tenant work. Events without a tenant go to the global chain whatever the policy. |

## Keys without an environment variable

Change these only in the published config file: `tenant.model`, `tenant.table`,
`tenant.column`, `tenant.resolution.*`, `tenant.lookup.*`, `tenant.cache.prefix`,
`tenant.memberships.{model,table,roles,hierarchical,default_role}`,
`tenant.queue.propagate`, `audit.{table,heads_table,anchors_table,verification_runs_table}`,
`audit.write.attempts`, `audit.verification.chunk_size`, `audit.doctor.*`,
`audit.request.*`, `audit.sanitizer.*` (except `pseudonym_key`), `audit.record_tenant_events`.
