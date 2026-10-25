<!-- source: docs/en/guides/tenancy.md sha256: 542df3db92a854eb17cea5a71badee983d4b5324fc59ba1dbbba5382cddb7561 -->
# Tenancy-Leitfaden

*Deutsche Ausgabe — die [englische Fassung](../../en/guides/tenancy.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Rezepte für `efnext/security-tenant`. Jedes hat dieselbe Form: den
Anwendungsfall, den Code, was er für die Sicherheit bewirkt, die typische
Fehlkonfiguration und den Test, der in *Ihrer* Anwendung liegen sollte. Das
gedankliche Modell steht in [Konzepte](../concepts.md), die API in
[reference/api.md](../reference/api.md).

## 1. Den Tenant aus einem Header, einem Routenparameter, der Session oder einer Subdomain ermitteln

**Anwendungsfall.** APIs senden `X-Tenant-Id`; eine Webanwendung hält den
Tenant in der Session; ein Produkt im Marketing-Stil verwendet
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

Der Identifier kann die UUID des Tenants sein, seine Public ID (`ten_…`),
seine Global Object ID (`billing:tenant:ten_…` — nur für diesen Dienst) oder
sein Slug; jede Form wird validiert, bevor eine Abfrage läuft, und unbekannte
Identifier werden kurz zwischengespeichert, sodass Scannen für Sie billig und
für den Scanner nutzlos ist.

**Sicherheitswirkung.** Die Resolution gewährt nie irgendetwas; sie wählt nur
den Tenant aus. `tenant.member` (Teil der Gruppe `tenant`) verlangt danach
eine aktive, nicht abgelaufene Mitgliedschaft. Fehlender Identifier → 400,
unbekannt → 404, gesperrt → 403, kein Mitglied → 403 plus ein Audit-Ereignis.

**Fehlkonfiguration.** `subdomain` aufzuführen, ohne `base_domains` zu setzen
(dann greift es nie); `tenant.resolve` auf eine Route zu legen und
`tenant.member` zu vergessen (der Tenant wird ausgewählt, die Mitgliedschaft
aber nicht geprüft — verwenden Sie die Gruppe `tenant`, sofern Sie keinen
Grund haben, es anders zu tun).

**Ihr Test.**

```php
it('selects the tenant from the header and refuses non-members', function (): void {
    $this->actingAs($this->alice)->getJson('/api/invoices', ['X-Tenant-Id' => 'acme'])->assertOk();
    $this->actingAs($this->alice)->getJson('/api/invoices', ['X-Tenant-Id' => 'beta'])->assertForbidden();
    $this->actingAs($this->alice)->getJson('/api/invoices')->assertStatus(400);
});
```

## 2. Rollen

**Anwendungsfall.** Owner verwalten die Abrechnung, Admins verwalten
Benutzer, Members lesen.

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

**Sicherheitswirkung.** Eine Rolle außerhalb des konfigurierten Vokabulars
kann weder vergeben werden noch eine Prüfung erfüllen. Verweigerungen lösen
`TenantAccessDenied` mit den erforderlichen Rollen aus, was das Audit-Paket
aufzeichnet.

**Fehlkonfiguration.** `hierarchical => false`, während Routen nur die
niedrigste Rolle benennen: Owner erhalten 403. Ein `default_role` mit
Privilegien: Jede Einladung wird zu einem Admin.

**Ihr Test.** Eine Anfrage pro geschützter Route mit einem `viewer`, die 403
erwartet.

## 3. Den Tenant in der Oberfläche wechseln

**Anwendungsfall.** Ein Benutzer gehört zu mehreren Tenants und wählt einen
davon in einem Menü aus.

```php
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Http\SessionTenant;

Route::post('/switch-tenant', function (Request $request, SessionTenant $session) {
    $session->switchTo($request, TenantId::fromString((string) $request->string('tenant')));   // 403 unless the user is an active member

    return redirect('/dashboard');
})->middleware('auth');
```

Mit `session` in der Resolution-Chain löst jede folgende Anfrage den
gewählten Tenant auf. `$session->current($request)` liest ihn aus,
`$session->forget($request)` löscht ihn.

**Sicherheitswirkung.** `switchTo()` prüft die Mitgliedschaft genau wie die
Middleware und löst `TenantSwitched` aus (aufgezeichnet als
`tenant.switched` mit dem vorherigen Tenant). Ein Benutzer kann nicht in
einen Tenant „wechseln“, zu dem er nicht gehört.

**Fehlkonfiguration.** Den Session-Schlüssel selbst zu schreiben, statt
`switchTo()` aufzurufen — das überspringt die Mitgliedschaftsprüfung und das
Audit-Ereignis.

## 4. Tenant-gebundene Models, benutzerdefinierte Spaltennamen

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

Alles andere bleibt unverändert: Scope, das Stempeln beim Erzeugen,
Eigentümerprüfungen, geschütztes Mass Assignment. Führen Sie die
Tenant-Spalte nicht in `$fillable` auf; falls Sie es müssen (Import-Tooling),
verweigert das Framework trotzdem einen Wert, der vom aktiven Tenant
abweicht (`TenantMismatch`).

**Ihr Test.** `expect(fn () => Project::query()->count())->toThrow(TenantContextMissing::class);`

## 5. Beziehungen zwischen tenant-gebundenen Tabellen

```php
Schema::create('invoice_lines', function (Blueprint $table): void {
    $table->id();
    $table->tenantId();
    $table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete();                    // required
    $table->tenantForeignUuid('approved_by_id', 'approvers', nullable: true)->restrictOnDelete();   // optional
});
```

**Sicherheitswirkung.** Der zusammengesetzte Schlüssel `(tenant_id,
invoice_id) → invoices(tenant_id, id)` lässt die Datenbank eine Position
ablehnen, die auf die Rechnung eines anderen Tenants verweist — gleichermaßen
über Eloquent, `DB::table()`, rohes SQL oder ein Migrationsskript.

**Fehlkonfiguration.** `foreignId('invoice_id')->constrained()` (ein
einfacher Schlüssel: Die Datenbank prüft den Tenant nicht mehr);
`nullOnDelete()` auf einem zusammengesetzten Schlüssel (wird schon beim
Schreiben der Migration verweigert — die Tenant-Spalte kann nicht geleert
werden; lösen Sie die Verknüpfung per Update). Eindeutigkeit, die pro Tenant
gelten soll, muss die Tenant-Spalte einschließen:
`$table->unique(['tenant_id', 'number'])`.

**Ihr Test.**

```php
it('refuses a line that points at another tenant\'s invoice, even through raw SQL', function (): void {
    $invoice = Tenancy::run($this->acme->tenantId(), fn () => Invoice::create(['number' => 'A-1']));

    expect(fn () => DB::table('invoice_lines')->insert([
        'tenant_id' => $this->beta->id, 'invoice_id' => $invoice->id, 'description' => 'x', 'amount_cents' => 1,
    ]))->toThrow(QueryException::class);
});
```

## 6. Eindeutigkeitsbedingungen und Upserts innerhalb eines Tenants

```php
Invoice::query()->upsert(
    [['number' => 'A-1', 'total_cents' => 100], ['number' => 'A-2', 'total_cents' => 200]],
    uniqueBy: ['tenant_id', 'number'],            // the tenant column is stamped into every row for you
    update: ['total_cents'],
);
```

Der tenant-fähige Query-Builder stempelt den aktiven Tenant in jede Zeile von
`insert()`, `insertOrIgnore()`, `insertGetId()` und `upsert()`, verweigert
einen widersprüchlichen Wert und lehnt die Tenant-Spalte in `update()`,
`increment()`, `decrement()` und der Update-Liste von `upsert()` ab. Beachten
Sie, dass die Konflikterkennung selbst Sache der Datenbank ist: Der
Unique-Index muss die Tenant-Spalte einschließen, sonst können nicht zwei
Tenants jeweils eine Rechnung `A-1` haben.

Nicht geschützt: `insertUsing()` / `insertOrIgnoreUsing()` (Inserts per
Unterabfrage) — behandeln Sie sie im Code-Review wie rohes SQL.

## 7. Jobs, Befehle, Closures

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

Ein geplanter Befehl, der für jeden Tenant laufen muss, iteriert explizit:

```php
Tenancy::withoutTenant('nightly reminders for all tenants', function (): void {
    Tenant::query()->where('status', 'active')->each(function (Tenant $tenant): void {
        Tenancy::run($tenant->tenantId(), fn () => InvoiceReminder::send());
    });
});
```

**Sicherheitswirkung.** Ein Job ohne Tenant schlägt fail closed fehl, sobald
er tenant-gebundene Daten berührt. Eine `withoutTenant()`-Umgehung wird nie
in Jobs weitergereicht. Der Grund und die aufrufende Codestelle jeder
Umgehung werden auditiert.

**Fehlkonfiguration.** `tenant.queue.propagate = false`, ohne Jobs
`TenantAware` zu machen; aus `withoutTenant()` heraus zu versenden und zu
erwarten, dass der Job die Umgehung erbt (das tut er nicht — das ist
beabsichtigt).

**Ihr Test.** Versenden Sie einen Job innerhalb von `Tenancy::run()` mit dem
`sync`-Treiber und prüfen Sie, dass er nur die Zeilen dieses Tenants gesehen
hat.

## 8. Kontrollierte tenantübergreifende Wartung

```php
$total = Tenancy::withoutTenant('monthly revenue report for finance', function (): int {
    return Invoice::query()->where('status', 'paid')->sum('total_cents');   // all tenants
});

Tenancy::withoutTenant('GDPR export for support ticket 4711', function () use ($tenantId): void {
    Invoice::forTenant($tenantId)->get();                                   // explicit even inside a bypass
});
```

**Sicherheitswirkung.** Der Grund ist Pflicht und landet zusammen mit Datei
und Zeile des Aufrufers im Audit-Log (`tenant.scope_bypassed`, auf der Kette
des Tenants oder der globalen). Das ist die Spur, nach der ein Prüfer fragt.

**Fehlkonfiguration.** `withoutGlobalScope(TenantScope::class)` — derselbe
Effekt ohne Audit-Trail; greppen Sie im Code-Review danach. Leere Gründe
werden verweigert.

## 9. An einen Tenant gebundene Sanctum-Tokens

Siehe den [Sanctum-Tokens-Leitfaden](sanctum-tokens.md).

## 10. Lebenszyklus der Mitgliedschaft

```php
$user->grantTenantMembership($tenantId, 'member', expiresAt: now()->addDays(30));   // invite that expires
$user->changeTenantMembershipStatus($tenantId, MembershipStatus::Suspended);
$user->revokeTenantMembership($tenantId);
```

Jeder Aufruf löst `MembershipChanged` aus (aufgezeichnet als
`tenant.membership.granted`, `…role_changed`, `…status_changed`,
`…revoked`). Eine gesperrte oder abgelaufene Mitgliedschaft wird von
`tenant.member` mit einer spezifischen Meldung verweigert; der
zwischengespeicherte Tenant-Datensatz bleibt davon unberührt (Mitgliedschaften
werden nicht zwischengespeichert).

## Was das Tenant-Paket bewusst nicht tut

- `DB::table()`, rohes SQL, Views oder Stored Procedures filtern;
- Aktionen *innerhalb* eines Tenants autorisieren (verwenden Sie Policies);
- das Verschieben von Datensätzen zwischen Tenants unterstützen
  (`TenantColumnImmutable`);
- `insertUsing()` schützen;
- Mitgliedschaften zwischenspeichern (jede Anfrage prüft die Tabelle; die
  Migration indiziert `(tenant_id, user_id)` eindeutig und `(user_id,
  status)`).

Lesen Sie [Was es nicht tut](../what-it-does-not-do.md), bevor Sie sich auf
irgendetwas davon verlassen.
