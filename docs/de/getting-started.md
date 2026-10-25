<!-- source: docs/en/getting-started.md sha256: a5c19fa283454d49323b40e2ad44145a9ec7ed7450877f21b8507efded549b2b -->
# Erste Schritte

*Deutsche Ausgabe — die [englische Fassung](../en/getting-started.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Ein Szenario von einer leeren Laravel-Anwendung bis zu einer laufenden,
verifizierten Installation: eine Multi-Tenant-Billing-API mit Rechnungen.
Jeder Schritt ist für sich vollständig; Sie sollten während der Durchführung
keine weitere Seite und nicht den Quellcode des Frameworks benötigen. Derselbe
Code, lauffähig und getestet, liegt in
[`examples/laravel-app`](../../examples/laravel-app).

Was Sie am Ende haben: zwei Tenants, einen Benutzer, der Mitglied eines von
ihnen ist, ein `Invoice`-Model, das außerhalb seines Tenants weder gelesen
noch geschrieben werden kann, eine Public ID in jeder URL, ein Audit-Ereignis
für jede Geschäftsänderung, das ein Datenbankadministrator nicht unbemerkt
verändern kann, einen Job, der seinen Tenant mitführt, und eine verifizierte
Entwicklungsinstallation: `security:doctor` meldet jede Prüfung grün bis auf
die zwei Befunde, die ein Entwicklungs-Setup erwartungsgemäß hat (SQLite,
nur Datenbank-Anker) — Schritt 9 zeigt die genaue Ausgabe, und der
[Produktionsleitfaden](guides/production.md) bringt auch diese beiden auf
Grün.

## 0. Voraussetzungen

- PHP 8.3 oder 8.4, Composer, eine frische Laravel-12-Anwendung (`laravel new billing`).
- PostgreSQL, MySQL 8 oder MariaDB für alles, was über diesen Rundgang
  hinausgeht. SQLite eignet sich, solange Sie ihm folgen — das Framework
  verweigert SQLite in Produktion und teilt Ihnen das mit.
- Für den API-Teil: `php artisan install:api` (Laravel Sanctum).

## 1. Installation

```bash
composer require "efnext/security-framework:^1.0@beta"   # pre-release: the stability flag is required (README → Status)
php artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config
```

Das fügt `config/tenant.php`, `config/audit.php` und `config/addressing.php`
hinzu. Die Migrationen des Frameworks (`tenants`, `tenant_memberships`,
`audit_events`, `audit_chain_heads`, `audit_anchors`,
`audit_verification_runs`) werden automatisch geladen und laufen zuerst.

## 2. Umgebung

```bash
php artisan audit:key:generate
```

gibt zwei Zeilen aus; tragen Sie diese zusammen mit dem Dienstnamen in `.env` ein:

```dotenv
SECURITY_SERVICE_NAME=billing
TENANT_USER_KEY_TYPE=unsignedBigInteger      # the type of users.id — must match before the first migrate

AUDIT_SIGNING_KEYS="k1:base64:…"             # from audit:key:generate
AUDIT_SIGNING_ACTIVE_KEY=k1
```

`.env.example` im Framework-Repository listet jede weitere Variable mit ihrem
Standardwert. Dann:

```bash
php artisan migrate
```

Bei MySQL/MariaDB mit aktiviertem Binary Logging benötigt der
Migrationsbenutzer `SUPER` oder die Servervariable
`log_bin_trust_function_creators=1`, weil die Audit-Tabellen Trigger erhalten,
die `UPDATE` und `DELETE` verweigern.

## 3. Benutzer und Tenants

```php
// app/Models/User.php
use Efnext\Security\Tenant\Membership\HasTenantMemberships;

class User extends Authenticatable
{
    use HasTenantMemberships;
    // …
}
```

Erstellen Sie zwei Tenants und eine Mitgliedschaft — per Seeder, Tinker oder Test:

```php
use Efnext\Security\Tenant\Eloquent\Tenant;

$acme = Tenant::create(['name' => 'Acme Ltd', 'slug' => 'acme']);
$beta = Tenant::create(['name' => 'Beta GmbH', 'slug' => 'beta']);

$user = User::factory()->create(['email' => 'alice@acme.test']);
$user->grantTenantMembership($acme->tenantId(), 'owner');
```

Tenants erhalten eine UUID, eine Public ID (`ten_…`) und einen Status
(`active`). Rollen stammen aus `config/tenant.php` (`owner`, `admin`,
`member`, `viewer`, höchste zuerst).

## 4. Die Rechnung: tenant-gebunden, öffentlich adressierbar

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

Das bringt Ihnen: Jede Abfrage auf `Invoice` und `InvoiceLine` ist auf den
aktiven Tenant beschränkt und *wirft eine Exception*, wenn keiner aktiv ist;
eine Position kann nur auf eine Rechnung desselben Tenants verweisen, selbst
über rohes SQL; eine Rechnung wird in URLs als `inv_7f3k9zq2m4x8c1bt`
adressiert und überall dort, wo ein globaler Name benötigt wird, als
`billing:invoice:inv_7f3k9zq2m4x8c1bt`.

## 5. Routen

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

`tenant` ist `tenant.resolve` (den Tenant finden, 400/404/403), gefolgt von
`tenant.member` (ist der authentifizierte Benutzer ein aktives Mitglied?
401/403). `tenant.role:admin` verlangt zusätzlich die Rolle `admin` oder
höher. Das Framework setzt diese vor das Route-Model-Binding, sodass
`{invoice}` innerhalb des Tenants aufgelöst wird — eine Rechnung eines anderen
Tenants ist ein 404, kein Leck.

Der Tenant stammt standardmäßig aus dem Header `X-Tenant-Id` (ID, Public ID
`ten_…`, Global Object ID oder Slug); Routenparameter, Session und Subdomain
sind die Alternativen (`config/tenant.php`, `resolution.chain`).

## 6. Der Controller: Geschäftsänderung und Audit-Datensatz zusammen

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

`Audit::transaction()` ist `DB::transaction()` plus die Wiederholungsregeln,
die die Kette braucht: Geschäftsänderung und Audit-Datensatz committen
zusammen, und ein Konflikt auf der Kette des Tenants (zwei Zahlungen
gleichzeitig) wird mit Backoff erneut versucht, statt fehlzuschlagen. Die
Closure kann deshalb mehr als einmal laufen — halten Sie E-Mails und
API-Aufrufe außerhalb davon.

## 7. Ausprobieren

Erstellen Sie ein Token für Alice (`$user->createToken('cli')->plainTextToken`) und:

```bash
curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: acme" -H "Accept: application/json" \
     -d number=A-1 -d total_cents=12500 http://localhost:8000/api/invoices
# {"public_id":"inv_7f3k9zq2m4x8c1bt","number":"A-1","status":"open"}

curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: acme" -H "Accept: application/json" \
     http://localhost:8000/api/invoices/inv_7f3k9zq2m4x8c1bt
# {"id":1,"public_id":"inv_7f3k9zq2m4x8c1bt","number":"A-1", … ,"lines":[]}
```

Und die Verweigerungen, um die es hier geht:

| Anfrage | Antwort | Warum |
| --- | --- | --- |
| kein `X-Tenant-Id` | `400 {"message":"No tenant identifier found in the request (checked: header X-Tenant-Id, route parameter {tenant}, session key efnext.tenant)."}` | es gibt nichts aufzulösen |
| `X-Tenant-Id: nope` | `404 {"message":"Tenant not found."}` | unbekannter Tenant |
| `X-Tenant-Id: beta` mit Alices Token | `403 {"message":"You are not a member of this tenant."}` | Alice gehört nur zu Acme; ein Audit-Ereignis `tenant.access_denied` wird geschrieben |
| `POST /invoices/inv_…/pay` als `member` | `403 {"message":"Your role in this tenant does not permit this action."}` | `tenant.role:admin` |
| `GET /invoices/inv_zzzzzzzzzzzzzzzz` | `404` | fehlerhaft gebildete oder fremde ID — für eine fehlerhafte wird nicht einmal eine Abfrage ausgeführt |
| `Invoice::count()` in Tinker ohne Tenant | `TenantContextMissing: No tenant context: cannot query App\Models\Invoice without an active tenant. Resolve a tenant first (tenant middleware), wrap the code in Tenancy::run(...), or declare a deliberate cross-tenant operation with Tenancy::withoutTenant("reason", ...)` | fail closed (im Zweifel verweigern) |

## 8. Jobs und Befehle

```php
use App\Jobs\SendInvoiceReminder;
use Efnext\Security\Tenant\Facades\Tenancy;

SendInvoiceReminder::dispatch($invoice);                       // inside a request: the tenant travels with the job
Tenancy::run($acme->tenantId(), fn () => Invoice::count());   // explicit context in code
```

```bash
php artisan tenant:run acme "invoices:remind"                  # explicit context on the CLI
```

Innerhalb des Jobs sind `Invoice`-Abfragen auf den Tenant beschränkt, aus dem
der Job versendet wurde; ein Job, der ohne Tenant versendet wurde, verweigert
(fail closed), sobald er tenant-gebundene Daten berührt. Ein Job, der seinen
eigenen Tenant kennt, implementiert `TenantAware` und gibt
`[new TenantContextMiddleware]` aus `middleware()` zurück.

## 9. Ankern, verifizieren, Doctor

```php
// routes/console.php
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->daily()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
```

Führen Sie sie einmal von Hand aus:

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

Die beiden nicht-OK-Zeilen sind genau die zwei Dinge, die diesen Rundgang von
der Produktion unterscheiden: eine echte Datenbank und ein Anker-Store
außerhalb davon. Der [Produktionsleitfaden](guides/production.md) macht beide
grün; `security:doctor --strict` ist das Deployment-Gate, das das beweist.

## 10. Die Garantien in Ihrer eigenen Testsuite belegen

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

Die Beispielanwendung liefert diese und noch einige weitere mit; kopieren Sie sie.

## Wie weiter

- [Tenancy-Leitfaden](guides/tenancy.md) — Resolver, Rollen, Tenant wechseln, tenantübergreifende Arbeit, benutzerdefinierte Spalten.
- [Addressing-Leitfaden](guides/addressing.md) — Public und Global IDs im Detail.
- [Auditing-Leitfaden](guides/auditing.md) — was aufgezeichnet wird, Metadaten, Akteure, Verifikation.
- [Produktionsleitfaden](guides/production.md) — Schlüssel, Object Lock, `--strict`, Monitoring.
- [Fehlersuche](guides/troubleshooting.md) — nach Symptom.
- [Integrations-Checkliste](integration-checklist.md) — vor dem Go-live abhaken.
