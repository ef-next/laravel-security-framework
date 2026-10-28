<!-- source: docs/en/getting-started.md sha256: 29f71b1526afffb5248b11c06c7f61f21bdc871ae499a7877f7d31da9d621ad0 -->
# Kom igång

*Svensk utgåva — den [engelska versionen](../en/getting-started.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Ett scenario från en tom Laravel-applikation till en körande, verifierad
installation: ett multi-tenant faktura-API för fakturering. Varje steg är
komplett; du bör inte behöva någon annan sida eller ramverkets källkod medan
du följer det. Samma kod, körbar och testad, finns i
[`examples/laravel-app`](../../examples/laravel-app).

Vad du har i slutet: två tenants, en användare som är medlem i en av dem, en
`Invoice`-modell som varken kan läsas eller skrivas utanför sin tenant, ett
publikt id i varje URL, en audit-händelse för varje affärsförändring som en
databasadministratör inte kan ändra obemärkt, ett jobb som för sin tenant med
sig, och en verifierad utvecklingsinstallation: `security:doctor` rapporterar
varje kontroll grön utom de två fynd som en utvecklingsmiljö förväntas ha
(SQLite, enbart databasankare) — steg 9 visar den exakta utskriften, och
[produktionsguiden](guides/production.md) gör även de två gröna.

## 0. Förutsättningar

- PHP 8.3, 8.4 eller 8.5, Composer, en ny Laravel 13-applikation (`laravel new billing`).
- PostgreSQL, MySQL 8 eller MariaDB för allt bortom denna genomgång. SQLite
  fungerar medan du följer den — ramverket vägrar SQLite i produktion och
  säger det till dig.
- För API-delen: `php artisan install:api` (Laravel Sanctum).

## 1. Installera

```bash
composer require "efnext/security-framework:^1.0@beta"   # pre-release: the stability flag is required (README → Status)
php artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config
```

Detta lägger till `config/tenant.php`, `config/audit.php` och
`config/addressing.php`. Ramverkets migrationer (`tenants`,
`tenant_memberships`, `audit_events`, `audit_chain_heads`, `audit_anchors`,
`audit_verification_runs`) laddas automatiskt och körs först.

## 2. Miljö

```bash
php artisan audit:key:generate
```

skriver ut två rader; lägg dem i `.env` tillsammans med tjänstenamnet:

```dotenv
SECURITY_SERVICE_NAME=billing
TENANT_USER_KEY_TYPE=unsignedBigInteger      # the type of users.id — must match before the first migrate

AUDIT_SIGNING_KEYS="k1:base64:…"             # from audit:key:generate
AUDIT_SIGNING_ACTIVE_KEY=k1
```

`.env.example` i ramverkets repository listar varje annan variabel med dess
standardvärde. Sedan:

```bash
php artisan migrate
```

På MySQL/MariaDB med binär loggning behöver migrationsanvändaren `SUPER`
eller servervariabeln `log_bin_trust_function_creators=1`, eftersom
audit-tabellerna får triggrar som nekar `UPDATE` och `DELETE`.

## 3. Användare och tenants

```php
// app/Models/User.php
use Efnext\Security\Tenant\Membership\HasTenantMemberships;

class User extends Authenticatable
{
    use HasTenantMemberships;
    // …
}
```

Skapa två tenants och ett medlemskap — en seeder, Tinker eller ett test:

```php
use Efnext\Security\Tenant\Eloquent\Tenant;

$acme = Tenant::create(['name' => 'Acme Ltd', 'slug' => 'acme']);
$beta = Tenant::create(['name' => 'Beta GmbH', 'slug' => 'beta']);

$user = User::factory()->create(['email' => 'alice@acme.test']);
$user->grantTenantMembership($acme->tenantId(), 'owner');
```

Tenants får en UUID, ett publikt id (`ten_…`) och en status (`active`).
Roller kommer från `config/tenant.php` (`owner`, `admin`, `member`,
`viewer`, högst först).

## 4. Fakturan: tenant-bunden, publikt adresserbar

```php
// app/Models/Invoice.php
use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;
use Efnext\Security\Addressing\Eloquent\HasPublicId;
use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model implements ExternallyAddressable
{
    use BelongsToTenant;
    use HasPublicId;
    use HasGlobalObjectId;

    protected $fillable = ['number', 'total_cents', 'status'];   // note: no tenant_id, no public_id

    public static function publicIdPrefix(): string { return 'inv'; }
    public static function resourceType(): string { return 'invoice'; }

    public function lines(): HasMany { return $this->hasMany(InvoiceLine::class); }

    public function markPaid(): void
    {
        $this->update(['status' => 'paid']);
    }
}
```

```php
// app/Models/InvoiceLine.php
class InvoiceLine extends Model
{
    use BelongsToTenant;

    protected $fillable = ['invoice_id', 'description', 'amount_cents'];
}
```

```php
// database/migrations/2026_01_01_000000_create_invoices_tables.php
Schema::create('invoices', function (Blueprint $table): void {
    $table->id();
    $table->tenantId();          // uuid tenant_id + FK to tenants + index + UNIQUE(tenant_id, id)
    $table->publicId();          // inv_… , unique
    $table->string('number');
    $table->unsignedBigInteger('total_cents')->default(0);
    $table->string('status')->default('open');
    $table->timestamps();
    $table->unique(['tenant_id', 'number']);   // uniqueness is per tenant, so the tenant column is part of it
});

Schema::create('invoice_lines', function (Blueprint $table): void {
    $table->id();
    $table->tenantId();
    $table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete();   // (tenant_id, invoice_id) -> invoices(tenant_id, id)
    $table->string('description');
    $table->unsignedBigInteger('amount_cents');
    $table->timestamps();
});
```

Vad detta ger dig: varje fråga på `Invoice` och `InvoiceLine` är avgränsad
till den aktiva tenanten och *kastar* utan en; en rad kan bara referera till
en faktura från samma tenant, även via rå SQL; en faktura adresseras som
`inv_7f3k9zq2m4x8c1bt` i URL:er och som
`billing:invoice:inv_7f3k9zq2m4x8c1bt` överallt där ett globalt namn behövs.

## 5. Routes

```php
// routes/api.php
use App\Http\Controllers\InvoiceController;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);          // {invoice} binds by public id
    Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->middleware('tenant.role:admin');
});
```

`tenant` är `tenant.resolve` (hitta tenanten, 400/404/403) följt av
`tenant.member` (är den autentiserade användaren en aktiv medlem? 401/403).
`tenant.role:admin` kräver dessutom rollen `admin` eller högre. Ramverket
placerar dessa före route model binding, så `{invoice}` löses upp inom
tenanten — en faktura från en annan tenant blir ett 404, inte ett läckage.

Tenanten kommer som standard från headern `X-Tenant-Id` (id, `ten_…`-publikt
id, globalt objekt-id eller slug); routeparameter, session och subdomän är
alternativen (`config/tenant.php`, `resolution.chain`).

## 6. Controllern: affärsförändring och audit-post tillsammans

```php
// app/Http/Controllers/InvoiceController.php
use App\Models\Invoice;
use Efnext\Security\Audit\Facades\Audit;
use Illuminate\Http\Request;

class InvoiceController
{
    public function index()
    {
        return Invoice::query()->latest()->get(['public_id', 'number', 'total_cents', 'status']);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['number' => 'required|string', 'total_cents' => 'required|integer|min:0']);

        return Audit::transaction(function () use ($data) {
            $invoice = Invoice::create($data);                       // tenant_id and public_id are filled in
            Audit::action('invoice.created')->on($invoice)->with(['number' => $invoice->number])->record();

            return response()->json($invoice->only('public_id', 'number', 'status'), 201);
        });
    }

    public function show(Invoice $invoice)
    {
        return $invoice->load('lines');
    }

    public function pay(Invoice $invoice)
    {
        Audit::transaction(function () use ($invoice): void {
            $invoice->markPaid();
            Audit::action('invoice.paid')->on($invoice)->with(['total_cents' => $invoice->total_cents])->record();
        });

        return response()->noContent();
    }
}
```

`Audit::transaction()` är `DB::transaction()` plus de återförsöksregler
kedjan behöver: affärsförändring och audit-post commitas tillsammans, och en
konflikt på tenantens kedja (två betalningar samtidigt) görs om med backoff
i stället för att misslyckas. Closuren kan därför köras mer än en gång — håll
e-post och API-anrop utanför den.

## 7. Prova det

Skapa en token för Alice (`$user->createToken('cli')->plainTextToken`) och:

```bash
curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: acme" -H "Accept: application/json" \
     -d number=A-1 -d total_cents=12500 http://localhost:8000/api/invoices
# {"public_id":"inv_7f3k9zq2m4x8c1bt","number":"A-1","status":"open"}

curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: acme" -H "Accept: application/json" \
     http://localhost:8000/api/invoices/inv_7f3k9zq2m4x8c1bt
# {"id":1,"public_id":"inv_7f3k9zq2m4x8c1bt","number":"A-1", … ,"lines":[]}
```

Och avvisningarna, som är själva poängen:

| Request | Svar | Varför |
| --- | --- | --- |
| ingen `X-Tenant-Id` | `400 {"message":"No tenant identifier found in the request (checked: header X-Tenant-Id, route parameter {tenant}, session key efnext.tenant)."}` | inget att lösa upp |
| `X-Tenant-Id: nope` | `404 {"message":"Tenant not found."}` | okänd tenant |
| `X-Tenant-Id: beta` med Alices token | `403 {"message":"You are not a member of this tenant."}` | Alice tillhör bara Acme; en audit-händelse `tenant.access_denied` skrivs |
| `POST /invoices/inv_…/pay` som en `member` | `403 {"message":"Your role in this tenant does not permit this action."}` | `tenant.role:admin` |
| `GET /invoices/inv_zzzzzzzzzzzzzzzz` | `404` | felaktigt bildat eller främmande id — ingen fråga görs ens för ett felaktigt bildat id |
| `Invoice::count()` i Tinker utan en tenant | `TenantContextMissing: No tenant context: cannot query App\Models\Invoice without an active tenant. Resolve a tenant first (tenant middleware), wrap the code in Tenancy::run(...), or declare a deliberate cross-tenant operation with Tenancy::withoutTenant("reason", ...)` | fail closed |

## 8. Jobb och kommandon

```php
use App\Jobs\SendInvoiceReminder;
use Efnext\Security\Tenant\Facades\Tenancy;

SendInvoiceReminder::dispatch($invoice);                       // inside a request: the tenant travels with the job
Tenancy::run($acme->tenantId(), fn () => Invoice::count());   // explicit context in code
```

```bash
php artisan tenant:run acme "invoices:remind"                  # explicit context on the CLI
```

Inuti jobbet är `Invoice`-frågor avgränsade till tenanten jobbet skickades
från; ett jobb som skickas utan en tenant misslyckas (fail closed) när det
rör vid tenant-bundna data. Ett jobb som känner till sin egen tenant
implementerar `TenantAware` och returnerar `[new TenantContextMiddleware]`
från `middleware()`.

## 9. Ankra, verifiera, doctor

```php
// routes/console.php
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->daily()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
```

Kör dem en gång för hand:

```
$ php artisan audit:anchor
  tenant:0192b6a4-3c1e-7d2f-9a5b-4e6c8d0f1a2b ....... anchored #2 (signed k1)
  Store ............................................ database
  Anchors written .................................. 1

$ php artisan audit:verify
  tenant:0192b6a4-3c1e-7d2f-9a5b-4e6c8d0f1a2b intact  2 events, signatures: valid, anchor: #2
  Chains checked ................................... 1
  Chains with violations ........................... 0

$ php artisan security:doctor
  [OK] Environment .................. APP_ENV=local, debug on, fail-fast on.
  [OK] Application key  APP_KEY is set (pseudonymisation pepper derived from it).
  [OK] Signing key ......................... 1 key(s) in the ring, active: k1.
  [FAIL] Database driver ................... Connection "sqlite" uses SQLite.
         Fine for development and tests; production needs PostgreSQL, MySQL or MariaDB (row locks across connections, durability).
  [OK] Append-only enforcement  Enabled (triggers are installed by the migration).
  [OK] Append-only triggers  All 4 triggers present on audit_events and audit_anchors.
  [WARN] Anchor stores ..................... Only the database store is readable.
         Anchors inside the audit database are not independent of it; … Add `filesystem` (S3 with Object Lock) … and consider AUDIT_REQUIRE_EXTERNAL_ANCHOR=true.
  [OK] Audit connection  Audit tables share the application connection "sqlite"; `Audit::transaction()` is atomic with business writes.
  [OK] Anchor freshness  1 chain(s) anchored at their head, 0 with recent unanchored events (within 60 min).
  [OK] Full verification  All 1 chain(s) fully verified and intact within the last 24 h.
  [OK] Tail verification  Last 200 event(s) of every chain verified now: 2 events, all intact.
  [OK] Tenant middleware priority  ResolveTenant runs before SubstituteBindings.
  [OK] Tenant lookup cache .. Store "file" (file), TTL 60s, negative TTL 5s.

  OK / WARN / FAIL / SKIP ...................................... 11 / 1 / 1 / 0
```

De två raderna som inte är OK är exakt de två saker som skiljer denna
genomgång från produktion: en riktig databas, och ett ankarlager utanför den.
[Produktionsguiden](guides/production.md) gör båda gröna; `security:doctor
--strict` är det deployment-gate som bevisar det.

## 10. Bevisa garantierna i din egen testsvit

```php
use App\Models\Invoice;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Facades\Tenancy;

it('refuses tenant-owned queries without a tenant', function (): void {
    expect(fn () => Invoice::query()->count())->toThrow(TenantContextMissing::class);
});

it('does not leak invoices across tenants', function (): void {
    Tenancy::run($this->acme->tenantId(), fn () => Invoice::create(['number' => 'A-1']));

    Tenancy::run($this->beta->tenantId(), fn () => expect(Invoice::count())->toBe(0));
});

it('rejects a member of another tenant', function (): void {
    $this->actingAs($this->alice)
        ->getJson('/api/invoices', ['X-Tenant-Id' => 'beta'])
        ->assertForbidden();
});
```

Exempelapplikationen levererar dessa och några till; kopiera dem.

## Vart härnäst

- [Tenancy-guiden](guides/tenancy.md) — resolvers, roller, byte av tenant, tenant-övergripande arbete, egna kolumner.
- [Addressing-guiden](guides/addressing.md) — publika och globala id:n på djupet.
- [Auditing-guiden](guides/auditing.md) — vad som ska registreras, metadata, aktörer, verifiering.
- [Produktionsguiden](guides/production.md) — nycklar, Object Lock, `--strict`, övervakning.
- [Felsökning](guides/troubleshooting.md) — efter symptom.
- [Checklista inför driftsättning](integration-checklist.md) — bocka av den före go-live.
