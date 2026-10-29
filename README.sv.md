<!-- source: README.md sha256: 967138b80bf68118f8ca5cb4a1147895e6cd518103fe26312ed24a3af51120ea -->
# ef.next Security Framework

*Svensk utgåva — den [engelska versionen](README.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Fail closed tenant-isolering (nekar hellre än släpper igenom), en manipulationsdetekterande audit-logg och säkra publika identifierare för Laravel 13 på PHP 8.3, 8.4 och 8.5 (se [Kompatibilitet](#kompatibilitet)).

**Status: förhandsversion.** Den aktuella versionslinjen är `1.0.0-beta` (se [CHANGELOG](CHANGELOG.md)); API:et kan fortfarande ändras före `1.0.0`, och varje ändring står där. Releaser är signerade taggar med SBOM:er ([Releasing](docs/maintainers/releasing.md)).

**Webbplats:** [security-framework.ef-next.com](https://security-framework.ef-next.com/) (på engelska) — vad ramverket är till för, kopplingen till Cyber Resilience Act, nyheter och kontakt. Koden och den fullständiga dokumentationen finns i det här repot.

| Paket | Vad det gör |
| --- | --- |
| `efnext/security-tenant` | Fastställer tenanten för en request, ett jobb eller ett kommando; nekar varje fråga mot tenant-bundna modeller utan en tenant; upprätthåller medlemskap och roller; för tenanten vidare genom köer. |
| `efnext/security-audit` | Lägger till händelser i hashkedjor per tenant med sekvensnummer, databastriggrar mot UPDATE/DELETE, HMAC-signaturer med nycklar utanför databasen, externa ankare och en verifierare. |
| `efnext/security-addressing` | Ogissningsbara, läsbara publika id:n (`dom_7f3k9zq2m4x8c1bt`) och globala objekt-id:n (`example-service:domain:dom_…`) för modeller. |

En distribution, tre komponenter: paketet `efnext/security-framework` innehåller alla tre (komponentnamnen är reserverade via `replace` och blir separat installerbara när monorepot delas upp inför en stabil release). Composers standardstabilitet är `stable`, så en förhandsversion måste begäras uttryckligen:

```bash
composer require "efnext/security-framework:^1.0@beta"
php artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config
php artisan migrate
php artisan audit:key:generate   # put the output into .env
php artisan security:doctor      # would this installation keep the guarantees below? exit 1 if not
```

Läs [Vad det inte gör](docs/sv/what-it-does-not-do.md) innan du förlitar dig på något av detta.

## Integration på tio minuter

Alla tre paketen i en genomgång; varje steg är komplett i sig.

**1. Tenants och användare**

```php
use Efnext\Security\Tenant\Membership\HasTenantMemberships;

class User extends Authenticatable
{
    use HasTenantMemberships;
}

$tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
$user->grantTenantMembership($tenant->tenantId(), 'owner');
```

**2. Tenant-bundna modeller**

```php
use Efnext\Security\Tenant\Eloquent\BelongsToTenant;

class Invoice extends Model
{
    use BelongsToTenant;   // every query is scoped; no tenant -> exception
}

// migration
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->tenantId();    // uuid column + FK to tenants + index + UNIQUE(tenant_id, id)
    // ...
});

Schema::create('invoice_lines', function (Blueprint $table) {
    $table->id();
    $table->tenantId();
    $table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete();   // FK (tenant_id, invoice_id) -> invoices(tenant_id, id)
});
```

Den sammansatta främmande nyckeln är försvar på djupet under Eloquent: inte ens rå SQL kan koppla en rad till en annan tenants faktura. Valfria relationer använder `nullable: true` med `cascadeOnDelete()` eller `restrictOnDelete()`; `nullOnDelete()` nekas vid migrationstillfället eftersom en sammansatt nyckel inte kan sätta bara en av sina kolumner till NULL (MySQL/MariaDB skulle avvisa constraintet, PostgreSQL raderingen).

**3. Publika id:n (Addressing)**

```php
use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;
use Efnext\Security\Addressing\Eloquent\HasPublicId;

class Invoice extends Model implements ExternallyAddressable
{
    use BelongsToTenant;
    use HasPublicId;          // inv_7f3k9zq2m4x8c1bt, assigned on create, immutable, used for route binding
    use HasGlobalObjectId;    // billing:invoice:inv_7f3k9zq2m4x8c1bt (SECURITY_SERVICE_NAME=billing)

    public static function publicIdPrefix(): string { return 'inv'; }
    public static function resourceType(): string { return 'invoice'; }
}

// migration: add to the table from step 2
$table->publicId();          // unique, indexed column (default name: public_id)
```

`{invoice}` i en route binds nu via det publika id:t (ett okänt eller felaktigt bildat id ger 404, aldrig en exception), `Invoice::query()->wherePublicId($id)` och `->whereGlobalObjectId($id)` slår upp en, `$invoice->publicId()` / `->globalObjectId()` lämnar ut dem. Använd det publika id:t i URL:er, API:er och loggar; använd det globala objekt-id:t när en annan tjänst eller ett revisionsspår entydigt måste namnge objektet. Publika id:n stoppar enumerering, de är inte auktorisering: tenant-middlewaren och dina policyer avgör fortfarande vem som får se fakturan. Bulk-`insert()` kringgår Eloquent-events och tilldelar därför inget id (kolumnen är `NOT NULL`, så databasen avvisar raden); skapa via modellen.

**4. Routes**

```php
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index']);      // Invoice::all() is scoped
    Route::delete('/invoices/{invoice}', ...)->middleware('tenant.role:admin');  // {invoice} is bound after the tenant is resolved
});
```

Tenanten kommer från `X-Tenant-Id` (internt id, `ten_…`-publikt id, globalt objekt-id eller slug), en `{tenant}`-routeparameter, sessionen eller en subdomän, i den konfigurerade ordningen. Okänd tenant: 404. Avstängd tenant: 403. Inget medlemskap: 403 plus en audit-händelse. Tenant-middlewaren registrerar sig själv i Laravels prioritetslista för middleware direkt före `SubstituteBindings`, så implicit route model binding av tenant-bundna modeller fungerar inom grupperna `web` och `api` oavsett i vilken ordning routen deklarerar dem.

**5. Audit**

```php
use Efnext\Security\Audit\Facades\Audit;

Audit::action('domain.verified')->on($domain)->with(['method' => 'dns'])->record();
Audit::action('auth.login_failed')->on('user', $email)->denied()->record();
```

Aktör, tenant, IP, user agent, request-id och korrelations-id fylls i automatiskt och är del av hashen. Kör `php artisan audit:verify` och `php artisan audit:anchor` från schemaläggaren.

```php
// Business change and audit record commit together; conflicts on the tenant's
// chain (deadlocks, serialization failures, MariaDB snapshot-isolation errors)
// are retried with back-off. Prefer this over DB::transaction() whenever a
// transaction contains an audit write.
Audit::transaction(function () use ($invoice) {
    $invoice->markPaid();
    Audit::action('invoice.paid')->on($invoice)->record();
});
```

Closuren kan köras mer än en gång: håll e-post, API-anrop och andra sidoeffekter utanför den (lägg dem i kö efter att den återvänt, eller gör dem idempotenta).

**6. Drift**

```bash
php artisan security:doctor            # after every deployment and from monitoring (--json; --strict = deployment gate of the high-security profile)
php artisan audit:anchor               # scheduler, every few minutes
php artisan audit:verify               # scheduler, at least daily
```

I `APP_ENV=production` vägrar audit-providern starta utan signeringsnyckel, på SQLite, med append-only-tillämpning avstängd eller utan ett läsbart ankarlager (`AUDIT_FAIL_FAST=false` är det medvetna, tillfälliga undantaget). Sätt `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` så snart din Object Lock-skyddade bucket finns på plats: produktionen vägrar då också starta med enbart databasankare, och doctorn rapporterar dem som ett fel i stället för en varning.

**7. Jobb och kommandon**

```php
ProcessInvoices::dispatch();                        // tenant travels with the job automatically
Tenancy::run($tenantId, fn () => Invoice::count()); // explicit context in code
```

```bash
php artisan tenant:run acme reports:generate        # explicit context on the CLI
```

## Vart härnäst

| Du vill … | Läs |
| --- | --- |
| förstå vad ramverket lovar och inte lovar | [Vad det inte gör](docs/sv/what-it-does-not-do.md), [Hotmodell](docs/sv/threat-model.md), [Koncept](docs/sv/concepts.md) |
| integrera det i en applikation | [Kom igång](docs/sv/getting-started.md) (en komplett första integration med exempelutdata), sedan guiderna: [Tenancy](docs/sv/guides/tenancy.md), [Addressing](docs/sv/guides/addressing.md), [Auditing](docs/sv/guides/auditing.md), [Jobb och kommandon](docs/sv/guides/jobs-and-commands.md), [Sanctum-tokens](docs/sv/guides/sanctum-tokens.md); [checklistan inför driftsättning](docs/sv/integration-checklist.md) före go-live |
| se allt i en fungerande applikation | [examples/laravel-app](examples/laravel-app/README.md) — en standard-Laravel-13-app plus femton filer, installerad och testad i CI vid varje push |
| slå upp något | [Konfiguration](docs/sv/reference/configuration.md) (varje nyckel och miljövariabel), [Artisan-kommandon](docs/sv/reference/artisan-commands.md), [Events](docs/sv/reference/events.md), [Exceptions](docs/sv/reference/exceptions.md), [Publikt API](docs/sv/reference/api.md), [Kompatibilitet](docs/sv/reference/compatibility.md) |
| köra det i produktion | [Produktionsguide](docs/sv/guides/production.md) (hemligheter, databas, ankare på objektlagring, deployment-gate), [Drift](docs/sv/operations.md) och dess driftshandböcker: [Övervakning](docs/sv/operations/monitoring.md), [Nyckelrotation](docs/sv/operations/key-rotation.md), [Incidenthantering](docs/sv/operations/incident-response.md); `.env.example` listar varje variabel |
| felsöka ett problem | `php artisan security:doctor` (`--json`, `--strict`), sedan [Felsökning](docs/sv/guides/troubleshooting.md) efter symptom |
| veta hur det fungerar invändigt | [Arkitektur](docs/sv/architecture.md) och diagrammen: [Systemöversikt](docs/sv/diagrams/system-overview.md), [Tenant-requestens livscykel](docs/sv/diagrams/tenant-request-lifecycle.md), [Audit-skrivväg och verifiering](docs/sv/diagrams/audit-write-and-verification.md), [Förtroendegränser](docs/sv/diagrams/trust-boundaries.md), [Datamodell](docs/sv/diagrams/data-model.md) |
| bidra eller göra en release | [CONTRIBUTING.md](CONTRIBUTING.md), [Testning](docs/maintainers/testing.md), [Releasing](docs/maintainers/releasing.md), [SUPPORT.md](SUPPORT.md) |
| låta en AI-agent integrera det | [AGENTS.md](AGENTS.md) — det kontrakt en agent måste hålla, och hur den verifierar sitt eget arbete; [llms.txt](llms.txt) indexerar dokumentationen för agenter |

Dokumentationen skrivs på engelska först ([README.md](README.md), `docs/en/`). Tyska ([README.de.md](README.de.md), `docs/de/`) och svenska (denna sida, `docs/sv/`) utgåvor av användar- och driftsidorna följer efter varje release; varje översatt sida namnger den engelska källan och den version den motsvarar.

## AI-kodagenter

Ramverket är tänkt att integreras av både människor och kodagenter. Det levererar reglerna som en agent måste följa i tre längder:

- **[Laravel Boost](https://laravel.com/docs/boost):** I en applikation med Boost listar `php artisan boost:install` (eller `boost:update`) `efnext/security-framework` bland tredjepartspaketen; när det är valt laddar Boost paketets riktlinje i agentens kontext – reglerna som upprätthåller garantierna, i [resources/boost/guidelines/core.blade.php](resources/boost/guidelines/core.blade.php) – och installerar skillen [`efnext-security-integration`](resources/boost/skills/efnext-security-integration/SKILL.md): integrationsförloppet och hur det verifieras, laddad när agenten behöver den.
- **[AGENTS.md](AGENTS.md):** det fullständiga kontraktet, för agenter utan Boost och för agenter som ändrar själva ramverket.
- **[llms.txt](llms.txt):** ett index över dokumentationen.

Nekanden och doctorns fynd anger hur de åtgärdas, `security:doctor --json` är maskinläsbar, och exitkoderna från `security:doctor --strict` och `audit:verify` visar en agent om dess arbete upprätthåller garantierna.

## Hur ramverket byggdes

Ramverket skrevs av en AI-kodagent, Claude från Anthropic, under ledning av maintainern Enrico Engel (ef.next): han bestämde arkitekturen och säkerhetsbesluten och godkände varje ändring. Det har gått igenom flera granskningsrundor; korrigeringarna de ledde till finns i [CHANGELOG](CHANGELOG.md).

Vi ber dig därför inte att lita på koden, utan att kontrollera den. Beläggen finns i detta repository och körs vid varje push:

- testsviten på SQLite, PostgreSQL, MySQL och MariaDB, inklusive samtidiga skrivare, med en lägsta täckningsgrad;
- mutationstestning av säkerhetskärnan som CI-grind;
- PHPStan på nivå 8, Pint och `composer audit`;
- [known-answer-vektorer](packages/audit/tests/Fixtures/hash-vectors.json) som räknar om audit-hashar och signaturer utan denna kodbas;
- signerade release-taggar, verifierade mot [trust root](.github/allowed_signers) i repositoryt, med SBOM:er och build provenance ([Releasing](docs/maintainers/releasing.md)).

Det som fortfarande saknas är en oberoende säkerhetsgranskning av tredje part. Tills den finns, behandla ramverket som den beta det är märkt som, och rapportera det du hittar enligt [SECURITY.md](SECURITY.md).

## Garantier och deras villkor

| Ramverket garanterar … | … under förutsättning att |
| --- | --- |
| Eloquent-frågor på modeller som använder `BelongsToTenant` returnerar aldrig en annan tenants rader och körs aldrig utan en tenant. | Du anropar inte `withoutGlobalScope(TenantScope::class)`, använder inte `DB::table()` för dessa tabeller och kör ingen rå SQL. Grepa efter detta i kodgranskning. |
| En post kan inte skapas för, flyttas till, uppdateras i eller raderas från en främmande tenant via Eloquent, även om modellinstansen laddades i ett annat sammanhang. | Samma som ovan. Mass assignment av tenant-kolumnen är blockerad om du inte själv lägger till den i `$fillable`. |
| En request når din controller endast om användaren är en aktiv, icke-utgången medlem av en existerande, aktiv tenant (och har den roll du bad om). | Routen har `auth` och gruppen `tenant` (eller `tenant.resolve` + `tenant.member`). |
| En audit-post kan inte ändras eller raderas via databasen utan att lämna ett spår. | Triggrar är installerade (migrationen vägrar databasmotorer som inte stöds), en signeringsnyckel är konfigurerad, och `audit:anchor` skriver till ett lager utanför databasen. Utan nyckeln kan en angripare med databasåtkomst skriva om en kedja konsekvent; utan externa ankare kan de trunkera den. `security:doctor` kontrollerar allt detta; `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` gör det externa lagret till ett startvillkor. |
| En barnrad kan bara referera till en förälder från samma tenant, oavsett vad som skrev den. | Barntabellen använder `$table->tenantForeignId()` / `tenantForeignUuid()` / `tenantForeignUlid()`, och föräldern skapades med `$table->tenantId()` (sammansatt nyckel). |
| Samtidiga skribenter förgrenar aldrig en kedja och hamnar inte i deadlock på den. | Audit-tabellerna ligger i en databas som respekterar `SELECT … FOR UPDATE` (PostgreSQL, MySQL/InnoDB, MariaDB). Affärstransaktioner som innehåller audit-skrivningar är inpackade i `Audit::transaction()` (försöker igen vid samtidighetskonflikter med backoff), och för helt nya tenants har `Audit::prepareChain()` körts en gång. |
| Publika id:n är ogissningsbara. | Du behåller standardentropin (80 bitar). De är fortfarande ingen auktoriseringsmekanism. |

## Kompatibilitet

| | Stöds – hårda CI-grindar |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 13 |
| Databaser | PostgreSQL 14–17, MySQL 8.0/8.4, MariaDB 10.11–11 (SQLite endast för tester) |

Varje kombination som stöds är en obligatorisk CI-grind, och
Composer-begränsningarna tillåter inget annat (endast Laravel 13; PHP över 8.5
går att installera men är otestat). Endast den senaste minor-releasen får rättningar
(`SECURITY.md`); release-processen finns i [docs/maintainers/releasing.md](docs/maintainers/releasing.md).

## Utveckling

Allt körs i Docker; värden behöver inget annat än Docker Desktop:

```bash
./start-security-framework.sh          # composer audit + selfcheck + Pint + PHPStan + tests on sqlite, pgsql, mysql, mariadb
./start-security-framework.sh mutate   # mutation testing on the security core
```

De övriga lägena (`test <db>`, `concurrency`, `matrix`, `load`, `shell`, `down`, `PHP_VERSION=`, `LARAVEL=`, `DEPS=`), de kvalitetsgrindar CI upprätthåller och reglerna för pull requests finns i [CONTRIBUTING.md](CONTRIBUTING.md) och [docs/maintainers/testing.md](docs/maintainers/testing.md).

### Att använda det från en Laravel Sail-produkt

Ramverkets repository är inte en Laravel-applikation, så det levererar sin egen
`compose.yaml` i stället för Sail; arbetsflödet är detsamma (inget annat än
Docker på värden). För att utveckla mot det från en Sail-baserad produkt, lägg
till ett path-repository och kräv dev-versionen:

```json
{
    "repositories": [
        {"type": "path", "url": "../security-framework", "options": {"symlink": true}}
    ]
}
```

```bash
sail composer require efnext/security-framework:@dev
sail artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config
sail artisan migrate
sail artisan audit:key:generate
```

Montera ramverkets katalog i Sail-containern (`volumes:` i din produkts
`compose.yaml`) så att symlänken kan lösas upp inuti Docker. Sails MySQL-tjänst
behöver `--log-bin-trust-function-creators=1` (eller en root-användare) för
audit-triggrarna; se [docs/sv/operations.md](docs/sv/operations.md).

## Dokumentation

- [Kom igång](docs/sv/getting-started.md) · [Koncept](docs/sv/concepts.md) · [Vad det inte gör](docs/sv/what-it-does-not-do.md)
- Guider: [Tenancy](docs/sv/guides/tenancy.md) · [Addressing](docs/sv/guides/addressing.md) · [Auditing](docs/sv/guides/auditing.md) · [Jobb och kommandon](docs/sv/guides/jobs-and-commands.md) · [Sanctum-tokens](docs/sv/guides/sanctum-tokens.md) · [Produktion](docs/sv/guides/production.md) · [Felsökning](docs/sv/guides/troubleshooting.md)
- Referens: [Konfiguration](docs/sv/reference/configuration.md) · [Artisan-kommandon](docs/sv/reference/artisan-commands.md) · [Events](docs/sv/reference/events.md) · [Exceptions](docs/sv/reference/exceptions.md) · [Publikt API](docs/sv/reference/api.md) · [Kompatibilitet](docs/sv/reference/compatibility.md)
- Drift: [Drift](docs/sv/operations.md) · [Övervakning](docs/sv/operations/monitoring.md) · [Nyckelrotation](docs/sv/operations/key-rotation.md) · [Incidenthantering](docs/sv/operations/incident-response.md)
- Design: [Arkitektur](docs/sv/architecture.md) · [Hotmodell](docs/sv/threat-model.md) · [Diagram](docs/sv/diagrams/system-overview.md) · [Checklista inför driftsättning](docs/sv/integration-checklist.md)
- [Exempelapplikation](examples/laravel-app/README.md) · [AGENTS.md](AGENTS.md) · [llms.txt](llms.txt)
- [Att bidra](CONTRIBUTING.md) · [Testning](docs/maintainers/testing.md) · [Releasing](docs/maintainers/releasing.md) · [Support](SUPPORT.md) · [Uppförandekod](CODE_OF_CONDUCT.md)
- [Säkerhetspolicy](SECURITY.md) · [Ändringslogg](CHANGELOG.md) · [Licens (MIT)](LICENSE)
