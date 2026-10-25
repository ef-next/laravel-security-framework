<!-- source: docs/en/guides/tenancy.md sha256: 542df3db92a854eb17cea5a71badee983d4b5324fc59ba1dbbba5382cddb7561 -->
# Tenancy-guide

*Svensk utgåva — den [engelska versionen](../../en/guides/tenancy.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Recept för `efnext/security-tenant`. Varje recept har samma form:
användningsfallet, koden, vad det gör för säkerheten, den typiska
felkonfigurationen och testet som bör finnas i *din* applikation. Den mentala
modellen finns i [Koncept](../concepts.md); API:et i
[reference/api.md](../reference/api.md).

## 1. Slå upp tenanten från en header, en route-parameter, sessionen eller en subdomän

**Användningsfall.** API:er skickar `X-Tenant-Id`; en webbapp håller tenanten
i sessionen; en marknadsföringsinriktad produkt använder
`acme.app.example.com`.

```php
// config/tenant.php
'resolution' => [
    'chain' => ['header', 'route', 'session', 'subdomain'],   // first hit wins
    'header' => ['X-Tenant-Id'],
    'route_parameters' => ['tenant'],
    'session_key' => 'efnext.tenant',
    'subdomain' => ['base_domains' => ['app.example.com']],
],
```

```php
Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class);
});

Route::middleware(['auth', 'tenant'])->prefix('/t/{tenant}')->group(function (): void {   // route parameter
    Route::get('/invoices', [InvoiceController::class, 'index']);
});

Route::middleware(['auth', 'tenant.resolve:optional'])->get('/help', HelpController::class);   // may run without a tenant
```

Identifieraren kan vara tenantens UUID, dess publika id (`ten_…`), dess
globala objekt-id (`billing:tenant:ten_…` — endast denna tjänst) eller dess
slug; varje form valideras innan en fråga körs, och okända identifierare
cachas kort så att skanning är billig för dig och meningslös för skannern.

**Säkerhetseffekt.** Upplösning ger aldrig någon behörighet; den väljer bara
tenanten. `tenant.member` (del av `tenant`-gruppen) kräver sedan ett aktivt,
icke-utgånget medlemskap. Saknad identifierare → 400, okänd → 404, avstängd →
403, inte medlem → 403 plus en audit-händelse.

**Felkonfiguration.** Att lista `subdomain` utan `base_domains` (den matchar
aldrig); att lägga `tenant.resolve` på en route men glömma `tenant.member`
(tenanten väljs, men medlemskapet kontrolleras inte — använd `tenant`-gruppen
om du inte har ett skäl att låta bli).

**Ditt test.**

```php
it('selects the tenant from the header and refuses non-members', function (): void {
    $this->actingAs($this->alice)->getJson('/api/invoices', ['X-Tenant-Id' => 'acme'])->assertOk();
    $this->actingAs($this->alice)->getJson('/api/invoices', ['X-Tenant-Id' => 'beta'])->assertForbidden();
    $this->actingAs($this->alice)->getJson('/api/invoices')->assertStatus(400);
});
```

## 2. Roller

**Användningsfall.** Ägare hanterar fakturering, administratörer hanterar
användare, medlemmar läser.

```php
// config/tenant.php
'memberships' => [
    'roles' => ['owner', 'admin', 'member', 'viewer'],   // highest first
    'hierarchical' => true,                              // owner satisfies admin
    'default_role' => 'member',
],
```

```php
Route::post('/billing', …)->middleware('tenant.role:owner');
Route::post('/users', …)->middleware('tenant.role:owner,admin');    // either one (or higher)

$user->grantTenantMembership($tenantId, 'admin');
$user->hasTenantRole($tenantId, 'member');                           // true for admin and owner in hierarchical mode
```

**Säkerhetseffekt.** En roll utanför det konfigurerade vokabuläret kan varken
beviljas eller uppfylla en kontroll. Nekanden skickar `TenantAccessDenied`
med de roller som krävs, vilket audit-paketet registrerar.

**Felkonfiguration.** `hierarchical => false` medan routes bara anger den
lägsta rollen: ägare får 403. En `default_role` med privilegier: varje
inbjudan blir en administratör.

**Ditt test.** En request per skyddad route med en `viewer`, som förväntas
ge 403.

## 3. Byt tenant i användargränssnittet

**Användningsfall.** En användare tillhör flera tenanter och väljer en i en
meny.

```php
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Http\SessionTenant;

Route::post('/switch-tenant', function (Request $request, SessionTenant $session) {
    $session->switchTo($request, TenantId::fromString((string) $request->string('tenant')));   // 403 unless the user is an active member

    return redirect('/dashboard');
})->middleware('auth');
```

Med `session` i upplösningskedjan löser varje efterföljande request den
valda tenanten. `$session->current($request)` läser den,
`$session->forget($request)` rensar den.

**Säkerhetseffekt.** `switchTo()` kontrollerar medlemskap precis som
middlewaren och skickar `TenantSwitched` (loggas i audit-loggen som
`tenant.switched` med den föregående tenanten). En användare kan inte ”byta”
till en tenant de inte tillhör.

**Felkonfiguration.** Att skriva sessionsnyckeln själv i stället för att
anropa `switchTo()` — det hoppar över medlemskapskontrollen och
audit-händelsen.

## 4. Tenant-bundna modeller, egna kolumnnamn

```php
class Project extends Model
{
    use BelongsToTenant;

    protected string $tenantColumn = 'org_id';   // when the schema uses another name
}

Schema::create('projects', function (Blueprint $table): void {
    $table->id();
    $table->tenantId('org_id');                  // same column here
    // …
});
```

Allt annat är oförändrat: scope, stämpling vid create, ägarskapskontroller,
skyddad mass assignment. Lista inte tenant-kolumnen i `$fillable`; om du
måste (importverktyg) nekar ramverket ändå ett värde som skiljer sig från
den aktiva tenanten (`TenantMismatch`).

**Ditt test.** `expect(fn () => Project::query()->count())->toThrow(TenantContextMissing::class);`

## 5. Relationer mellan tenant-bundna tabeller

```php
Schema::create('invoice_lines', function (Blueprint $table): void {
    $table->id();
    $table->tenantId();
    $table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete();                    // required
    $table->tenantForeignUuid('approved_by_id', 'approvers', nullable: true)->restrictOnDelete();   // optional
});
```

**Säkerhetseffekt.** Den sammansatta nyckeln `(tenant_id, invoice_id) →
invoices(tenant_id, id)` gör att databasen nekar en rad som refererar till en
annan tenants faktura — via Eloquent, `DB::table()`, rå SQL eller ett
migreringsskript, lika mycket.

**Felkonfiguration.** `foreignId('invoice_id')->constrained()` (en vanlig
nyckel: databasen kontrollerar inte längre tenanten); `nullOnDelete()` på en
sammansatt nyckel (nekas redan när migreringen skrivs — tenant-kolumnen kan
inte nollställas; koppla loss genom update). Unikhet som ska gälla per
tenant måste inkludera tenant-kolumnen: `$table->unique(['tenant_id', 'number'])`.

**Ditt test.**

```php
it('refuses a line that points at another tenant\'s invoice, even through raw SQL', function (): void {
    $invoice = Tenancy::run($this->acme->tenantId(), fn () => Invoice::create(['number' => 'A-1']));

    expect(fn () => DB::table('invoice_lines')->insert([
        'tenant_id' => $this->beta->id, 'invoice_id' => $invoice->id, 'description' => 'x', 'amount_cents' => 1,
    ]))->toThrow(QueryException::class);
});
```

## 6. Unika constraints och upserts inom en tenant

```php
Invoice::query()->upsert(
    [['number' => 'A-1', 'total_cents' => 100], ['number' => 'A-2', 'total_cents' => 200]],
    uniqueBy: ['tenant_id', 'number'],            // the tenant column is stamped into every row for you
    update: ['total_cents'],
);
```

Den tenant-medvetna byggaren stämplar den aktiva tenanten i varje rad för
`insert()`, `insertOrIgnore()`, `insertGetId()` och `upsert()`, nekar ett
motstridigt värde och avvisar tenant-kolumnen i `update()`, `increment()`,
`decrement()` och uppdateringslistan i `upsert()`. Observera att själva
konfliktdetekteringen är databasens: det unika indexet måste inkludera
tenant-kolumnen, annars kan inte två tenanter båda ha en faktura `A-1`.

Inte skyddade: `insertUsing()` / `insertOrIgnoreUsing()` (subquery-inserts)
— behandla dem som rå SQL vid kodgranskning.

## 7. Jobb, kommandon, closures

```php
// Implicit: dispatched inside a request or Tenancy::run() → the tenant travels in the payload
GenerateStatement::dispatch($invoice);

// Explicit: the job knows its tenant
final class NightlyReconciliation implements ShouldQueue, TenantAware
{
    public function __construct(private readonly TenantId $tenantId) {}

    public function tenantId(): TenantId { return $this->tenantId; }

    public function middleware(): array { return [new TenantContextMiddleware]; }

    public function handle(): void { Invoice::query()->where('status', 'open')->each(…); }
}

// Code
Tenancy::run($tenantId, fn () => Invoice::count());

// CLI
php artisan tenant:run acme "invoices:remind --dry-run"
```

Ett schemalagt kommando som måste köras för varje tenant loopar explicit:

```php
Tenancy::withoutTenant('nightly reminders for all tenants', function (): void {
    Tenant::query()->where('status', 'active')->each(function (Tenant $tenant): void {
        Tenancy::run($tenant->tenantId(), fn () => InvoiceReminder::send());
    });
});
```

**Säkerhetseffekt.** Ett jobb utan tenant tillämpar fail closed (nekar
hellre än släpper igenom) i samma ögonblick det rör vid tenant-bunden data.
En `withoutTenant()`-bypass propageras aldrig in i jobb. Skälet och den
anropande koden för varje bypass loggas i audit-loggen.

**Felkonfiguration.** `tenant.queue.propagate = false` utan att göra jobben
`TenantAware`; att dispatcha från `withoutTenant()` och förvänta sig att
jobbet ärver bypassen (det gör det inte — med avsikt).

**Ditt test.** Dispatcha ett jobb inuti `Tenancy::run()` med `sync`-drivrutinen
och assert:a att det bara såg den tenantens rader.

## 8. Kontrollerat underhåll över tenantgränser

```php
$total = Tenancy::withoutTenant('monthly revenue report for finance', function (): int {
    return Invoice::query()->where('status', 'paid')->sum('total_cents');   // all tenants
});

Tenancy::withoutTenant('GDPR export for support ticket 4711', function () use ($tenantId): void {
    Invoice::forTenant($tenantId)->get();                                   // explicit even inside a bypass
});
```

**Säkerhetseffekt.** Skälet är obligatoriskt och hamnar, tillsammans med
filen och raden hos anroparen, i audit-loggen (`tenant.scope_bypassed`, på
tenantens kedja eller den globala). Det är det spåret en revisor efterfrågar.

**Felkonfiguration.** `withoutGlobalScope(TenantScope::class)` — samma
effekt men utan revisionsspåret; sök (grep) efter det vid kodgranskning.
Tomma skäl nekas.

## 9. Sanctum-tokens bundna till en tenant

Se [guiden om Sanctum-tokens](sanctum-tokens.md).

## 10. Medlemskapets livscykel

```php
$user->grantTenantMembership($tenantId, 'member', expiresAt: now()->addDays(30));   // invite that expires
$user->changeTenantMembershipStatus($tenantId, MembershipStatus::Suspended);
$user->revokeTenantMembership($tenantId);
```

Varje anrop skickar `MembershipChanged` (loggas i audit-loggen som
`tenant.membership.granted`, `…role_changed`, `…status_changed`,
`…revoked`). Ett avstängt eller utgånget medlemskap nekas av `tenant.member`
med ett specifikt meddelande; den cachade tenant-posten påverkas inte
(medlemskap cachas inte).

## Vad tenant-paketet medvetet inte gör

- filtrera `DB::table()`, rå SQL, views eller stored procedures;
- auktorisera åtgärder *inom* en tenant (använd policies);
- stödja att flytta poster mellan tenanter (`TenantColumnImmutable`);
- skydda `insertUsing()`;
- cacha medlemskap (varje request kontrollerar tabellen; migreringen
  indexerar `(tenant_id, user_id)` unikt samt `(user_id, status)`).

Läs [Vad det inte gör](../what-it-does-not-do.md) innan du förlitar dig på
något av detta.
