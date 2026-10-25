# Addressing guide

`efnext/security-addressing` gives models two identifiers that are safe to
show: a **public id** (`inv_7f3k9zq2m4x8c1bt`) and a **global object id**
(`billing:invoice:inv_7f3k9zq2m4x8c1bt`). Internal auto-increment keys stay
internal.

## Why

Sequential ids leak business volume (`/invoices/4711` says you have 4,711
invoices), invite enumeration (`/invoices/4712`), and are only unique inside
one table of one service. A public id is unguessable (80 bits of randomness by
default), carries a type prefix that makes logs and support tickets readable,
and is immutable once assigned. A global object id adds the service and the
resource type, so that an audit trail, a message on a bus or another service
can name the object without ambiguity.

What they are **not**: authorization. Knowing an id is not a permission. The
tenant middleware and your policies decide who may see the invoice; the id
only stops guessing.

## 1. Give a model a public id

```php
use Efnext\Security\Addressing\Eloquent\HasPublicId;

class Invoice extends Model
{
    use HasPublicId;

    public static function publicIdPrefix(): string { return 'inv'; }   // [a-z][a-z0-9]{1,11}
}

Schema::create('invoices', function (Blueprint $table): void {
    $table->id();
    $table->publicId();     // string(80), unique; column name from config (public_id)
    // …
});
```

- Assigned on `creating`; a pre-set value is accepted only if it is well-formed
  and carries the model's prefix.
- Immutable: changing it on a persisted model throws `PublicIdImmutable`.
- Guarded against mass assignment automatically.
- `$invoice->publicId()` returns a `PublicId` value object (`->toString()`,
  `->prefix`, `->body`); before the model is created it throws `MissingPublicId`.

**Route model binding** uses the public id from now on:

```php
Route::get('/invoices/{invoice}', fn (Invoice $invoice) => $invoice);
```

A malformed value (`inv_x`, `4711`, `../etc`) returns 404 *without a database
query*; an unknown or — inside a tenant — foreign id returns 404 after one
indexed lookup. Queries: `Invoice::wherePublicId($id)` (malformed → no rows,
never an error).

**Security effect.** No enumeration, no volume leak, readable identifiers.
**Misconfiguration.** Exposing `id` anyway in JSON responses — add `id` to
`$hidden` or use API resources; using `find($request->id)` instead of route
binding or `wherePublicId()`.

**Your test.**

```php
it('binds by public id and hides malformed and foreign ids behind 404', function (): void {
    $invoice = Tenancy::run($this->acme->tenantId(), fn () => Invoice::create(['number' => 'A-1']));

    $this->actingAs($this->alice)->getJson("/api/invoices/{$invoice->public_id}", ['X-Tenant-Id' => 'acme'])->assertOk();
    $this->actingAs($this->alice)->getJson('/api/invoices/inv_x', ['X-Tenant-Id' => 'acme'])->assertNotFound();
    $this->actingAs($this->alice)->getJson('/api/invoices/'.$invoice->id, ['X-Tenant-Id' => 'acme'])->assertNotFound();
});
```

## 2. Give it a global object id

```php
use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;

class Invoice extends Model implements ExternallyAddressable
{
    use HasPublicId;
    use HasGlobalObjectId;

    public static function publicIdPrefix(): string { return 'inv'; }
    public static function resourceType(): string { return 'invoice'; }   // [a-z][a-z0-9-]{0,62}
}

$invoice->globalObjectId()->toString();   // billing:invoice:inv_7f3k9zq2m4x8c1bt
Invoice::whereGlobalObjectId($id)->first();   // no rows for another service or type
```

The first segment is `SECURITY_SERVICE_NAME` (`config('addressing.service_name')`).
Set it once, before the first id leaves the system, and never change it: every
issued global id would stop resolving.

**When to use which.** Public id in URLs, API payloads, UI and logs of *this*
application. Global object id whenever the object is named *outside* it: audit
events (`Audit::action()->on($invoice)` uses `resourceType()` and the public
id), messages to other services, cross-service references, support tooling.
`ExternallyAddressable` is what the audit builder and the tenant model rely on.

## 3. Parsing untrusted input

```php
use Efnext\Security\Addressing\Identifiers\GlobalObjectId;
use Efnext\Security\Addressing\Identifiers\PublicId;

$id = PublicId::tryFromString($request->query('id'));            // null when malformed; fromString() throws
if ($id === null || ! $id->hasPrefix('inv')) { abort(404); }

$gid = GlobalObjectId::tryFromString($payload['object']);
if ($gid === null || ! $gid->is('billing', 'invoice')) { … }
```

Both value objects are canonical-or-invalid: lowercase only, fixed shapes
(`prefix_body`, body 8–64 lowercase alphanumerics; segments `[a-z][a-z0-9-]{0,62}`).
There is no "normalise" step that could make two spellings equal.

## 4. Bulk inserts

`insert()` bypasses Eloquent events, so nothing assigns the id and the `NOT
NULL` column refuses the row. Either create through the model, or generate ids
explicitly:

```php
$generator = app(\Efnext\Security\Addressing\Contracts\PublicIdGenerator::class);

DB::table('invoices')->insert(array_map(fn (array $row) => $row + [
    'public_id' => $generator->generateUnique('inv', 'invoices', 'public_id')->toString(),
], $rows));
```

`generateUnique()` checks the table before returning; the `UNIQUE` index
remains the real guarantee.

## 5. Tuning (rarely needed)

| Setting | Default | Note |
| --- | --- | --- |
| `SECURITY_PUBLIC_ID_ENTROPY_BYTES` | 10 (80 bits) | below 8 the generator refuses |
| `SECURITY_PUBLIC_ID_ENCODING` | `base32` (Crockford alphabet, 16 chars for 10 bytes) | `hex` doubles the length |
| `SECURITY_PUBLIC_ID_COLUMN` | `public_id` | per model: `publicIdColumn()` |
| `SECURITY_PUBLIC_ID_MAX_ATTEMPTS` | 5 | collision retries; practically never used at 80 bits |

## Things the addressing package does not do

- authorize access (see above);
- hide the internal `id` from JSON by itself;
- rename or migrate ids after a change of `SECURITY_SERVICE_NAME` or a prefix;
- protect models that do not use the trait.
