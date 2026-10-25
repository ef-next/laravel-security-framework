# Auditing guide

Recipes for `efnext/security-audit`: what to record, how to record it, what
ends up in the log, and how you find out when someone touched it. The model
behind it is in [Concepts](../concepts.md); the write path and verification
are drawn in [diagrams/audit-write-and-verification.md](../diagrams/audit-write-and-verification.md).

## 1. Record a business event

```php
use Efnext\Security\Audit\Facades\Audit;

Audit::action('invoice.paid')
    ->on($invoice)                                    // any ExternallyAddressable model …
    ->with(['total_cents' => 12500, 'method' => 'sepa'])
    ->record();

Audit::action('auth.login_failed')
    ->on('user', $email)                              // … or an explicit type + id
    ->denied()
    ->with(['reason' => 'password'])
    ->record();
```

Filled in for you: the tenant (from the active context), the actor (the
authenticated user, or `anonymous`, or `system('console')`), IP, user agent,
request id and correlation id, the time, the sequence number, the predecessor
hash, the event hash and the signature. All of it is part of the hash.

Naming: actions are `noun.verb_past` in lower snake case (`invoice.paid`,
`member.role_changed`); resource types are lowercase singular nouns. The log
outlives your code — choose names you can still read in five years.

**Your test.**

```php
it('records a paid invoice on the tenant chain', function (): void {
    Tenancy::run($this->acme->tenantId(), function (): void {
        $invoice = Invoice::create(['number' => 'A-1']);
        Audit::action('invoice.paid')->on($invoice)->record();

        $event = AuditEvent::query()->forResource('invoice', $invoice->public_id)->firstOrFail();
        expect($event->action)->toBe('invoice.paid')->and($event->sequence)->toBe(1);
    });
});
```

## 2. Business change and audit record in one transaction

```php
Audit::transaction(function () use ($invoice): void {
    $invoice->markPaid();
    Audit::action('invoice.paid')->on($invoice)->record();
});
```

`Audit::transaction()` is `DB::transaction()` plus what the chain needs: when
no transaction is open it retries the whole closure with jittered back-off on
concurrency errors (InnoDB deadlocks, PostgreSQL serialization failures,
MariaDB's snapshot-isolation error 1020), because concurrent writers to the
same tenant serialise on the chain's head row. Inside an existing transaction it
runs once.

Rules: the closure may run more than once — no e-mails, no API calls, no
non-idempotent side effects inside; queue them after it returns. Never use bare
`DB::transaction()` around an audit write: the retry rules are missing and,
under load, the first events of new chains can deadlock on InnoDB. For a brand
new tenant call `Audit::prepareChain($tenantId)` once (when you create the
tenant) so the head row exists before the first burst of events.

## 3. Actors

```php
Audit::action('export.downloaded')->on($report)->by(Actor::service('reporting-worker'))->record();
Audit::action('retention.applied')->on('tenant', $tenantId)->bySystem('scheduler')->record();
```

```php
// Let your user model describe itself (label, service accounts, impersonation)
final class User extends Authenticatable implements AuditActor
{
    public function toAuditActor(): Actor
    {
        return $this->impersonator
            ? Actor::user($this->id, "{$this->email} (impersonated by {$this->impersonator->email})")
            : Actor::user($this->id, $this->email);
    }
}
```

Actor type, id and label are hashed; labels are stored as given — do not put
secrets in them.

## 4. Metadata: what to pass, what happens to it

Pass the fields the event is about — amounts, states, identifiers of related
objects (as public ids). Never `$request->all()`, never whole models.

The sanitiser then, in this order: redacts keys whose tokens match a secret
pattern (`password`, `token`, `api_key`, `authorization`, `iban`, `cvv`, …) →
`[REDACTED]`; pseudonymises identifiers (`email`, `username`, `phone`, …) to
`email_hash` = HMAC of the value with a pepper derived from `APP_KEY` (or
`AUDIT_PSEUDONYM_KEY`); redacts *values* that look like secrets whatever the
key (JWTs, bearer strings, PEM keys, card numbers, cloud keys); bounds depth
(6), keys per level (100) and string length (1 024).

```php
->with(['customer_email' => 'zoe@example.test', 'card_number' => '4111 1111 1111 1111', 'note' => 'ok'])
// stored: {"customer_email_hash": "9f2c…", "card_number": "[REDACTED]", "note": "ok"}
```

Metadata is immutable and *sanitised, not encrypted*. Personal data that must
be erasable belongs outside the log, referenced by public id; rotating
`AUDIT_PSEUDONYM_KEY` after the retention period is the closest thing to
crypto-shredding the design offers. If you need the previous state of a
resource, put a diff into metadata yourself.

## 5. Tenant-less and global events

Under the default policy (`AUDIT_TENANT_POLICY=required`) an event without a
tenant is refused — that catches code paths that lost their context. Events
that are genuinely tenant-less go to the `global` chain explicitly:

```php
Audit::action('deploy.completed')->on('release', 'v1.0.0-beta.1')->global()->bySystem('ci')->record();
```

The tenant package's own security events (`tenant.access_denied`,
`tenant.scope_bypassed`, `tenant.membership.*`, `tenant.switched`) are recorded
automatically and land on the global chain when no tenant is known, whatever
the policy says.

## 6. Reading the log

```php
AuditEvent::query()->forResource('invoice', $invoice->public_id)->orderBy('sequence')->get();
AuditEvent::query()->forChain(ChainKey::fromString('tenant:'.$tenantId))->where('action', 'like', 'invoice.%')->latest('occurred_at')->paginate();
```

`AuditEvent` is read-only (updating or deleting throws). Columns: `chain_key`,
`tenant_id`, `sequence`, `previous_hash`, `event_hash`, `hash_version`,
`key_id`, `signature`, `occurred_at`, `occurred_at_us`, `recorded_at`, `actor_type`,
`actor_id`, `actor_label`, `action`, `resource_type`, `resource_id`, `outcome`,
`metadata` (canonical JSON), `request_id`, `correlation_id`, `ip_address`,
`user_agent`. `recorded_at` is the only descriptive column outside the hash
(it is set by the server after hashing).

## 7. Verify

```bash
php artisan audit:verify                       # every chain
php artisan audit:verify --chain=acme-uuid --from=1000
```

```php
$report = Audit::verifyTenant($tenantId);       // or verifyGlobal(), verify(ChainKey, from, to)
$report->isIntact();                           // false → look at ->violations (reason, sequence, event id, details)
```

What is checked, event by event: sequence continuity, predecessor hash,
recomputed event hash, signature (valid / unknown key / missing), hash version;
and at the end: the head row matches the last event, the newest anchor matches
the chain at that sequence and is not ahead of it. Every run is recorded in
`audit_verification_runs`; `security:doctor` uses those records for its
`verification_recency` check and verifies the last 200 events of every chain
itself.

Large installations verify incrementally: keep the last verified sequence per
chain and call `Audit::verify($chain, $lastVerified + 1)`; the verifier
checks the predecessor link, so the pieces chain up.

**Alerting.** A chain with violations dispatches `ChainViolationDetected`
(report included) and makes `audit:verify` exit 1. Listen to the event; page
someone. See [operations/incident-response.md](../operations/incident-response.md)
for what to do next.

## 8. Anchors

`audit:anchor` copies the head of every chain (sequence, hash, signature) to
the configured stores. With `database` only, an attacker who can rewrite the
chain can rewrite the anchor too, so the doctor warns. The production setup is
`AUDIT_ANCHOR_STORES=filesystem,database` with a disk on object storage that
has Object Lock, and `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` so production
refuses to boot without it. The copyable recipe is in the
[Production guide](production.md).

## 9. Signing keys

```bash
php artisan audit:key:generate --id=k2     # rotation: append to AUDIT_SIGNING_KEYS, move AUDIT_SIGNING_ACTIVE_KEY
```

Old keys stay in the list so old signatures keep verifying; remove one only
when every event signed with it has left the retention window. The keys must
live in a secret manager, never next to the database credentials — a
database-level attacker with the key can rewrite history consistently. Details
in [operations/key-rotation.md](../operations/key-rotation.md).

## 10. Separate audit connection

`AUDIT_DB_CONNECTION` moves the audit tables to their own database and user
(grants: `SELECT, INSERT` on events, anchors and runs; `SELECT, INSERT, UPDATE`
on `audit_chain_heads`). You gain privilege separation and lose atomicity with
business writes: `Audit::transaction()` cannot span two connections, so a crash
between the two writes leaves one without the other. The doctor tells you which
trade-off you have made.

## Things the audit package does not do

- prevent tampering (it makes it detectable — run `audit:verify`);
- sign or anchor without a key and without the scheduler;
- record anything you do not tell it to (except the tenant package's security events);
- encrypt metadata or solve erasure;
- verify on every write (verification is scheduled and chunked).

Full list: [What it does not do](../what-it-does-not-do.md).
