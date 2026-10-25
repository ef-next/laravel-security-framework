# Data model

**Reader question:** which tables does the framework create, what does a
tenant-owned table of *yours* look like, and which columns carry the
guarantees (isolation, chain, signature, anchor)?

```mermaid
erDiagram
    tenants {
        uuid id PK
        string public_id UK "ten_… (80-bit, indexed)"
        string slug UK "nullable, 64"
        string name
        string status "active | suspended | archived — unknown = suspended"
        timestamptz created_at
        timestamptz updated_at
    }

    tenant_memberships {
        ulid id PK
        uuid tenant_id FK "→ tenants.id, cascade"
        mixed user_id "type = TENANT_USER_KEY_TYPE"
        string role "nullable, 64"
        string status "active | invited | suspended"
        timestamptz expires_at "nullable"
        timestamptz created_at
        timestamptz updated_at
    }

    customers {
        bigint id PK
        uuid tenant_id FK "→ tenants.id · UNIQUE(tenant_id, id)"
        string public_id UK "cus_…"
        string name
    }

    invoices {
        bigint id PK
        uuid tenant_id FK "→ tenants.id · UNIQUE(tenant_id, id)"
        bigint customer_id FK "(tenant_id, customer_id) → customers(tenant_id, id)"
        string public_id UK "inv_…"
        string number "UNIQUE(tenant_id, number)"
    }

    audit_chain_heads {
        string chain_key PK "tenant:{uuid} or global"
        bigint last_sequence
        char last_hash "64 hex, nullable"
        timestamptz updated_at
    }

    audit_events {
        ulid id PK
        string chain_key "UNIQUE(chain_key, sequence)"
        string tenant_id "nullable (global chain)"
        bigint sequence
        char previous_hash "64 hex, nullable for sequence 1"
        char event_hash "SHA-256(domain ‖ canonical JSON)"
        smallint hash_version "2"
        string key_id "signing key id"
        char signature "HMAC-SHA256(event_hash)"
        timestamptz occurred_at "inside the hash"
        bigint occurred_at_us "inside the hash"
        timestamptz recorded_at "server time, outside the hash"
        string actor_type "user | service | system | anonymous"
        string actor_id "nullable"
        string actor_label "nullable"
        string action "invoice.paid"
        string resource_type "invoice"
        string resource_id "public id"
        string outcome "allowed | denied | failed"
        json metadata "sanitised before hashing"
        string request_id "nullable"
        string correlation_id "nullable"
        string ip_address "nullable"
        string user_agent "nullable"
    }

    audit_anchors {
        ulid id PK
        string chain_key "INDEX(chain_key, sequence)"
        bigint sequence
        char event_hash "head hash at anchoring time"
        string key_id
        char signature "HMAC over the anchor"
        timestamptz anchored_at
        timestamptz created_at
    }

    audit_verification_runs {
        bigint id PK
        string chain_key "INDEX(chain_key, finished_at)"
        string source "audit:verify | security:doctor"
        bigint from_sequence
        bigint to_sequence "nullable"
        bigint events_checked
        int violations
        boolean intact
        timestamptz started_at
        timestamptz finished_at
    }

    tenants ||--o{ tenant_memberships : "has members"
    tenants ||--o{ customers : "owns (restrict on delete)"
    tenants ||--o{ invoices : "owns (restrict on delete)"
    customers ||--o{ invoices : "composite FK within the tenant"
    tenants ||--o| audit_chain_heads : "one chain per tenant (+ global)"
    audit_chain_heads ||--o{ audit_events : "sequence 1..n, each links previous_hash"
    audit_chain_heads ||--o{ audit_anchors : "signed head snapshots"
    audit_chain_heads ||--o{ audit_verification_runs : "bookkeeping"
```

`customers` and `invoices` are **your** tables, shown as the getting-started
example creates them. Everything else is created by the framework's migrations
(table names are configurable: `tenant.table`, `tenant.memberships.table`,
`audit.table`, `audit.heads_table`, `audit.anchors_table`,
`audit.verification_runs_table`).

## In words

**Tenant side.** `tenants` is keyed by a UUID (internal), has an unguessable
public id (`ten_…`, what clients send), an optional slug and a status the
locator fails closed on. `tenant_memberships` is the *only* thing that grants
a user access to a tenant; its `user_id` column takes the type of your
`users.id` (`TENANT_USER_KEY_TYPE`, fixed at migration time).

**Your tables.** `$table->tenantId()` adds `tenant_id` (FK to `tenants`,
`RESTRICT` on delete, indexed) **and** `UNIQUE (tenant_id, id)`. That unique
index is what allows the second macro family — `tenantForeignId()`,
`tenantForeignUuid()`, `tenantForeignUlid()` — to declare a **composite**
foreign key `(tenant_id, customer_id) → customers (tenant_id, id)`: the
database itself refuses an invoice that points at another tenant's customer,
whatever the application does. Composite keys cannot use `SET NULL`; the
framework refuses that at schema-definition time.

**Audit side.** One `audit_chain_heads` row per chain — `tenant:<uuid>` or
`global` — holds the tip. Every `audit_events` row carries its position
(`sequence`, unique per chain), the link (`previous_hash`), the content hash
(`event_hash`, over everything except `recorded_at`) and the signature with
the id of the key that made it. `audit_anchors` are signed copies of the head
at a point in time; the copies in object storage are the ones that count.
`audit_verification_runs` records what was checked when — operational data,
not evidence.

**Append-only.** `audit_events` and `audit_anchors` get database triggers that
refuse `UPDATE` and `DELETE` (PostgreSQL, MySQL, MariaDB, SQLite). The other
tables are ordinary.

## What the picture does not say

- `tenant_id` in `audit_events` is a string, not a foreign key: audit rows
  must survive the tenant row. Deleting a tenant with events is your
  retention decision, not a cascade.
- The hash covers the *canonical JSON* of the event, not the row. Column
  order, character set and driver quoting do not matter; the known-answer
  vectors in `packages/audit/tests/Fixtures/hash-vectors.json` pin the
  format.
- Public ids are unique per table, not globally; the *global object id*
  (`<service>:<type>:<public id>`) is derived, not stored.
- `audit_events.metadata` holds the sanitised form. Keys named like secrets
  are redacted and identifiers are hashed before the row — and the hash —
  are computed; the original never reaches the database.

Related: [Tenancy guide](../guides/tenancy.md) · [Auditing guide](../guides/auditing.md) ·
[Audit write path](audit-write-and-verification.md) · [Configuration](../reference/configuration.md)
