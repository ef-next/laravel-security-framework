# Sanctum tokens bound to a tenant

An API token that is valid for *one* tenant, so that a leaked token cannot be
replayed against another tenant the same user belongs to.

## The mechanism

Laravel Sanctum tokens carry abilities. The framework treats abilities of the
form `tenant:<tenant id>` as a **binding**: the `tenant.token` middleware
compares them with the tenant that `tenant.resolve` selected for the request
and refuses a mismatch with 403 (`This API token is not valid for this tenant.`).
The comparison is constant-time.

Tokens without any `tenant:` ability are **not bound**: by default they pass
(a personal token of a user who belongs to several tenants), with
`TENANT_TOKENS_REQUIRE_BINDING=true` they are refused with 403 (`This API token
is not bound to a tenant.`). Cookie-based Sanctum sessions (SPA) carry no
ability list and are never affected.

## 1. Issue a bound token

```php
Route::post('/tenants/{tenant}/tokens', function (Request $request) {
    $tenantId = Tenancy::requireId();                                     // the tenant of this request, already membership-checked

    $token = $request->user()->createToken(
        name: $request->string('name'),
        abilities: ['tenant:'.$tenantId->toString(), 'invoices:read', 'invoices:write'],
        expiresAt: now()->addDays(90),
    );

    return ['token' => $token->plainTextToken];
})->middleware(['auth:sanctum', 'tenant', 'tenant.role:admin']);
```

The tenant id in the ability is the tenant's UUID (`TenantId::toString()`),
not the slug or the public id.

## 2. Protect routes

```php
Route::middleware(['auth:sanctum', 'tenant', 'tenant.token'])->group(function (): void {
    Route::get('/invoices', [InvoiceController::class, 'index']);
});
```

Order: `auth:sanctum` (who), `tenant` (which tenant, is the user a member),
`tenant.token` (is *this token* allowed to act there). The framework orders
them correctly relative to route model binding whatever order you write.

## 3. Require binding for machine clients

```dotenv
TENANT_TOKENS_REQUIRE_BINDING=true
```

Now every token used on a `tenant.token` route must be bound. Roll it out in
two steps: issue bound tokens to all integrations first, then flip the switch;
the audit log (`tenant.access_denied` with reason `token_not_bound`) shows who
still uses an unbound token.

## Security effect

A stolen token is limited to one tenant even if the user has access to more;
a token cannot be used to "switch" tenants by changing the header. Every
denial is audited with the actor id and the reason (`token_tenant_mismatch`,
`token_not_bound`).

## Misconfiguration

- `tenant.token` without `tenant.resolve` before it → `TenantContextMissing`
  (500): the middleware has no tenant to compare against. Use the `tenant`
  group plus `tenant.token`.
- Putting the slug into the ability (`tenant:acme`) → never matches; use the UUID.
- Relying on abilities alone for authorization inside the tenant → the
  framework only checks the *tenant* binding; `invoices:write` is for your
  policies (`$request->user()->tokenCan('invoices:write')`).

## Your test

Use real tokens: `Sanctum::actingAs()` attaches a mock token whose `abilities`
attribute the middleware cannot read, so it always looks *unbound*.

```php
it('refuses a token bound to another tenant', function (): void {
    $token = $this->alice->createToken('cli', ['tenant:'.$this->beta->id])->plainTextToken;

    $this->withToken($token)->getJson('/api/invoices', ['X-Tenant-Id' => 'acme'])->assertForbidden();
});

it('accepts a token bound to the request tenant', function (): void {
    $token = $this->alice->createToken('cli', ['tenant:'.$this->acme->id])->plainTextToken;

    $this->withToken($token)->getJson('/api/invoices', ['X-Tenant-Id' => 'acme'])->assertOk();
});

it('refuses unbound tokens once binding is required', function (): void {
    config()->set('tenant.tokens.require_binding', true);
    $token = $this->alice->createToken('legacy')->plainTextToken;

    $this->withToken($token)->getJson('/api/invoices', ['X-Tenant-Id' => 'acme'])->assertForbidden();
});
```
