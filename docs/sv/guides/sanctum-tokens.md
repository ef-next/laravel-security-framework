<!-- source: docs/en/guides/sanctum-tokens.md sha256: 9f7b862ae8efdb4b59d559d4d92156027bde6b90de25913b5d4b4bfec0dcffaf -->
# Sanctum-tokens bundna till en tenant

*Svensk utgåva — den [engelska versionen](../../en/guides/sanctum-tokens.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

En API-token som är giltig för *en* tenant, så att en läckt token inte kan
återspelas mot en annan tenant som samma användare tillhör.

## Mekanismen

Laravel Sanctum-tokens bär abilities. Ramverket behandlar abilities av
formen `tenant:<tenant id>` som en **bindning**: middlewaren `tenant.token`
jämför dem med tenanten som `tenant.resolve` valde för requesten och nekar
en missmatchning med 403 (`This API token is not valid for this tenant.`).
Jämförelsen sker i konstant tid.

Tokens utan någon `tenant:`-ability är **inte bundna**: som standard släpps
de igenom (en personlig token för en användare som tillhör flera tenanter),
med `TENANT_TOKENS_REQUIRE_BINDING=true` nekas de med 403 (`This API token
is not bound to a tenant.`). Cookiebaserade Sanctum-sessioner (SPA) bär
ingen ability-lista och påverkas aldrig.

## 1. Utfärda en bunden token

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

Tenant-id:t i abilityn är tenantens UUID (`TenantId::toString()`), inte
sluggen eller det publika id:t.

## 2. Skydda routes

```php
Route::middleware(['auth:sanctum', 'tenant', 'tenant.token'])->group(function (): void {
    Route::get('/invoices', [InvoiceController::class, 'index']);
});
```

Ordning: `auth:sanctum` (vem), `tenant` (vilken tenant, är användaren
medlem), `tenant.token` (får *just den här token* agera där). Ramverket
ordnar dem korrekt i förhållande till route model binding oavsett i vilken
ordning du skriver dem.

## 3. Kräv bindning för maskinklienter

```dotenv
TENANT_TOKENS_REQUIRE_BINDING=true
```

Nu måste varje token som används på en `tenant.token`-route vara bunden.
Rulla ut det i två steg: utfärda bundna tokens till alla integrationer
först, slå sedan om brytaren; audit-loggen (`tenant.access_denied` med
skälet `token_not_bound`) visar vem som fortfarande använder en obunden
token.

## Säkerhetseffekt

En stulen token är begränsad till en tenant även om användaren har tillgång
till fler; en token kan inte användas för att ”byta” tenant genom att ändra
headern. Varje nekande loggas i audit-loggen med aktörens id och skälet
(`token_tenant_mismatch`, `token_not_bound`).

## Felkonfiguration

- `tenant.token` utan `tenant.resolve` före den → `TenantContextMissing`
  (500): middlewaren har ingen tenant att jämföra mot. Använd
  `tenant`-gruppen plus `tenant.token`.
- Att lägga sluggen i abilityn (`tenant:acme`) → matchar aldrig; använd
  UUID:t.
- Att förlita sig enbart på abilities för auktorisering inuti tenanten →
  ramverket kontrollerar bara *tenant*-bindningen; `invoices:write` är för
  dina policies (`$request->user()->tokenCan('invoices:write')`).

## Ditt test

Använd riktiga tokens: `Sanctum::actingAs()` bifogar en mock-token vars
`abilities`-attribut middlewaren inte kan läsa, så den ser alltid ut att
vara *obunden*.

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
