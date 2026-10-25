<!-- source: docs/en/guides/addressing.md sha256: f9b7fdfd0355c43eaf4705aa0bb3cc0e0ad774cac31123005072b5fc7c7fe801 -->
# Addressing-Leitfaden

*Deutsche Ausgabe — die [englische Fassung](../../en/guides/addressing.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

`efnext/security-addressing` gibt Models zwei Identifier, die gefahrlos
angezeigt werden können: eine **Public ID** (`inv_7f3k9zq2m4x8c1bt`) und eine
**Global Object ID** (`billing:invoice:inv_7f3k9zq2m4x8c1bt`). Interne
Auto-Increment-Schlüssel bleiben intern.

## Warum

Sequenzielle IDs verraten das Geschäftsvolumen (`/invoices/4711` sagt, dass
Sie 4.711 Rechnungen haben), laden zur Enumeration ein (`/invoices/4712`) und
sind nur innerhalb einer Tabelle eines Dienstes eindeutig. Eine Public ID ist
nicht erratbar (standardmäßig 80 Bit Zufälligkeit), trägt ein Typ-Präfix, das
Logs und Support-Tickets lesbar macht, und ist nach der Vergabe
unveränderlich. Eine Global Object ID fügt den Dienst und den Ressourcentyp
hinzu, sodass ein Audit-Trail, eine Nachricht auf einem Bus oder ein anderer
Dienst das Objekt ohne Mehrdeutigkeit benennen kann.

Was sie **nicht** sind: eine Autorisierung. Eine ID zu kennen ist keine
Berechtigung. Die Tenant-Middleware und Ihre Policies entscheiden, wer die
Rechnung sehen darf; die ID verhindert nur das Erraten.

## 1. Einem Model eine Public ID geben

```php
use Efnext\Security\Addressing\Eloquent\HasPublicId;

class Invoice extends Model
{
    use HasPublicId;

    public static function publicIdPrefix(): string { return 'inv'; }   // [a-z][a-z0-9]{1,11}
}

Schema::create('invoices', function (Blueprint $table): void {
    $table->id();
    $table->publicId();     // string(80), unique; column name from config (public_id)
    // …
});
```

- Wird bei `creating` vergeben; ein vorab gesetzter Wert wird nur akzeptiert,
  wenn er wohlgeformt ist und das Präfix des Models trägt.
- Unveränderlich: Das Ändern auf einem persistierten Model wirft
  `PublicIdImmutable`.
- Automatisch gegen Mass Assignment geschützt.
- `$invoice->publicId()` liefert ein `PublicId`-Value-Object (`->toString()`,
  `->prefix`, `->body`); bevor das Model erzeugt ist, wirft es
  `MissingPublicId`.

**Route-Model-Binding** verwendet ab jetzt die Public ID:

```php
Route::get('/invoices/{invoice}', fn (Invoice $invoice) => $invoice);
```

Ein fehlerhaft gebildeter Wert (`inv_x`, `4711`, `../etc`) liefert 404 *ohne
Datenbankabfrage*; eine unbekannte oder — innerhalb eines Tenants — fremde ID
liefert 404 nach einem indizierten Lookup. Abfragen:
`Invoice::wherePublicId($id)` (fehlerhaft gebildet → keine Zeilen, nie ein
Fehler).

**Sicherheitswirkung.** Keine Enumeration, kein Volumen-Leck, lesbare
Identifier. **Fehlkonfiguration.** `id` trotzdem in JSON-Antworten
offenzulegen — fügen Sie `id` zu `$hidden` hinzu oder verwenden Sie
API-Resources; `find($request->id)` statt Route-Binding oder
`wherePublicId()` zu verwenden.

**Ihr Test.**

```php
it('binds by public id and hides malformed and foreign ids behind 404', function (): void {
    $invoice = Tenancy::run($this->acme->tenantId(), fn () => Invoice::create(['number' => 'A-1']));

    $this->actingAs($this->alice)->getJson("/api/invoices/{$invoice->public_id}", ['X-Tenant-Id' => 'acme'])->assertOk();
    $this->actingAs($this->alice)->getJson('/api/invoices/inv_x', ['X-Tenant-Id' => 'acme'])->assertNotFound();
    $this->actingAs($this->alice)->getJson('/api/invoices/'.$invoice->id, ['X-Tenant-Id' => 'acme'])->assertNotFound();
});
```

## 2. Ihm eine Global Object ID geben

```php
use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;

class Invoice extends Model implements ExternallyAddressable
{
    use HasPublicId;
    use HasGlobalObjectId;

    public static function publicIdPrefix(): string { return 'inv'; }
    public static function resourceType(): string { return 'invoice'; }   // [a-z][a-z0-9-]{0,62}
}

$invoice->globalObjectId()->toString();   // billing:invoice:inv_7f3k9zq2m4x8c1bt
Invoice::whereGlobalObjectId($id)->first();   // no rows for another service or type
```

Das erste Segment ist `SECURITY_SERVICE_NAME`
(`config('addressing.service_name')`). Setzen Sie es einmal, bevor die erste
ID das System verlässt, und ändern Sie es nie: Jede bereits ausgegebene
Global ID würde sich sonst nicht mehr auflösen lassen.

**Was wann verwenden.** Die Public ID in URLs, API-Payloads, UI und Logs
*dieser* Anwendung. Die Global Object ID immer dann, wenn das Objekt
*außerhalb* davon benannt wird: Audit-Ereignisse (`Audit::action()->on($invoice)`
verwendet `resourceType()` und die Public ID), Nachrichten an andere Dienste,
dienstübergreifende Referenzen, Support-Tooling. Auf `ExternallyAddressable`
verlassen sich der Audit-Builder und das Tenant-Model.

## 3. Nicht vertrauenswürdige Eingaben parsen

```php
use Efnext\Security\Addressing\Identifiers\GlobalObjectId;
use Efnext\Security\Addressing\Identifiers\PublicId;

$id = PublicId::tryFromString($request->query('id'));            // null when malformed; fromString() throws
if ($id === null || ! $id->hasPrefix('inv')) { abort(404); }

$gid = GlobalObjectId::tryFromString($payload['object']);
if ($gid === null || ! $gid->is('billing', 'invoice')) { … }
```

Beide Value Objects sind kanonisch-oder-ungültig: nur Kleinbuchstaben, feste
Formen (`prefix_body`, Body 8–64 alphanumerische Kleinbuchstaben; Segmente
`[a-z][a-z0-9-]{0,62}`). Es gibt keinen „Normalisierungs“-Schritt, der zwei
Schreibweisen gleich machen könnte.

## 4. Bulk-Inserts

`insert()` umgeht Eloquent-Events, sodass nichts die ID vergibt und die `NOT
NULL`-Spalte die Zeile ablehnt. Erzeugen Sie entweder über das Model, oder
generieren Sie IDs explizit:

```php
$generator = app(\Efnext\Security\Addressing\Contracts\PublicIdGenerator::class);

DB::table('invoices')->insert(array_map(fn (array $row) => $row + [
    'public_id' => $generator->generateUnique('inv', 'invoices', 'public_id')->toString(),
], $rows));
```

`generateUnique()` prüft die Tabelle, bevor es zurückkehrt; der
`UNIQUE`-Index bleibt die eigentliche Garantie.

## 5. Feinabstimmung (selten nötig)

| Einstellung | Standard | Hinweis |
| --- | --- | --- |
| `SECURITY_PUBLIC_ID_ENTROPY_BYTES` | 10 (80 Bit) | unter 8 verweigert der Generator |
| `SECURITY_PUBLIC_ID_ENCODING` | `base32` (Crockford-Alphabet, 16 Zeichen für 10 Byte) | `hex` verdoppelt die Länge |
| `SECURITY_PUBLIC_ID_COLUMN` | `public_id` | pro Model: `publicIdColumn()` |
| `SECURITY_PUBLIC_ID_MAX_ATTEMPTS` | 5 | Kollisions-Wiederholungen; bei 80 Bit praktisch nie genutzt |

## Was das Addressing-Paket nicht tut

- Zugriff autorisieren (siehe oben);
- die interne `id` von sich aus vor JSON verbergen;
- IDs nach einer Änderung von `SECURITY_SERVICE_NAME` oder eines Präfixes
  umbenennen oder migrieren;
- Models schützen, die den Trait nicht verwenden.
