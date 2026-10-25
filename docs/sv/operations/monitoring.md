<!-- source: docs/en/operations/monitoring.md sha256: 1f47218dcaed14fe1ccea7ad534ac218861f595ca8bde0cbc6078822769b3b18 -->
# Övervakning

*Svensk utgåva — den [engelska versionen](../../en/operations/monitoring.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Vad du ska bevaka när ramverket är i produktion, varifrån varje signal kommer och vad en förändring i den betyder. Ramverket sänder ut tre typer av signaler: **doctorn** (konfiguration och hälsa), **verifieraren** (integritet) och **händelser** (beteende). Inget av detta kräver en publicerad konfigurationsfil.

## 1. Doctorn som hälsoendpoint

`php artisan security:doctor --json` skriver ut en rapport:

```json
{
  "healthy": true,
  "strict": false,
  "counts": { "ok": 13, "warn": 0, "fail": 0, "skip": 0 },
  "checks": [
    { "id": "signing_key", "status": "ok", "title": "Signing key", "detail": "…", "hint": null },
    { "id": "anchor_freshness", "status": "ok", "title": "Anchor freshness", "detail": "…", "hint": null }
  ]
}
```

- `healthy` är värdet att styra på (gate on): `false` när någon kontroll misslyckas, och med
  `--strict` även när någon kontroll varnar. Processens exit-kod säger samma sak (0 / 1).
- `checks[].id` är stabilt; larma på id:n, inte på titlar eller formuleringar.
- `--no-verify` hoppar över den direkta svansverifieringen (live tail verification) (den enda
  kontrollen som läser händelserader) och gör körningen tillräckligt billig för att köras med
  några minuters mellanrum.

Kontroll-id:na och vad ett resultat som inte är OK betyder:

| id | VARNING betyder | FEL betyder |
| --- | --- | --- |
| `environment` | `APP_DEBUG` på, eller `AUDIT_FAIL_FAST` av i produktion | — |
| `app_key` | — | ingen `APP_KEY` |
| `signing_key` | — | ingen nyckelring, felaktigt formaterad, aktiv nyckel saknas eller är kortare än 32 byte |
| `database_driver` | — | SQLite i produktion, eller ej stödd drivrutin |
| `append_only_config` | — | `AUDIT_ENFORCE_APPEND_ONLY=false` |
| `append_only_triggers` | — | triggrarna är inte installerade på `audit_events` / `audit_anchors` (borttagna, eller migrering skippad) |
| `anchor_stores` | endast databaslagret är läsbart | inget läsbart lager; ett externt lager som inte svarar; under `--strict`: `AUDIT_REQUIRE_EXTERNAL_ANCHOR` inte satt |
| `separate_connection` | audit-tabellerna delar anslutning med applikationen | — |
| `anchor_freshness` | — | en kedja har händelser äldre än `audit.doctor.max_anchor_age_minutes` (standard 60) som inget ankare täcker; eller det externa lagret kan inte läsas |
| `verification_recency` | ingen intakt fullständig verifiering inom `max_verification_age_hours` (standard 24) | en registrerad körning hittade avvikelser |
| `tail_verification` | — | de sista `tail_events` (standard 200) i en kedja verifieras inte |
| `middleware_priority` | — | tenant-middleware är inte ordnad före `SubstituteBindings` |
| `tenant_cache` | tenant-uppslag använder `array`-lagret i produktion | — |

Två sätt att köra den från övervakningen:

```php
// routes/console.php – mail on failure, one server
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()
    ->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor')->daily()->onOneServer()
    ->emailOutputOnFailure('security@example.test');
```

```bash
# or from an external checker (exit code + JSON), e.g. a cron on the app host
php artisan security:doctor --json --no-verify | jq -e '.healthy' >/dev/null || alert "security:doctor unhealthy"
```

Exponera inte doctorn över HTTP utan autentisering; dess utdata namnger din konfiguration.

## 2. Integritet: verifieraren

`audit:verify` är sanningskällan (source of truth) för att ”loggen är intakt”. Schemalägg
den minst dagligen; varje timme är billigt för de flesta installationer eftersom
verifieringen sker strömmande i delar (chunks).

```php
Schedule::command('audit:verify')->hourly()->onOneServer()->emailOutputOnFailure('security@example.test');
```

Två utdata, koppla båda:

- **Exit-kod 1** närhelst någon kedja har en avvikelse (`--fail-fast` stannar vid den
  första). `emailOutputOnFailure` eller schemaläggarens felhook är minimum.
- **`ChainViolationDetected`** skickas ut (dispatchas) per berörd kedja med den fullständiga
  `VerificationReport`. Detta är signalen att larma (page) på:

```php
use Efnext\Security\Audit\Events\ChainViolationDetected;

Event::listen(ChainViolationDetected::class, function (ChainViolationDetected $event): void {
    $report = $event->report;

    Alert::page('audit-integrity', [
        'chain' => $report->chainKey->toString(),
        'violations' => count($report->violations),
        'first' => $report->violations[0]->describe(),
        'report' => $report->toArray(),
    ]);
});
```

En avvikelse är aldrig brus. Det finns ingen ”förväntad” avvikelse i normal drift — en falsk
positiv betyder att något är fel med driftsättningen (en nyckel borttagen ur ringen, en
återställning utan dess ankare), vilket också är värt en larmning. Följ sedan
[incidenthantering](incident-response.md).

## 3. Beteende: händelser och audit-åtgärder

Ramverket skickar ut (dispatchar) Laravel-händelser som du kan prenumerera på;
audit-paketet skriver redan tenant-händelserna till loggen (`audit.record_tenant_events`).

| Händelse | När | Bevaka |
| --- | --- | --- |
| `Tenant\Events\TenantAccessDenied` | en kontroll av medlemskap, roll eller token nekade en förfrågan | en topp per tenant eller per användare: ett klientfel, en återkallad användare som fortfarande anropar, eller enumerering |
| `Tenant\Events\TenantScopeBypassed` | `Tenancy::withoutTenant()` anropades | varje förekomst utanför de kodvägar du förväntar dig (rapporter, underhållskommandon) |
| `Tenant\Events\MembershipChanged` | beviljande, rolländring, statusändring, återkallelse | ändringar utanför dina admin-flöden |
| `Tenant\Events\TenantSwitched` | sessionens tenant ändrades | ovanlig frekvens per session |
| `Tenant\Events\TenantContextActivated` | en tenant blev aktiv | endast ett volymmått |
| `Audit\Events\AuditEventRecorded` | efter varje lyckad audit-skrivning | skrivfrekvens per kedja (ett fall till noll för en aktiv tenant betyder en trasig writer eller en tyst app) |
| `Audit\Events\ChainViolationDetected` | verifieraren hittade avvikelser | se ovan |

Samma information finns i loggen som audit-åtgärder (`tenant.access_denied`,
`tenant.scope_bypassed`, `tenant.membership.<change>`, `tenant.switched`), så en fråga mot
`audit_events` ger historiken utan en mätvärdespipeline (metrics pipeline):

```sql
-- denied requests per tenant, last 24 h
SELECT tenant_id, COUNT(*) FROM audit_events
WHERE action = 'tenant.access_denied' AND occurred_at > NOW() - INTERVAL '24 hours'
GROUP BY tenant_id ORDER BY 2 DESC;
```

## 4. Mätvärden värda att exportera

| Mätvärde | Källa | Larma när |
| --- | --- | --- |
| åldern på det senaste ankaret per kedja | `audit_anchors` (`MAX(anchored_at)` per `chain_key`) eller doctorns `anchor_freshness` | äldre än dubbla ankarintervallet |
| åldern på den senaste intakta fullständiga verifieringen per kedja | `audit_verification_runs WHERE intact AND source = 'audit:verify'` | äldre än verifieringsintervallet + marginal |
| verifieringskörningar med `intact = false` | `audit_verification_runs` | alla förekomster |
| audit-skrivningar per minut per kedja | `AuditEventRecorded` eller `COUNT(*)` över `occurred_at` | noll för en kedja som normalt är aktiv |
| `tenant.access_denied` per tenant / per aktör | audit-loggen | över en tenant-specifik baslinje |
| `tenant.scope_bypassed` | audit-loggen | varje förekomst med en oväntad `reason` |
| doctor `healthy` | `security:doctor --json` | `false` |

Ankare och verifieringskörningar är vanliga tabeller; vilken exportör som helst som kan köra
SQL kan läsa dem. Kedjehuvud-tabellen (`audit_chain_heads.updated_at`) berättar när en kedja
senast rörde sig.

## 5. Loggar

- Audit-writern loggar inte lyckade skrivningar (raden är själva posten).
- `InsecureConfiguration` vid uppstart, `InvalidSigningKey`, och nekad konfiguration är
  undantag (exceptions), inte loggrader; de når din exception-hanterare och stoppar
  processen.
- `log`-ankarlagret skriver en strukturerad rad per ankare till den kanal du konfigurerar
  (`AUDIT_ANCHOR_LOG_CHANNEL`). Att vidarebefordra den kanalen till ett SIEM eller en
  oföränderlig loggtjänst ger dig en tredje, oberoende kopia av varje kedjehuvud — endast
  skrivbar (write-only), så kombinera den med ett läsbart lager.
- Med `audit.enforce_append_only=false` loggar migreringen en varning; doctorn rapporterar
  det som FAIL vid varje körning.

## 6. Vad övervakning inte kan tala om för dig

- Att ankar-bucketen är *oföränderlig*. Doctorn kontrollerar att lagret svarar och att
  ankarna är färska; Object Lock är en bucket-egenskap du verifierar en gång (försök att
  ta bort ett ankare) och granskar genom din molnleverantörs konfigurationskontroller.
- Att nyckeln och databasen är i olika händer. Det är en egenskap hos driftsättningen; se
  [Förtroendegränser](../diagrams/trust-boundaries.md).
- Att din egen kod bara frågar tenant-bundna tabeller via Eloquent. Det är en
  kodgranskningsfråga; de sammansatta främmande nycklarna är den sista försvarslinjen.

Relaterat: [Produktionsguide](../guides/production.md) · [Incidenthantering](incident-response.md) ·
[Händelsereferens](../reference/events.md) · [Artisan-kommandon](../reference/artisan-commands.md)
