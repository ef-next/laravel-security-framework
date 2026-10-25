<!-- source: docs/en/guides/sanctum-tokens.md sha256: 9f7b862ae8efdb4b59d559d4d92156027bde6b90de25913b5d4b4bfec0dcffaf -->
# An einen Tenant gebundene Sanctum-Tokens

*Deutsche Ausgabe — die [englische Fassung](../../en/guides/sanctum-tokens.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Ein API-Token, das nur für *einen* Tenant gültig ist, sodass ein geleaktes
Token nicht gegen einen anderen Tenant wiederverwendet werden kann, zu dem
derselbe Benutzer gehört.

## Der Mechanismus

Laravel-Sanctum-Tokens tragen Abilities. Das Framework behandelt Abilities
der Form `tenant:<tenant id>` als **Bindung**: Die Middleware
`tenant.token` vergleicht sie mit dem Tenant, den `tenant.resolve` für die
Anfrage ausgewählt hat, und verweigert eine Abweichung mit 403 (`This API
token is not valid for this tenant.`). Der Vergleich erfolgt zeitkonstant.

Tokens ohne jede `tenant:`-Ability sind **nicht gebunden**: Standardmäßig
passieren sie (ein persönliches Token eines Benutzers, der zu mehreren
Tenants gehört), mit `TENANT_TOKENS_REQUIRE_BINDING=true` werden sie mit 403
verweigert (`This API token is not bound to a tenant.`). Cookie-basierte
Sanctum-Sessions (SPA) tragen keine Ability-Liste und sind davon nie
betroffen.

## 1. Ein gebundenes Token ausstellen

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

Die Tenant-ID in der Ability ist die UUID des Tenants
(`TenantId::toString()`), nicht der Slug oder die Public ID.

## 2. Routen schützen

```php
Route::middleware(['auth:sanctum', 'tenant', 'tenant.token'])->group(function (): void {
    Route::get('/invoices', [InvoiceController::class, 'index']);
});
```

Reihenfolge: `auth:sanctum` (wer), `tenant` (welcher Tenant, ist der
Benutzer Mitglied), `tenant.token` (darf *dieses Token* dort handeln). Das
Framework ordnet sie relativ zum Route-Model-Binding korrekt, unabhängig
davon, in welcher Reihenfolge Sie sie schreiben.

## 3. Bindung für maschinelle Clients erzwingen

```dotenv
TENANT_TOKENS_REQUIRE_BINDING=true
```

Jetzt muss jedes Token, das auf einer `tenant.token`-Route verwendet wird,
gebunden sein. Rollen Sie es in zwei Schritten aus: Stellen Sie zunächst
allen Integrationen gebundene Tokens aus, dann schalten Sie um; das
Audit-Log (`tenant.access_denied` mit dem Grund `token_not_bound`) zeigt,
wer noch ein ungebundenes Token verwendet.

## Sicherheitswirkung

Ein gestohlenes Token ist auf einen Tenant beschränkt, selbst wenn der
Benutzer Zugriff auf mehrere hat; ein Token kann nicht dazu verwendet
werden, durch Ändern des Headers den Tenant zu „wechseln“. Jede
Verweigerung wird mit der Akteur-ID und dem Grund auditiert
(`token_tenant_mismatch`, `token_not_bound`).

## Fehlkonfiguration

- `tenant.token` ohne vorangehendes `tenant.resolve` → `TenantContextMissing`
  (500): Die Middleware hat keinen Tenant, mit dem sie vergleichen kann.
  Verwenden Sie die Gruppe `tenant` plus `tenant.token`.
- Den Slug in die Ability zu setzen (`tenant:acme`) → passt nie; verwenden
  Sie die UUID.
- Sich für die Autorisierung innerhalb des Tenants allein auf Abilities zu
  verlassen → das Framework prüft nur die *Tenant*-Bindung; `invoices:write`
  ist Sache Ihrer Policies (`$request->user()->tokenCan('invoices:write')`).

## Ihr Test

Verwenden Sie echte Tokens: `Sanctum::actingAs()` hängt ein Mock-Token an,
dessen `abilities`-Attribut die Middleware nicht lesen kann, sodass es immer
*ungebunden* aussieht.

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
