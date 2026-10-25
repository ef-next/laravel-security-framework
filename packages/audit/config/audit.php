<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'connection' => env('AUDIT_DB_CONNECTION'),
    'table' => 'audit_events',
    'heads_table' => 'audit_chain_heads',
    'anchors_table' => 'audit_anchors',
    'verification_runs_table' => 'audit_verification_runs',

    /*
    |--------------------------------------------------------------------------
    | Append-only enforcement
    |--------------------------------------------------------------------------
    |
    | Installs BEFORE UPDATE / BEFORE DELETE triggers on PostgreSQL, MySQL,
    | MariaDB and SQLite. Any other driver aborts the migration. Setting this to
    | false is only meant for read replicas or exotic setups; it is logged loudly.
    |
    */

    'enforce_append_only' => env('AUDIT_ENFORCE_APPEND_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Tenant policy
    |--------------------------------------------------------------------------
    |
    | "required": every event belongs to a tenant (taken from the entry or the
    |             active tenant context); events without one are rejected.
    | "nullable": events without a tenant are allowed and form the "global" chain
    |             (useful for login failures and platform-level actions).
    |
    | Independently of the policy, Audit::action(...)->global() writes to the
    | global chain, and the tenant package's security events without a tenant
    | (scope bypasses outside a context, denials for unknown tenants) are always
    | recorded there.
    |
    */

    'tenant_policy' => env('AUDIT_TENANT_POLICY', 'required'),

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    |
    | Concurrent writers to the same chain queue on a row lock. Deadlocks and
    | serialization failures are retried this many times with growing jitter
    | when the writer owns the transaction; inside your own DB::transaction()
    | they propagate and the outer transaction has to retry.
    |
    */

    'write' => [
        'attempts' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signing (HMAC-SHA256 over each event hash)
    |--------------------------------------------------------------------------
    |
    | Keys live in the environment (or a secret store), never in the database,
    | so an attacker with database access cannot forge a valid chain. Format:
    |   AUDIT_SIGNING_KEYS="k1:base64:<32+ bytes>,k2:base64:<32+ bytes>"
    |   AUDIT_SIGNING_ACTIVE_KEY=k2
    | Old keys stay listed so their signatures remain verifiable.
    | Generate a key with: php artisan audit:key:generate
    |
    */

    'signing' => [
        'keys' => env('AUDIT_SIGNING_KEYS', ''),
        'active_key' => env('AUDIT_SIGNING_ACTIVE_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anchoring
    |--------------------------------------------------------------------------
    |
    | php artisan audit:anchor stores the current chain heads in one or more
    | anchor stores. A store outside the database (object storage with object
    | lock, an external log sink) is what makes wholesale chain rewrites
    | detectable. Available: database, filesystem, log, or the class name of
    | your own AnchorStore. List the external store first; the verifier prefers
    | the first store that holds an anchor.
    |
    | AUDIT_ANCHOR_STORES is a comma-separated list, e.g. "filesystem,database".
    | Pointing AUDIT_ANCHOR_DISK at a bucket does nothing on its own — the
    | filesystem store also has to be in this list.
    |
    */

    'anchoring' => [
        'stores' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUDIT_ANCHOR_STORES', 'database'))))) ?: ['database'],
        'filesystem' => [
            'disk' => env('AUDIT_ANCHOR_DISK', 'local'),
            'path' => env('AUDIT_ANCHOR_PATH', 'audit-anchors'),
        ],
        'log' => [
            'channel' => env('AUDIT_ANCHOR_LOG_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    */

    'verification' => [
        'chunk_size' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | security:doctor
    |--------------------------------------------------------------------------
    |
    | Thresholds for the operational checks. Anchors older than max_anchor_age
    | while newer events exist, or no intact full verification within
    | max_verification_age, are reported. The doctor also verifies the last
    | tail_events of every chain on the spot.
    |
    */

    'doctor' => [
        'max_anchor_age_minutes' => 60,
        'max_verification_age_hours' => 24,
        'tail_events' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Production fail-fast
    |--------------------------------------------------------------------------
    |
    | In APP_ENV=production the service provider refuses to boot with a
    | configuration that silently removes the guarantees: no signing key, an
    | SQLite audit database, append-only enforcement switched off, or no
    | readable anchor store. Set AUDIT_FAIL_FAST=false only for a deliberate,
    | temporary exception; security:doctor keeps reporting the same problems.
    |
    | require_external_anchor raises the bar: anchors inside the audit database
    | are readable, but an attacker who can rewrite the chain can rewrite them
    | too. With this switch on, production refuses to boot unless at least one
    | readable store outside the database (filesystem on object storage with
    | object lock, or a custom store) is configured and can be instantiated;
    | security:doctor then reports a database-only setup as FAIL instead of WARN.
    |
    */

    'production' => [
        'fail_fast' => env('AUDIT_FAIL_FAST', true),
        'require_external_anchor' => env('AUDIT_REQUIRE_EXTERNAL_ANCHOR', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request context
    |--------------------------------------------------------------------------
    */

    'request' => [
        'enrich' => true,
        'request_id_header' => 'X-Request-Id',
        'correlation_id_header' => 'X-Correlation-Id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata sanitising
    |--------------------------------------------------------------------------
    |
    | Keys are tokenised ("passwordConfirmation" -> password, confirmation;
    | "x-api-key" -> x, api, key). A key is redacted when one of the patterns
    | below appears as a contiguous token sequence, so "author" or
    | "session_count" survive while "session_id" and "apiKey" do not.
    | Values are also scanned for well-known secret shapes (JWTs, bearer
    | strings, PEM private keys, card numbers, cloud access keys).
    |
    */

    'sanitizer' => [
        'marker' => '[REDACTED]',
        'redact_key_patterns' => [
            'password', 'passwd', 'pwd', 'secret', 'token', 'apikey', 'api key', 'private key',
            'authorization', 'cookie', 'otp', 'totp', 'credential', 'credentials',
            'session id', 'sessionid', 'session token', 'csrf', 'xsrf', 'bearer',
            'ssn', 'iban', 'bic', 'credit card', 'card number', 'pan', 'cvv', 'cvc', 'pin',
            'recovery code', 'backup code', 'nonce', 'signature', 'client assertion',
        ],
        'pseudonymize_key_patterns' => [
            'email', 'e mail', 'mail', 'username', 'user name', 'login', 'phone', 'mobile', 'identifier',
        ],
        'allow_keys' => [
            // exact keys that are never redacted or hashed, e.g. 'token_type', 'email_verified'
            'token_type', 'email_verified', 'email_verified_at', 'login_count', 'mail_driver',
        ],
        'max_depth' => 6,
        'max_keys' => 100,
        'max_string_length' => 1024,
        // Pepper for pseudonymisation; defaults to a key derived from APP_KEY.
        'pseudonym_key' => env('AUDIT_PSEUDONYM_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant security events
    |--------------------------------------------------------------------------
    |
    | Record access denials, scope bypasses, membership changes and tenant
    | switches from efnext/security-tenant automatically.
    |
    */

    'record_tenant_events' => true,

];
