<!-- source: README.md sha256: abf5679b95ed94a208633d5ce21be0e415d12e43aa5446ea0ecc62912774402a -->
# ef.next Security Framework

*Deutsche Ausgabe — die [englische Fassung](README.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Tenant-Isolation (Mandantentrennung) nach dem Prinzip fail closed (im Zweifel verweigern), ein manipulationssicher nachweisbares Audit-Log (Manipulationen werden erkannt) und sichere öffentliche Bezeichner für Laravel 12 auf PHP 8.3/8.4 (Laravel 13 und PHP 8.5: experimentell, siehe [Kompatibilität](#kompatibilität)).

**Status: Pre-Release.** Die aktuelle Versionslinie ist `1.0.0-beta` (siehe [CHANGELOG](CHANGELOG.md)); die API kann sich vor `1.0.0` noch ändern, und jede Änderung steht dort. Releases sind signierte Tags mit SBOMs ([Releasing](docs/maintainers/releasing.md)).

| Paket | Was es tut |
| --- | --- |
| `efnext/security-tenant` | Ermittelt den Tenant (Mandanten) einer Anfrage, eines Jobs oder eines Befehls; verweigert jede Abfrage auf tenant-gebundenen Models ohne Tenant; setzt Mitgliedschaften und Rollen durch; reicht den Tenant durch Queues weiter. |
| `efnext/security-audit` | Fügt Ereignisse an Hash-Ketten pro Tenant an, mit Sequenznummern, Datenbank-Triggern gegen UPDATE/DELETE, HMAC-Signaturen mit Schlüsseln außerhalb der Datenbank, externen Ankern und einem Verifier. |
| `efnext/security-addressing` | Nicht erratbare, lesbare Public IDs (`dom_7f3k9zq2m4x8c1bt`) und Global Object IDs (`example-service:domain:dom_…`) für Models. |

Eine Distribution, drei Komponenten: das Paket `efnext/security-framework` enthält alle drei (die Komponentennamen sind über `replace` reserviert und werden separat installierbar, sobald das Monorepo für ein stabiles Release aufgeteilt wird). Composers Standard-Stabilität ist `stable`, ein Pre-Release muss also ausdrücklich angefordert werden:

```bash
# until the package is on Packagist: point Composer at the repository once
composer config repositories.efnext vcs https://github.com/ef-next/laravel-security-framework

composer require "efnext/security-framework:^1.0@beta"
php artisan vendor:publish --tag=security-tenant-config --tag=security-audit-config --tag=security-addressing-config
php artisan migrate
php artisan audit:key:generate   # put the output into .env
php artisan security:doctor      # would this installation keep the guarantees below? exit 1 if not
```

Lesen Sie [Was es nicht tut](docs/de/what-it-does-not-do.md), bevor Sie sich auf irgendetwas davon verlassen.

## Integration in zehn Minuten

Alle drei Pakete in einem Durchgang; jeder Schritt ist für sich vollständig.

**1. Tenants und Benutzer**

```php
use Efnext\Security\Tenant\Membership\HasTenantMemberships;

class User extends Authenticatable
{
    use HasTenantMemberships;
}

$tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
$user->grantTenantMembership($tenant->tenantId(), 'owner');
```

**2. Tenant-gebundene Models**

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

Der zusammengesetzte Fremdschlüssel ist gestaffelte Verteidigung unterhalb von Eloquent: Selbst rohes SQL kann eine Position nicht an die Rechnung eines anderen Tenants hängen. Optionale Relationen verwenden `nullable: true` mit `cascadeOnDelete()` oder `restrictOnDelete()`; `nullOnDelete()` wird zur Migrationszeit verweigert, weil ein zusammengesetzter Schlüssel nicht nur eine seiner Spalten auf NULL setzen kann (MySQL/MariaDB würden den Constraint ablehnen, PostgreSQL das Löschen).

**3. Public IDs (Addressing)**

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

`{invoice}` in einer Route wird jetzt über die Public ID gebunden (eine unbekannte oder fehlerhaft gebildete ID ist ein 404, nie eine Exception), `Invoice::query()->wherePublicId($id)` und `->whereGlobalObjectId($id)` schlagen eine nach, `$invoice->publicId()` / `->globalObjectId()` geben sie heraus. Verwenden Sie die Public ID in URLs, APIs und Logs; verwenden Sie die Global Object ID, wenn ein anderer Dienst oder ein Audit-Trail das Objekt eindeutig benennen muss. Public IDs verhindern Enumeration, sie sind keine Autorisierung: Die Tenant-Middleware und Ihre Policies entscheiden weiterhin, wer die Rechnung sehen darf. Ein Bulk-`insert()` umgeht Eloquent-Events und vergibt daher keine ID (die Spalte ist `NOT NULL`, die Datenbank lehnt die Zeile also ab); erzeugen Sie Datensätze über das Model.

**4. Routen**

```php
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index']);      // Invoice::all() is scoped
    Route::delete('/invoices/{invoice}', ...)->middleware('tenant.role:admin');  // {invoice} is bound after the tenant is resolved
});
```

Der Tenant kommt aus `X-Tenant-Id` (interne ID, `ten_…`-Public-ID, Global Object ID oder Slug), einem `{tenant}`-Routenparameter, der Session oder einer Subdomain, in der konfigurierten Reihenfolge. Unbekannter Tenant: 404. Gesperrter Tenant: 403. Keine Mitgliedschaft: 403 plus ein Audit-Ereignis. Die Tenant-Middleware trägt sich in Laravels Middleware-Prioritätsliste direkt vor `SubstituteBindings` ein, sodass implizites Route-Model-Binding tenant-gebundener Models innerhalb der Gruppen `web` und `api` funktioniert, in welcher Reihenfolge die Route sie auch deklariert.

**5. Audit**

```php
use Efnext\Security\Audit\Facades\Audit;

Audit::action('domain.verified')->on($domain)->with(['method' => 'dns'])->record();
Audit::action('auth.login_failed')->on('user', $email)->denied()->record();
```

Akteur, Tenant, IP, User-Agent, Request-ID und Correlation-ID werden automatisch befüllt und sind Teil des Hashes. Führen Sie `php artisan audit:verify` und `php artisan audit:anchor` aus dem Scheduler aus.

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

Die Closure kann mehr als einmal laufen: Halten Sie E-Mails, API-Aufrufe und andere Seiteneffekte außerhalb davon (stellen Sie sie nach der Rückkehr in die Queue oder machen Sie sie idempotent).

**6. Betrieb**

```bash
php artisan security:doctor            # after every deployment and from monitoring (--json; --strict = deployment gate of the high-security profile)
php artisan audit:anchor               # scheduler, every few minutes
php artisan audit:verify               # scheduler, at least daily
```

In `APP_ENV=production` verweigert der Audit-Provider den Start ohne Signaturschlüssel, auf SQLite, mit abgeschalteter Append-only-Durchsetzung (nur anfügend) oder ohne lesbaren Anker-Store (`AUDIT_FAIL_FAST=false` ist die bewusste, vorübergehende Ausnahme). Setzen Sie `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true`, sobald Ihr Bucket mit Object Lock eingerichtet ist: Die Produktion verweigert dann auch den Start mit reinen Datenbank-Ankern, und der Doctor meldet sie als Fehler statt als Warnung.

**7. Jobs und Befehle**

```php
ProcessInvoices::dispatch();                        // tenant travels with the job automatically
Tenancy::run($tenantId, fn () => Invoice::count()); // explicit context in code
```

```bash
php artisan tenant:run acme reports:generate        # explicit context on the CLI
```

## Wie weiter

| Sie möchten … | Lesen Sie |
| --- | --- |
| verstehen, was das Framework verspricht und was nicht | [Was es nicht tut](docs/de/what-it-does-not-do.md), [Bedrohungsmodell](docs/de/threat-model.md), [Konzepte](docs/de/concepts.md) |
| es in eine Anwendung integrieren | [Erste Schritte](docs/de/getting-started.md) (eine vollständige erste Integration mit Beispielausgaben), dann die Anleitungen: [Tenancy](docs/de/guides/tenancy.md), [Addressing](docs/de/guides/addressing.md), [Auditing](docs/de/guides/auditing.md), [Jobs und Befehle](docs/de/guides/jobs-and-commands.md), [Sanctum-Tokens](docs/de/guides/sanctum-tokens.md); die [Integrations-Checkliste](docs/de/integration-checklist.md) vor dem Go-live |
| alles in einer lauffähigen Anwendung sehen | [examples/laravel-app](examples/laravel-app/README.md) — eine Standard-Laravel-12-App plus fünfzehn Dateien, bei jedem Push in der CI installiert und getestet |
| etwas nachschlagen | [Konfiguration](docs/de/reference/configuration.md) (jeder Schlüssel und jede Umgebungsvariable), [Artisan-Befehle](docs/de/reference/artisan-commands.md), [Events](docs/de/reference/events.md), [Exceptions](docs/de/reference/exceptions.md), [Öffentliche API](docs/de/reference/api.md), [Kompatibilität](docs/de/reference/compatibility.md) |
| es in Produktion betreiben | [Produktionsleitfaden](docs/de/guides/production.md) (Secrets, Datenbank, Anker auf Objektspeicher, das Deployment-Gate), [Betrieb](docs/de/operations.md) und seine Runbooks: [Monitoring](docs/de/operations/monitoring.md), [Schlüsselrotation](docs/de/operations/key-rotation.md), [Incident Response](docs/de/operations/incident-response.md); `.env.example` listet jede Variable |
| ein Problem diagnostizieren | `php artisan security:doctor` (`--json`, `--strict`), dann [Fehlersuche](docs/de/guides/troubleshooting.md) nach Symptom |
| wissen, wie es innen funktioniert | [Architektur](docs/de/architecture.md) und die Diagramme: [Systemübersicht](docs/de/diagrams/system-overview.md), [Lebenszyklus einer Tenant-Anfrage](docs/de/diagrams/tenant-request-lifecycle.md), [Audit-Schreibpfad und Verifikation](docs/de/diagrams/audit-write-and-verification.md), [Vertrauensgrenzen](docs/de/diagrams/trust-boundaries.md), [Datenmodell](docs/de/diagrams/data-model.md) |
| beitragen oder ein Release erstellen | [CONTRIBUTING.md](CONTRIBUTING.md), [Testen](docs/maintainers/testing.md), [Releases](docs/maintainers/releasing.md), [SUPPORT.md](SUPPORT.md) |
| es von einem KI-Agenten integrieren lassen | [AGENTS.md](AGENTS.md) — der Vertrag, den ein Agent einhalten muss, und wie er seine eigene Arbeit prüft; [llms.txt](llms.txt) indexiert die Dokumentation für Agenten |

Die Dokumentation wird zuerst auf Englisch geschrieben ([README.md](README.md), `docs/en/`). Deutsche (diese Seite, `docs/de/`) und schwedische ([README.sv.md](README.sv.md), `docs/sv/`) Ausgaben der Benutzer- und Betreiberseiten folgen jedem Release; jede übersetzte Seite nennt die englische Quelle und den Stand, dem sie entspricht.

## KI-Coding-Agenten

Das Framework soll von Menschen und von Coding-Agenten gleichermaßen integriert werden. Es liefert die Regeln, die ein Agent einhalten muss, in drei Längen:

- **[Laravel Boost](https://laravel.com/docs/boost):** In einer Anwendung mit Boost führt `php artisan boost:install` (oder `boost:update`) `efnext/security-framework` unter den Drittpaketen auf; sobald es ausgewählt ist, lädt Boost die Richtlinie des Pakets in den Kontext des Agenten – die Regeln, die die Garantien erhalten, in [resources/boost/guidelines/core.blade.php](resources/boost/guidelines/core.blade.php) – und installiert den Skill [`efnext-security-integration`](resources/boost/skills/efnext-security-integration/SKILL.md): den Integrationsablauf und seine Prüfung, geladen, sobald der Agent ihn braucht.
- **[AGENTS.md](AGENTS.md):** der vollständige Vertrag, für Agenten ohne Boost und für Agenten, die das Framework selbst ändern.
- **[llms.txt](llms.txt):** ein Index der Dokumentation.

Verweigerungen und Befunde des Doctors nennen ihre Behebung, `security:doctor --json` ist maschinenlesbar, und die Exit-Codes von `security:doctor --strict` und `audit:verify` zeigen einem Agenten, ob seine Arbeit die Garantien erhält.

## Wie das Framework entstanden ist

Das Framework wurde von einem KI-Coding-Agenten geschrieben, Claude von Anthropic, unter der Leitung des Maintainers Enrico Engel (ef.next): Er hat die Architektur und die Sicherheitsentscheidungen festgelegt und jede Änderung abgenommen. Es hat mehrere Review-Runden durchlaufen; die daraus folgenden Korrekturen stehen im [CHANGELOG](CHANGELOG.md).

Wir bitten Sie deshalb nicht, dem Code zu vertrauen, sondern ihn zu prüfen. Die Belege liegen in diesem Repository und laufen bei jedem Push:

- die Testsuite auf SQLite, PostgreSQL, MySQL und MariaDB, einschließlich paralleler Schreiber, mit einer Mindest-Coverage;
- Mutation Testing über den Sicherheitskern als CI-Gate;
- PHPStan auf Level 8, Pint und `composer audit`;
- [Known-Answer-Vektoren](packages/audit/tests/Fixtures/hash-vectors.json), mit denen sich Audit-Hashes und Signaturen ohne diese Codebasis nachrechnen lassen;
- signierte Release-Tags, geprüft gegen den [Trust Root](.github/allowed_signers) im Repository, mit SBOMs und Build-Provenance ([Releasing](docs/maintainers/releasing.md)).

Was noch fehlt, ist ein unabhängiges Sicherheitsaudit durch Dritte. Bis es vorliegt, behandeln Sie das Framework als die Beta, als die es gekennzeichnet ist, und melden Sie Funde wie in [SECURITY.md](SECURITY.md) beschrieben.

## Garantien und ihre Bedingungen

| Das Framework garantiert … | … unter der Bedingung, dass |
| --- | --- |
| Eloquent-Abfragen auf Models mit `BelongsToTenant` liefern nie die Zeilen eines anderen Tenants und laufen nie ohne Tenant. | Sie rufen `withoutGlobalScope(TenantScope::class)` nicht auf, verwenden für diese Tabellen kein `DB::table()` und führen kein rohes SQL aus. Greppen Sie im Code-Review danach. |
| Ein Datensatz kann über Eloquent nicht für einen fremden Tenant erzeugt, dorthin verschoben, dort aktualisiert oder gelöscht werden, selbst wenn die Model-Instanz in einem anderen Kontext geladen wurde. | Wie oben. Mass Assignment der Tenant-Spalte ist blockiert, sofern Sie sie nicht selbst zu `$fillable` hinzufügen. |
| Eine Anfrage erreicht Ihren Controller nur, wenn der Benutzer ein aktives, nicht abgelaufenes Mitglied eines existierenden, aktiven Tenants ist (und die von Ihnen verlangte Rolle hat). | Die Route trägt `auth` und die Gruppe `tenant` (oder `tenant.resolve` + `tenant.member`). |
| Ein Audit-Datensatz kann über die Datenbank nicht geändert oder gelöscht werden, ohne eine Spur zu hinterlassen. | Trigger sind installiert (die Migration verweigert nicht unterstützte Treiber), ein Signaturschlüssel ist konfiguriert, und `audit:anchor` schreibt in einen Store außerhalb der Datenbank. Ohne den Schlüssel kann ein Angreifer mit Datenbankzugriff eine Kette konsistent umschreiben; ohne externe Anker kann er sie kürzen. `security:doctor` prüft all das; `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` macht den externen Store zur Startbedingung. |
| Eine Kindzeile kann nur auf einen Elterndatensatz desselben Tenants verweisen, egal, was sie geschrieben hat. | Die Kindtabelle verwendet `$table->tenantForeignId()` / `tenantForeignUuid()` / `tenantForeignUlid()`, und die Elterntabelle wurde mit `$table->tenantId()` angelegt (zusammengesetzter Schlüssel). |
| Gleichzeitige Schreiber verzweigen eine Kette nie und geraten an ihr nicht in einen Deadlock. | Die Audit-Tabellen liegen auf einer Datenbank, die `SELECT … FOR UPDATE` respektiert (PostgreSQL, MySQL/InnoDB, MariaDB). Geschäftstransaktionen, die Audit-Schreibvorgänge enthalten, sind in `Audit::transaction()` gekapselt (wiederholt Nebenläufigkeitskonflikte mit Back-off), und für brandneue Tenants ist `Audit::prepareChain()` einmal gelaufen. |
| Public IDs sind nicht erratbar. | Sie behalten die Standard-Entropie (80 Bit) bei. Ein Autorisierungsmechanismus sind sie trotzdem nicht. |

## Kompatibilität

| | Unterstützt – harte CI-Gates | Experimentell – installierbar, in der CI beobachtet, kein Support-Versprechen |
| --- | --- | --- |
| PHP | 8.3, 8.4 | 8.5 |
| Laravel | 12 | 13 |
| Datenbanken | PostgreSQL 14–17, MySQL 8.0/8.4, MariaDB 10.11–11 (SQLite nur für Tests) | — |

Die Composer-Constraints lassen die experimentellen Kombinationen zu, damit sie
ausprobiert werden können; eine Kombination wird unterstützt, sobald ihr CI-Job
für ein Release ein verpflichtendes Gate war. Nur das jeweils neueste Minor-Release
erhält Korrekturen (`SECURITY.md`); der Release-Prozess steht in [docs/maintainers/releasing.md](docs/maintainers/releasing.md).

## Entwicklung

Alles läuft in Docker; der Host braucht nichts außer Docker Desktop:

```bash
./start-security-framework.sh          # composer audit + selfcheck + Pint + PHPStan + tests on sqlite, pgsql, mysql, mariadb
./start-security-framework.sh mutate   # mutation testing on the security core
```

Die übrigen Modi (`test <db>`, `concurrency`, `matrix`, `load`, `shell`, `down`, `PHP_VERSION=`, `LARAVEL=`, `DEPS=`), die von der CI durchgesetzten Qualitäts-Gates und die Regeln für Pull Requests stehen in [CONTRIBUTING.md](CONTRIBUTING.md) und [docs/maintainers/testing.md](docs/maintainers/testing.md).

### Verwendung aus einem Laravel-Sail-Produkt

Das Framework-Repository ist keine Laravel-Anwendung, deshalb bringt es statt
Sail eine eigene `compose.yaml` mit; der Arbeitsablauf ist derselbe (nichts
außer Docker auf dem Host). Um aus einem Sail-basierten Produkt dagegen zu
entwickeln, fügen Sie ein Path-Repository hinzu und fordern die Dev-Version an:

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

Mounten Sie das Framework-Verzeichnis in den Sail-Container (`volumes:` in der
`compose.yaml` Ihres Produkts), damit der Symlink innerhalb von Docker aufgelöst
wird. Der MySQL-Dienst von Sail braucht für die Audit-Trigger
`--log-bin-trust-function-creators=1` (oder einen Root-Benutzer); siehe
[docs/de/operations.md](docs/de/operations.md).

## Dokumentation

- [Erste Schritte](docs/de/getting-started.md) · [Konzepte](docs/de/concepts.md) · [Was es nicht tut](docs/de/what-it-does-not-do.md)
- Anleitungen: [Tenancy](docs/de/guides/tenancy.md) · [Addressing](docs/de/guides/addressing.md) · [Auditing](docs/de/guides/auditing.md) · [Jobs und Befehle](docs/de/guides/jobs-and-commands.md) · [Sanctum-Tokens](docs/de/guides/sanctum-tokens.md) · [Produktion](docs/de/guides/production.md) · [Fehlersuche](docs/de/guides/troubleshooting.md)
- Referenz: [Konfiguration](docs/de/reference/configuration.md) · [Artisan-Befehle](docs/de/reference/artisan-commands.md) · [Events](docs/de/reference/events.md) · [Exceptions](docs/de/reference/exceptions.md) · [Öffentliche API](docs/de/reference/api.md) · [Kompatibilität](docs/de/reference/compatibility.md)
- Betrieb: [Betrieb](docs/de/operations.md) · [Monitoring](docs/de/operations/monitoring.md) · [Schlüsselrotation](docs/de/operations/key-rotation.md) · [Incident Response](docs/de/operations/incident-response.md)
- Design: [Architektur](docs/de/architecture.md) · [Bedrohungsmodell](docs/de/threat-model.md) · [Diagramme](docs/de/diagrams/system-overview.md) · [Integrations-Checkliste](docs/de/integration-checklist.md)
- [Beispielanwendung](examples/laravel-app/README.md) · [AGENTS.md](AGENTS.md) · [llms.txt](llms.txt)
- [Mitwirken](CONTRIBUTING.md) · [Testen](docs/maintainers/testing.md) · [Releases](docs/maintainers/releasing.md) · [Support](SUPPORT.md) · [Verhaltenskodex](CODE_OF_CONDUCT.md)
- [Sicherheitsrichtlinie](SECURITY.md) · [Changelog](CHANGELOG.md) · [Lizenz (MIT)](LICENSE)
