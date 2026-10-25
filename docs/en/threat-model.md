# Threat model

Assets: tenant data (confidentiality, integrity), the audit trail (integrity,
availability of evidence), tenant and membership records (integrity).

## Adversaries and what the framework does about them

| Adversary | Capability | Relevant controls | Residual risk |
| --- | --- | --- | --- |
| **A1 – Foreign tenant user** | Authenticated user of tenant B sending requests with tenant A's identifier (header, route, subdomain). | `ResolveTenant` + `EnsureTenantMember`: membership check before the controller; 403 and an audit event on failure. `TenantScope` on every model query. `EnsureTokenTenant` for tenant-bound API tokens. | Routes that lack the `tenant` middleware group. Global models that should have been tenant-owned. |
| **A2 – Enumerating outsider** | Unauthenticated requests guessing tenant identifiers or resource ids. | Public ids with 80 bits of entropy; 404 without echoing the identifier; negative cache on unknown identifiers; malformed identifiers never reach the database. | Response-time differences between "unknown tenant" and "known tenant, no membership" (404 vs 403 by design). Rate limiting is the application's job. |
| **A3 – Careless developer** | Forgets middleware, a `where`, or runs a command/job without tenant. | Fail-closed scope: no tenant means `TenantContextMissing`, not a full-table read. Immutable tenant column. Tenant column excluded from mass assignment. Queue propagation by default. Architecture tests. | `DB::table()`, raw SQL, `withoutGlobalScope()`. These are code-review items; the docs say so. |
| **A4 – Malicious insider with application access** | Can deploy code or run Tinker. | `withoutTenant()` requires a reason and is audited with caller location; bypass never propagates to jobs. | Cannot be prevented by a library. The audit trail is the control. |
| **A5 – Database-level attacker without the signing key** | Can `UPDATE`/`DELETE` after dropping triggers; can recompute hashes. | Triggers (first hurdle). HMAC signatures over every event hash with a key outside the database: recomputed hashes fail signature verification. Chain head and external anchors: truncation is detected. | If the attacker also holds the signing key (same secret store, same host), signatures can be forged. Keep the key separate; anchor externally. |
| **A6 – Database-level attacker with the signing key** | Everything A5 can do plus valid signatures. | External anchors in a store the attacker cannot reach (object lock, log sink). `AnchorMismatch`/`AnchorAhead` on verification. | Events after the last anchor can be rewritten undetected. Anchor frequently. |
| **A7 – Compromised application server** | Full control of the PHP process, including the signing key in memory. | None inside the process. External anchors limit the window; verification from a separate host detects rewrites older than the last anchor. | Anything within the anchor interval. Separate the verifier's key access from the application if this adversary matters to you. |
| **A8 – Clock manipulation / skew** | Servers with wrong clocks. | Ordering by sequence, never by time. `occurredAt` more than five minutes in the future is rejected. | Backdated `occurredAt` within the past is accepted and hashed as given; the sequence still reveals the true order. |
| **A9 – Log poisoning** | Attacker controls metadata content (e.g. user input) to inject secrets or huge payloads. | Sanitizer: key patterns, secret-shaped values, depth/key/length limits, control characters stripped from request context. | Secrets under unknown keys that do not match a known shape. Do not log raw input. |

## Explicitly out of scope

- Authentication (use Laravel's guards, Sanctum, Passport, SSO).
- Authorization within a tenant (Policies, Gates).
- Network-level controls, WAF, rate limiting.
- Encryption at rest, backups, disaster recovery.
- Database-per-tenant isolation.

## Assumptions

- The application boots the three service providers and runs the migrations.
- Tenant-owned models use `BelongsToTenant`; global models are deliberately global.
- Routes touching tenant data use the `tenant` middleware group after authentication.
- `audit:verify` and `audit:anchor` are scheduled and their failures alert someone.
- `AUDIT_SIGNING_KEYS` is stored in a secret manager, not in the database and not next to the database credentials.
