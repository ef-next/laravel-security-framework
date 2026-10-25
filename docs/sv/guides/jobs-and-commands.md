<!-- source: docs/en/guides/jobs-and-commands.md sha256: dedddd8ebe10356db3e7848338806b72d1e1643651f945a3bedb10e821a33223 -->
# Jobb och kommandon

*Svensk utgåva — den [engelska versionen](../../en/guides/jobs-and-commands.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Hur tenant-kontexten färdas in i köade jobb, schemalagda kommandon och
closures — och hur den medvetet inte gör det.

## Implicit propagering (standard)

```php
// In a request with an active tenant, or inside Tenancy::run():
GenerateStatement::dispatch($invoice);
```

Ramverket registrerar en payload-hook: varje jobb som dispatchas medan en
tenant är aktiv bär `{"efnext_tenant": {"id": "…"}}` i sin payload. När en
worker plockar upp jobbet aktiveras tenanten innan `handle()` körs, och
workerns föregående tillstånd återställs efteråt (relevant för
`sync`-drivrutinen och för Octane-workers). Inuti jobbet är tenant-bundna
modeller scopade precis som i requesten som dispatchade det.

Vad som **inte** propageras: en `withoutTenant()`-bypass. Ett jobb som
dispatchas inuti ett tenant-överskridande block körs utan tenant och
tillämpar fail closed (nekar hellre än släpper igenom) så fort det rör vid
tenant-bunden data — med avsikt, så att ett underhållsskript inte av misstag
kan starta oskopat bakgrundsarbete.

Konfiguration: `tenant.queue.propagate` (standard `true`). Med `false` bär
inget jobb en tenant; varje jobb behöver då den explicita formen nedan.

## Explicit: jobbet känner till sin tenant

```php
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Queue\TenantAware;
use Efnext\Security\Tenant\Queue\TenantContextMiddleware;

final class NightlyReconciliation implements ShouldQueue, TenantAware
{
    use Queueable;

    public function __construct(private readonly TenantId $tenantId) {}

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function middleware(): array
    {
        return [new TenantContextMiddleware];   // activates tenantId() around handle(); wins over the payload
    }

    public function handle(): void
    {
        Invoice::query()->where('status', 'open')->chunkById(500, fn ($invoices) => …);
    }
}
```

Använd detta för jobb som dispatchas från tenant-lösa platser
(schemaläggare, webhooks, andra jobb) eller som aldrig får bero på
dispatcherns kontext. `TenantContextMiddleware` på ett jobb som inte
implementerar `TenantAware` kastar en `LogicException` — misstaget fångas
redan vid första testkörningen.

## Konsolkommandon

```bash
php artisan tenant:run acme "invoices:remind --dry-run"   # tenant by id, ten_… public id, global object id or slug
php artisan tenant:run acme "queue:work --once"           # even the worker itself, for debugging
```

En avstängd eller arkiverad tenant nekas om inte `--allow-inactive` anges;
exitkoden är det inre kommandots.

Inuti ditt eget kommando:

```php
public function handle(): int
{
    return Tenancy::run($this->argument('tenant'), function (): int {
        // scoped
        return self::SUCCESS;
    });
}
```

## Schemalagt arbete för varje tenant

```php
// routes/console.php
Schedule::call(function (): void {
    Tenancy::withoutTenant('nightly invoice reminders for all active tenants', function (): void {
        Tenant::query()->where('status', 'active')->each(function (Tenant $tenant): void {
            SendInvoiceReminders::dispatch($tenant->tenantId());   // TenantAware job → explicit tenant, not the bypass
        });
    });
})->dailyAt('06:00')->onOneServer();
```

Loopen över tenanter är en tenant-överskridande operation och loggas som en
sådan i audit-loggen (`tenant.scope_bypassed` med skälet och anropande
fil/rad på den globala kedjan). Varje dispatchat jobb körs sedan inuti
exakt en tenant.

## Closures och tester

```php
Tenancy::run($tenantId, fn () => Invoice::count());          // previous context restored afterwards

// Pest
beforeEach(function (): void {
    $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
});

it('counts only the tenant\'s invoices', function (): void {
    Tenancy::run($this->acme->tenantId(), fn () => Invoice::factory()->count(3)->create());
    Tenancy::run($this->acme->tenantId(), fn () => expect(Invoice::count())->toBe(3));
});
```

Factories fungerar inuti `Tenancy::run()` eftersom `creating` stämplar
tenanten. Ett test som rör vid en tenant-bunden modell *utanför* varje
kontext misslyckas med `TenantContextMissing` — det är testet som talar om
för dig att produktionskodvägen har samma hål.

## Symptom

| Symptom | Orsak | Åtgärd |
| --- | --- | --- |
| `TenantContextMissing` inuti ett jobb | dispatchat utan kontext, eller från ett `withoutTenant()`-block, eller `propagate` är avstängt | gör jobbet `TenantAware`, eller dispatcha inuti `Tenancy::run()` |
| ett jobb behandlade data för *fel* tenant | det dispatchades inuti `Tenancy::run($other, …)` | kontrollera dispatch-stället; överväg `TenantAware` för jobb som ändå bär sin tenant i konstruktorn |
| `LogicException: … must implement TenantAware to use TenantContextMiddleware` | middleware på ett vanligt jobb | implementera interfacet |
| sync-drivrutinen ”läcker” en tenant in i nästa test | ingen — det föregående tillståndet återställs efter varje jobb; kontrollera dina egna anrop till `Tenancy::activate()` | använd `Tenancy::run()` i tester |
