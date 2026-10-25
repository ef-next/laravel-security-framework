<!-- source: docs/en/reference/artisan-commands.md sha256: a1ebce3024e2a9db2c24048dd5c66c0435148b2da63cf07e59bf6cf13c651252 -->
# Artisan-kommandon

*Svensk utgåva — den [engelska versionen](../../en/reference/artisan-commands.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Fem kommandon; fyra från audit-paketet, ett från tenant-paketet. Alla
returnerar `0` vid lyckat resultat och `1` vid fel; `tenant:run` returnerar
exitkoden för det kommando det omsluter.

## `security:doctor`

```
security:doctor [--strict] [--json] [--no-verify]
```

Kontrollerar om denna installation fortfarande ger de garantier som README
lovar. Kör det efter varje driftsättning och från övervakning.

| Alternativ | Effekt |
| --- | --- |
| `--strict` | Högsäkerhetsprofilen/deployment-gaten: varningar räknas som fel, och kontrollen `anchor_stores` misslyckas om inte `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true`. |
| `--json` | Maskinläsbar rapport: `{healthy, strict, counts: {ok, warn, fail, skip}, checks: [{id, status, title, detail, hint}]}`. `healthy` är utfallet enligt det läge körningen bedömdes i. |
| `--no-verify` | Hoppar över den direkta verifieringen av de senaste `audit.doctor.tail_events` händelserna per kedja (snabbare, ingen genomgång av databasen). |

Exitkod `1` när någon kontroll är `FAIL`, eller med `--strict` när någon
kontroll är `WARN`; annars `0`.

| Kontroll-id | Misslyckas när | Varnar när |
| --- | --- | --- |
| `environment` | — | produktion med `APP_DEBUG=true`; produktion med `AUDIT_FAIL_FAST=false` |
| `app_key` | `APP_KEY` är tom | — |
| `signing_key` | nyckelringen är tom eller ogiltig | — |
| `database_driver` | audit-anslutningen är SQLite eller inte pgsql/mysql/mariadb; anslutningen är onåbar | — |
| `append_only_config` | `audit.enforce_append_only` är false | — |
| `append_only_triggers` | en trigger saknas på `audit_events` eller `audit_anchors`; drivrutinen stöds inte | — |
| `anchor_stores` | inget läsbart lager; ett lager kastar ett undantag; externt ankare krävs men är inte uppfyllt eller är oläsbart; `--strict` medan kravet är avstängt | endast database-lagret är läsbart |
| `separate_connection` | — | audit-anslutningen skiljer sig från applikationens anslutning |
| `anchor_freshness` | huvudena är oläsbara; en kedja är oförankrad längre än `max_anchor_age_minutes`; externt ankare krävs men finns inte | — (hoppas över: inga kedjor) |
| `verification_recency` | den senaste fullständiga körningen av en kedja hittade avvikelser | ingen intakt fullständig körning inom `max_verification_age_hours` (hoppas över: inga kedjor) |
| `tail_verification` | svansen (tail) av en kedja verifieras inte | — (hoppas över: `--no-verify` eller inga kedjor) |
| `middleware_priority` | `ResolveTenant` är inte ordnad före `SubstituteBindings` | — |
| `tenant_cache` | — | produktion med en tenant-cache-store av typen `array`/`null` |

Varje rad som inte är OK har en hint som anger åtgärden. Varje tail-kontroll
registreras i `audit_verification_runs` med källan `security:doctor`.

## `audit:verify`

```
audit:verify [--chain=*] [--from=1] [--to=] [--json] [--fail-fast]
```

Går igenom hashkedjorna och rapporterar varje avvikelse (hash mismatch,
sekvensglapp, bruten länk, signaturproblem, avvikelse mellan huvud och
ankare, hashversion som inte stöds, …). Schemalägg det minst dagligen.

| Alternativ | Effekt |
| --- | --- |
| `--chain` | Kan upprepas: ett tenant-id, `tenant:<id>` eller `global`. Standard: varje kedja som har en huvudrad. |
| `--from`, `--to` | Sekvensfönster (standard: från den första händelsen till huvudet). |
| `--json` | Ett rapportobjekt per kedja. |
| `--fail-fast` | Stoppar vid den första kedjan med avvikelser. |

Utan en signeringsnyckel skriver det ut en varning (`hashes are verified,
signatures are not`) och fortsätter. Varje körning registreras i
`audit_verification_runs` med källan `audit:verify`; en kedja med avvikelser
skickar `ChainViolationDetected`. Exitkod `1` när någon kedja har
avvikelser.

Utdata per kedja: `tenant:<id> intact — <n> events, signatures: valid,
anchor: #<seq>` eller `tenant:<id> VIOLATIONS` följt av en rad per avvikelse
(`<reason> at sequence <n> {details}`).

## `audit:anchor`

```
audit:anchor [--chain=*] [--force]
```

Lagrar det aktuella huvudet (senaste sekvens och hash) för varje kedja i
de(t) konfigurerade ankarlagret/lagren, signerat med den aktiva nyckeln.
Schemalägg det med några minuters mellanrum. Kedjor vars huvud inte har
flyttat sig sedan den senaste ankringen rapporteras som `unchanged` om inte
`--force` anges. Foten anger lagret (`filesystem+database` för en
sammansättning) och antalet skrivna ankare.

Exitkoden är alltid `0`; ett lager som inte kan skrivas till (onåbar disk)
kastar ett undantag, som Artisan omvandlar till en exitkod skild från noll.

## `audit:key:generate`

```
audit:key:generate [--id=k1]
```

Skriver ut en ny 32-byte HMAC-nyckel i det format som `AUDIT_SIGNING_KEYS`
förväntar sig, plus motsvarande rad för `AUDIT_SIGNING_ACTIVE_KEY`.
Nyckel-id måste matcha `[A-Za-z0-9_.-]{1,32}`; ett ogiltigt id avslutas med
`1`. Ingen databasåtkomst. Vid rotation, lägg till den nya posten i den
befintliga listan och flytta den aktiva nyckeln.

## `tenant:run`

```
tenant:run {tenant} {artisan} [--allow-inactive]
```

Kör ett annat Artisan-kommando inuti en tenant-kontext, t.ex.
`php artisan tenant:run acme "reports:generate --month=2026-09"`. Tenanten
anges som id, publikt id, globalt objekt-id eller slug. En avstängd eller
arkiverad tenant avvisas (exit `1`) om inte `--allow-inactive` är satt; en
okänd tenant avslutas med `1`. Annars är exitkoden densamma som det inre
kommandots; den tidigare kontexten återställs efteråt.
