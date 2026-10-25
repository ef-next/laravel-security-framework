<!-- source: docs/en/guides/addressing.md sha256: f9b7fdfd0355c43eaf4705aa0bb3cc0e0ad774cac31123005072b5fc7c7fe801 -->
# Adresseringsguide

*Svensk utgåva — den [engelska versionen](../../en/guides/addressing.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

`efnext/security-addressing` ger modeller två identifierare som är säkra att
visa: ett **publikt id** (`inv_7f3k9zq2m4x8c1bt`) och ett **globalt
objekt-id** (`billing:invoice:inv_7f3k9zq2m4x8c1bt`). Interna
auto-increment-nycklar förblir interna.

## Varför

Sekventiella id:n läcker affärsvolym (`/invoices/4711` avslöjar att du har
4 711 fakturor), inbjuder till enumerering (`/invoices/4712`) och är bara
unika inom en tabell i en tjänst. Ett publikt id är ogissningsbart (80
bitars slumpmässighet som standard), bär ett typprefix som gör loggar och
supportärenden läsbara, och är oföränderligt när det väl har tilldelats. Ett
globalt objekt-id lägger till tjänsten och resurstypen, så att ett
revisionsspår, ett meddelande på en buss eller en annan tjänst kan namnge
objektet utan tvetydighet.

Vad de **inte** är: auktorisering. Att känna till ett id är inte en
behörighet. Tenant-middlewaren och dina policies avgör vem som får se
fakturan; id:t stoppar bara gissningar.

## 1. Ge en modell ett publikt id

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

- Tilldelas vid `creating`; ett förinställt värde accepteras bara om det är
  välformat och bär modellens prefix.
- Oföränderligt: att ändra det på en sparad modell kastar `PublicIdImmutable`.
- Skyddas automatiskt mot mass assignment.
- `$invoice->publicId()` returnerar ett `PublicId`-värdesobjekt
  (`->toString()`, `->prefix`, `->body`); innan modellen har skapats kastar
  det `MissingPublicId`.

**Route model binding** använder från och med nu det publika id:t:

```php
Route::get('/invoices/{invoice}', fn (Invoice $invoice) => $invoice);
```

Ett felformat värde (`inv_x`, `4711`, `../etc`) ger 404 *utan någon
databasfråga*; ett okänt id, eller — inuti en tenant — ett främmande id, ger
404 efter en indexerad slagning. Frågor: `Invoice::wherePublicId($id)`
(felformat → inga rader, aldrig ett fel).

**Säkerhetseffekt.** Ingen enumerering, inget volymläckage, läsbara
identifierare.
**Felkonfiguration.** Att ändå exponera `id` i JSON-svar — lägg till `id` i
`$hidden` eller använd API-resurser; att använda `find($request->id)` i
stället för route binding eller `wherePublicId()`.

**Ditt test.**

```php
it('binds by public id and hides malformed and foreign ids behind 404', function (): void {
    $invoice = Tenancy::run($this->acme->tenantId(), fn () => Invoice::create(['number' => 'A-1']));

    $this->actingAs($this->alice)->getJson("/api/invoices/{$invoice->public_id}", ['X-Tenant-Id' => 'acme'])->assertOk();
    $this->actingAs($this->alice)->getJson('/api/invoices/inv_x', ['X-Tenant-Id' => 'acme'])->assertNotFound();
    $this->actingAs($this->alice)->getJson('/api/invoices/'.$invoice->id, ['X-Tenant-Id' => 'acme'])->assertNotFound();
});
```

## 2. Ge den ett globalt objekt-id

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

Det första segmentet är `SECURITY_SERVICE_NAME`
(`config('addressing.service_name')`). Sätt det en gång, innan det första
id:t lämnar systemet, och ändra det aldrig: varje utfärdat globalt id skulle
sluta gå att slå upp.

**Vilket du ska använda när.** Publikt id i URL:er, API-payloads,
användargränssnitt och loggar för *den här* applikationen. Globalt
objekt-id när objektet namnges *utanför* den: audit-händelser
(`Audit::action()->on($invoice)` använder `resourceType()` och det publika
id:t), meddelanden till andra tjänster, tjänsteöverskridande referenser,
supportverktyg. `ExternallyAddressable` är vad audit-byggaren och
tenant-modellen förlitar sig på.

## 3. Tolka opålitlig indata

```php
use Efnext\Security\Addressing\Identifiers\GlobalObjectId;
use Efnext\Security\Addressing\Identifiers\PublicId;

$id = PublicId::tryFromString($request->query('id'));            // null when malformed; fromString() throws
if ($id === null || ! $id->hasPrefix('inv')) { abort(404); }

$gid = GlobalObjectId::tryFromString($payload['object']);
if ($gid === null || ! $gid->is('billing', 'invoice')) { … }
```

Båda värdesobjekten är kanoniska-eller-ogiltiga: bara gemener, fasta former
(`prefix_body`, kropp 8–64 gemena alfanumeriska tecken; segment
`[a-z][a-z0-9-]{0,62}`). Det finns inget ”normaliserings”-steg som skulle
kunna göra två stavningar likvärdiga.

## 4. Massinsättningar

`insert()` kringgår Eloquent-events, så ingenting tilldelar id:t och `NOT
NULL`-kolumnen nekar raden. Antingen skapar du via modellen, eller så
genererar du id:n explicit:

```php
$generator = app(\Efnext\Security\Addressing\Contracts\PublicIdGenerator::class);

DB::table('invoices')->insert(array_map(fn (array $row) => $row + [
    'public_id' => $generator->generateUnique('inv', 'invoices', 'public_id')->toString(),
], $rows));
```

`generateUnique()` kontrollerar tabellen innan den returnerar; `UNIQUE`-
indexet förblir den verkliga garantin.

## 5. Finjustering (sällan nödvändigt)

| Setting | Default | Note |
| --- | --- | --- |
| `SECURITY_PUBLIC_ID_ENTROPY_BYTES` | 10 (80 bitar) | under 8 nekar generatorn |
| `SECURITY_PUBLIC_ID_ENCODING` | `base32` (Crockford-alfabetet, 16 tecken för 10 byte) | `hex` fördubblar längden |
| `SECURITY_PUBLIC_ID_COLUMN` | `public_id` | per modell: `publicIdColumn()` |
| `SECURITY_PUBLIC_ID_MAX_ATTEMPTS` | 5 | kollisionsförsök; används i praktiken aldrig vid 80 bitar |

## Vad addressing-paketet inte gör

- auktorisera åtkomst (se ovan);
- dölja det interna `id` från JSON på egen hand;
- byta namn på eller migrera id:n efter en ändring av `SECURITY_SERVICE_NAME`
  eller ett prefix;
- skydda modeller som inte använder traitet.
