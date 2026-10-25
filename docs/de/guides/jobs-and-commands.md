<!-- source: docs/en/guides/jobs-and-commands.md sha256: dedddd8ebe10356db3e7848338806b72d1e1643651f945a3bedb10e821a33223 -->
# Jobs und Befehle

*Deutsche Ausgabe — die [englische Fassung](../../en/guides/jobs-and-commands.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Wie der Tenant-Kontext in Queue-Jobs, geplante Befehle und Closures gelangt —
und wie er es bewusst nicht tut.

## Implizite Weitergabe (Standard)

```php
// In a request with an active tenant, or inside Tenancy::run():
GenerateStatement::dispatch($invoice);
```

Das Framework registriert einen Payload-Hook: Jeder Job, der versendet wird,
während ein Tenant aktiv ist, trägt `{"efnext_tenant": {"id": "…"}}` in
seiner Payload. Wenn ein Worker den Job aufnimmt, wird der Tenant aktiviert,
bevor `handle()` läuft, und der vorherige Zustand des Workers wird danach
wiederhergestellt (relevant für den `sync`-Treiber und für Octane-Worker).
Innerhalb des Jobs sind tenant-gebundene Models so gescoped wie in der
Anfrage, die ihn versendet hat.

Was **nicht** weitergegeben wird: eine `withoutTenant()`-Umgehung. Ein Job,
der innerhalb eines tenantübergreifenden Blocks versendet wird, läuft ohne
Tenant und schlägt fail closed fehl, sobald er tenant-gebundene Daten
berührt — beabsichtigt, damit ein Wartungsskript nicht versehentlich
ungescopte Hintergrundarbeit erzeugen kann.

Konfiguration: `tenant.queue.propagate` (Standard `true`). Mit `false` trägt
kein Job einen Tenant; jeder Job braucht dann die explizite Form weiter
unten.

## Explizit: Der Job kennt seinen Tenant

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

Verwenden Sie dies für Jobs, die von tenantlosen Stellen aus versendet
werden (Scheduler, Webhooks, andere Jobs) oder die niemals vom Kontext des
Absenders abhängen dürfen. `TenantContextMiddleware` auf einem Job, der
`TenantAware` nicht implementiert, wirft eine `LogicException` — der Fehler
wird schon im ersten Testlauf abgefangen.

## Konsolenbefehle

```bash
php artisan tenant:run acme "invoices:remind --dry-run"   # tenant by id, ten_… public id, global object id or slug
php artisan tenant:run acme "queue:work --once"           # even the worker itself, for debugging
```

Ein gesperrter oder archivierter Tenant wird verweigert, sofern nicht
`--allow-inactive` angegeben ist; der Exit-Code ist der des inneren Befehls.

Innerhalb Ihres eigenen Befehls:

```php
public function handle(): int
{
    return Tenancy::run($this->argument('tenant'), function (): int {
        // scoped
        return self::SUCCESS;
    });
}
```

## Geplante Arbeit für jeden Tenant

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

Die Schleife über die Tenants ist eine tenantübergreifende Operation und
wird als solche auditiert (`tenant.scope_bypassed` mit dem Grund und der
aufrufenden Datei/Zeile auf der globalen Kette). Jeder versendete Job läuft
dann innerhalb genau eines Tenants.

## Closures und Tests

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

Factories funktionieren innerhalb von `Tenancy::run()`, weil `creating` den
Tenant stempelt. Ein Test, der ein tenant-gebundenes Model *außerhalb* jedes
Kontexts berührt, schlägt mit `TenantContextMissing` fehl — das ist der
Test, der Ihnen sagt, dass der Produktionscodepfad dasselbe Loch hat.

## Symptome

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| `TenantContextMissing` in einem Job | ohne Kontext versendet, oder aus einem `withoutTenant()`-Block, oder `propagate` ist aus | den Job `TenantAware` machen, oder innerhalb von `Tenancy::run()` versenden |
| ein Job hat Daten des *falschen* Tenants verarbeitet | er wurde innerhalb von `Tenancy::run($other, …)` versendet | prüfen Sie die Versandstelle; erwägen Sie `TenantAware` für Jobs, die ihren Tenant ohnehin im Konstruktor tragen |
| `LogicException: … must implement TenantAware to use TenantContextMiddleware` | Middleware auf einem einfachen Job | das Interface implementieren |
| der `sync`-Treiber „leakt“ einen Tenant in den nächsten Test | keine — der vorherige Zustand wird nach jedem Job wiederhergestellt; prüfen Sie auf eigene `Tenancy::activate()`-Aufrufe | in Tests `Tenancy::run()` verwenden |
