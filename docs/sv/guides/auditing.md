<!-- source: docs/en/guides/auditing.md sha256: 21964ce49e2f8cbff03bb2c405d4f942a9c0ad4339ab855c24130e3787cc039c -->
# Audit-guide

*Svensk utgåva — den [engelska versionen](../../en/guides/auditing.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Recept för `efnext/security-audit`: vad som ska registreras, hur det
registreras, vad som hamnar i loggen och hur du får reda på när någon har
rört den. Modellen bakom det finns i [Koncept](../concepts.md); skrivvägen
och verifieringen är ritade i
[diagrams/audit-write-and-verification.md](../diagrams/audit-write-and-verification.md).

## 1. Registrera en affärshändelse

```php
use Efnext\Security\Audit\Facades\Audit;

Audit::action('invoice.paid')
    ->on($invoice)                                    // any ExternallyAddressable model …
    ->with(['total_cents' => 12500, 'method' => 'sepa'])
    ->record();

Audit::action('auth.login_failed')
    ->on('user', $email)                              // … or an explicit type + id
    ->denied()
    ->with(['reason' => 'password'])
    ->record();
```

Fylls i åt dig: tenanten (från den aktiva kontexten), aktören (den
autentiserade användaren, eller `anonymous`, eller `system('console')`), IP,
user agent, request-id och korrelations-id, tidpunkten, sekvensnumret,
föregångarens hash, händelsens hash och signaturen. Allt detta ingår i
hashen.

Namngivning: åtgärder är `noun.verb_past` i lower snake case
(`invoice.paid`, `member.role_changed`); resurstyper är substantiv i
singular med gemener. Loggen överlever din kod — välj namn du fortfarande
kan läsa om fem år.

**Ditt test.**

```php
it('records a paid invoice on the tenant chain', function (): void {
    Tenancy::run($this->acme->tenantId(), function (): void {
        $invoice = Invoice::create(['number' => 'A-1']);
        Audit::action('invoice.paid')->on($invoice)->record();

        $event = AuditEvent::query()->forResource('invoice', $invoice->public_id)->firstOrFail();
        expect($event->action)->toBe('invoice.paid')->and($event->sequence)->toBe(1);
    });
});
```

## 2. Affärsändring och audit-post i samma transaktion

```php
Audit::transaction(function () use ($invoice): void {
    $invoice->markPaid();
    Audit::action('invoice.paid')->on($invoice)->record();
});
```

`Audit::transaction()` är `DB::transaction()` plus det kedjan behöver: när
ingen transaktion är öppen försöker den om hela closuren med jitter i
backoff-tiden vid samtidighetsfel (InnoDB-deadlockar,
PostgreSQL-serialiseringsfel, MariaDB:s snapshot-isolation-fel 1020),
eftersom samtidiga skribenter mot samma tenant serialiseras på kedjans
huvudrad. Inuti en befintlig transaktion körs den en gång.

Regler: closuren kan köras mer än en gång — inga e-postmeddelanden, inga
API-anrop, inga icke-idempotenta sidoeffekter inuti den; köa dem efter att
den har returnerat. Använd aldrig ren `DB::transaction()` runt en
audit-skrivning: återförsöksreglerna saknas, och under belastning kan de
första händelserna i nya kedjor deadlocka på InnoDB. För en helt ny tenant
anropar du `Audit::prepareChain($tenantId)` en gång (när du skapar tenanten)
så att huvudraden finns innan den första skuren av händelser.

## 3. Aktörer

```php
Audit::action('export.downloaded')->on($report)->by(Actor::service('reporting-worker'))->record();
Audit::action('retention.applied')->on('tenant', $tenantId)->bySystem('scheduler')->record();
```

```php
// Let your user model describe itself (label, service accounts, impersonation)
final class User extends Authenticatable implements AuditActor
{
    public function toAuditActor(): Actor
    {
        return $this->impersonator
            ? Actor::user($this->id, "{$this->email} (impersonated by {$this->impersonator->email})")
            : Actor::user($this->id, $this->email);
    }
}
```

Aktörstyp, id och label hashas; labels lagras som de är — lägg inte
hemligheter i dem.

## 4. Metadata: vad du ska skicka med, vad som händer med det

Skicka med fälten som händelsen handlar om — belopp, tillstånd,
identifierare för relaterade objekt (som publika id:n). Aldrig
`$request->all()`, aldrig hela modeller.

Saneraren gör sedan, i denna ordning: redigerar bort (redact) nycklar vars
token matchar ett hemlighetsmönster (`password`, `token`, `api_key`,
`authorization`, `iban`, `cvv`, …) → `[REDACTED]`; pseudonymiserar
identifierare (`email`, `username`, `phone`, …) till `email_hash` = HMAC av
värdet med en pepper härledd från `APP_KEY` (eller `AUDIT_PSEUDONYM_KEY`);
redigerar bort *värden* som ser ut som hemligheter oavsett nyckel (JWT:er,
bearer-strängar, PEM-nycklar, kortnummer, molnnycklar); begränsar djup (6),
nycklar per nivå (100) och stränglängd (1 024).

```php
->with(['customer_email' => 'zoe@example.test', 'card_number' => '4111 1111 1111 1111', 'note' => 'ok'])
// stored: {"customer_email_hash": "9f2c…", "card_number": "[REDACTED]", "note": "ok"}
```

Metadata är oföränderlig och *saniterad, inte krypterad*. Personuppgifter
som måste kunna raderas hör hemma utanför loggen, refererade via publikt
id; att rotera `AUDIT_PSEUDONYM_KEY` efter lagringsperioden är det närmaste
crypto-shredding designen erbjuder. Om du behöver resursens tidigare
tillstånd, lägg själv in en diff i metadatan.

## 5. Tenant-lösa och globala händelser

Under standardpolicyn (`AUDIT_TENANT_POLICY=required`) nekas en händelse
utan tenant — det fångar kodvägar som har tappat sin kontext. Händelser som
verkligen är tenant-lösa går explicit till den `global`-kedjan:

```php
Audit::action('deploy.completed')->on('release', 'v1.0.0-beta.1')->global()->bySystem('ci')->record();
```

Tenant-paketets egna säkerhetshändelser (`tenant.access_denied`,
`tenant.scope_bypassed`, `tenant.membership.*`, `tenant.switched`)
registreras automatiskt och hamnar på den globala kedjan när ingen tenant är
känd, oavsett vad policyn säger.

## 6. Läsa loggen

```php
AuditEvent::query()->forResource('invoice', $invoice->public_id)->orderBy('sequence')->get();
AuditEvent::query()->forChain(ChainKey::fromString('tenant:'.$tenantId))->where('action', 'like', 'invoice.%')->latest('occurred_at')->paginate();
```

`AuditEvent` är skrivskyddad (uppdatering eller radering kastar ett fel).
Kolumner: `chain_key`, `tenant_id`, `sequence`, `previous_hash`,
`event_hash`, `hash_version`, `key_id`, `signature`, `occurred_at`,
`occurred_at_us`, `recorded_at`, `actor_type`, `actor_id`, `actor_label`,
`action`, `resource_type`, `resource_id`, `outcome`, `metadata` (kanonisk
JSON), `request_id`, `correlation_id`, `ip_address`, `user_agent`.
`recorded_at` är den enda beskrivande kolumnen utanför hashen (den sätts av
servern efter hashningen).

## 7. Verifiera

```bash
php artisan audit:verify                       # every chain
php artisan audit:verify --chain=acme-uuid --from=1000
```

```php
$report = Audit::verifyTenant($tenantId);       // or verifyGlobal(), verify(ChainKey, from, to)
$report->isIntact();                           // false → look at ->violations (reason, sequence, event id, details)
```

Vad som kontrolleras, händelse för händelse: sekvenskontinuitet,
föregångarens hash, omberäknad händelsehash, signatur (giltig / okänd
nyckel / saknas), hash-version; och till sist: huvudraden matchar den
senaste händelsen, det senaste ankaret matchar kedjan vid den sekvensen och
ligger inte före den. Varje körning registreras i
`audit_verification_runs`; `security:doctor` använder dessa poster för sin
`verification_recency`-kontroll och verifierar själv de senaste 200
händelserna i varje kedja.

Stora installationer verifierar inkrementellt: håll reda på den senast
verifierade sekvensen per kedja och anropa
`Audit::verify($chain, $lastVerified + 1)`; verifieraren kontrollerar
föregångarlänken, så bitarna kedjas ihop.

**Larmning.** En kedja med avvikelser skickar `ChainViolationDetected` (med
rapporten inkluderad) och gör att `audit:verify` avslutas med kod 1. Lyssna
på händelsen; larma (page) någon. Se
[operations/incident-response.md](../operations/incident-response.md) för
vad du ska göra härnäst.

## 8. Ankare

`audit:anchor` kopierar huvudet på varje kedja (sekvens, hash, signatur)
till de konfigurerade ankarlagren. Med enbart `database` kan en angripare
som kan skriva om kedjan skriva om ankaret också, så doctor varnar.
Produktionsuppsättningen är `AUDIT_ANCHOR_STORES=filesystem,database` med en
disk på objektlagring som har Object Lock, samt
`AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` så att produktionen vägrar starta utan
det. Det kopierbara receptet finns i [produktionsguiden](production.md).

## 9. Signeringsnycklar

```bash
php artisan audit:key:generate --id=k2     # rotation: append to AUDIT_SIGNING_KEYS, move AUDIT_SIGNING_ACTIVE_KEY
```

Gamla nycklar stannar kvar i listan så att gamla signaturer fortsätter att
verifiera; ta bara bort en när varje händelse som signerats med den har
lämnat lagringsfönstret. Nycklarna måste finnas i en hemlighetshanterare
(secret manager), aldrig bredvid databasens autentiseringsuppgifter — en
angripare på databasnivå med nyckeln kan skriva om historiken på ett
konsekvent sätt. Detaljer i
[operations/key-rotation.md](../operations/key-rotation.md).

## 10. Separat audit-anslutning

`AUDIT_DB_CONNECTION` flyttar audit-tabellerna till en egen databas och egen
användare (rättigheter: `SELECT, INSERT` på events, anchors och runs;
`SELECT, INSERT, UPDATE` på `audit_chain_heads`). Du vinner
privilegieseparation och förlorar atomicitet med affärsskrivningar:
`Audit::transaction()` kan inte spänna över två anslutningar, så en krasch
mellan de två skrivningarna lämnar den ena utan den andra. Doctor talar om
för dig vilken avvägning du har gjort.

## Vad audit-paketet inte gör

- förhindra manipulation (det gör den detekterbar — kör `audit:verify`);
- signera eller förankra utan en nyckel och utan schemaläggaren;
- registrera något du inte talar om för det (utom tenant-paketets egna
  säkerhetshändelser);
- kryptera metadata eller lösa radering;
- verifiera vid varje skrivning (verifiering är schemalagd och chunkad).

Fullständig lista: [Vad det inte gör](../what-it-does-not-do.md).
