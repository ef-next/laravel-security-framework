<!-- source: docs/en/operations/incident-response.md sha256: f608b6da99ca434f160ee6f3cbf5bbac829c80ea0470f41bef77a064a12a6586 -->
# Incidenthantering

*Svensk utgåva — den [engelska versionen](../../en/operations/incident-response.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Vad du ska göra när verifieraren rapporterar en avvikelse, doctorn rapporterar att en
garanti är borta, eller du har anledning att tro att audit-loggen eller en tenant-gräns har
attackerats. Ramverkets uppgift under en incident är att berätta **vad som ändrades, var,
och vad som fortfarande är pålitligt**; den här sidan är proceduren kring det.

Läs den här en gång innan du behöver den. De tre första stegen handlar om att *inte
förstöra bevis*.

## 0. Säkra bevis först

Innan någon åtgärd, omstart, migrering, eller ”jag kollar bara snabbt i produktion”:

1. **Ta en ögonblicksbild (snapshot) av audit-databasen** (eller hela databasen om
   audit-tabellerna delar den): en konsekvent dump eller en ögonblicksbild på
   lagringsnivå. Notera tiden.
2. **Frys ankarlagret**: rotera, gallra eller omkonfigurera inte bucketen. Om din
   leverantör erbjuder en legal hold, applicera den. Exportera listningen
   (`AUDIT_ANCHOR_PATH/<chain>/*.json`) med objektversioner och tidsstämplar.
3. **Kopiera applikations- och webbserverloggarna** för den aktuella tidsperioden,
   inklusive request-id:n — `audit_events.request_id` och `correlation_id` länkar samman
   de två.
4. **Rotera inte signeringsnyckeln än** om du inte redan vet att den läckt (se då
   [nyckelrotation → komprometterad nyckel](key-rotation.md#komprometterad-nyckel) och kom
   sedan tillbaka). Signaturerna under den nuvarande nyckeln är en del av bevisen.
5. Arbeta mot ögonblicksbilden där du kan. Varje kommando nedan är skrivskyddat (read-only), men ju färre
   händer i produktion under triage, desto bättre.

## 1. Skaffa dig hela bilden

Från en värd du litar på — helst en som kan läsa databasen och ankarlagret men som inte är
en applikationsserver:

```bash
php artisan audit:verify --json > verify-$(date -u +%Y%m%dT%H%M%SZ).json
php artisan security:doctor --json --strict > doctor-$(date -u +%Y%m%dT%H%M%SZ).json
```

`audit:verify` utan `--chain` går igenom varje kedja från sekvens 1; på en stor
installation tar det ett tag, vilket är okej — du vill ha den fullständiga rapporten, inte
ett stickprov. Behåll JSON-filerna; de är protokollet över vad systemet sa vid den
tidpunkten.

Rapporten per kedja:

```json
{
  "chain_key": "tenant:0192a7c3-…",
  "from_sequence": 1,
  "to_sequence": null,
  "events_checked": 48213,
  "last_hash": "…",
  "intact": false,
  "signatures": "valid",
  "anchor": { "chain_key": "…", "sequence": 48190, "event_hash": "…", "key_id": "k2", "signature": "…", "anchored_at": "…" },
  "violations": [
    { "reason": "event_hash_mismatch", "sequence": 47001, "event_id": "01J…", "expected_event_hash": "…", "stored_event_hash": "…" }
  ]
}
```

## 2. Läs avvikelserna

Varje avvikelse har en `reason`, en `sequence` och (oftast) ett `event_id`. Anledningen
(reason) talar om vilken typ av manipulation du tittar på:

| Anledning (reason) | Vad det betyder | Var du ska titta härnäst |
| --- | --- | --- |
| `event_hash_mismatch` | raden vid denna sequence ger inte längre sin lagrade hash: **en rad redigerades** | den raden; `recorded_at` jämfört med databasens ändringsspårning; vem som hade `UPDATE` på `audit_events` — triggrarna borde ha nekat det, så kontrollera även `append_only_triggers` i doctor-rapporten |
| `previous_hash_mismatch` | den här radens länk pekar inte på den faktiska föregående raden: **en rad byttes ut eller togs bort före den** | raderna mellan den senaste korrekta sequence och den här |
| `sequence_gap` / `missing_predecessor` | ett sekvensnummer saknas: **rader togs bort** | som ovan; ett gap allra sist är trunkering (se `head_mismatch`) |
| `signature_invalid` | hashen är konsekvent men signaturen är det inte: **omskriven utan nyckeln** (eller med en annan nyckel under samma id) | angriparen hade databasåtkomst men inte nyckeln — det goda fallet; raderna är ändå felaktiga |
| `signature_key_unknown` | signerad med ett key id som inte finns i ringen | tog någon bort en nyckel från `AUDIT_SIGNING_KEYS`? en konfigurationsincident, inte nödvändigtvis en attack; återställ nyckeln och verifiera på nytt |
| `signature_missing` | ingen signatur på en händelse trots att ringen är konfigurerad | händelser skrivna medan ringen var tom (ett felkonfigurationsfönster), eller en omskrivning som tappade signaturen |
| `head_mismatch` | `audit_chain_heads` stämmer inte överens med den sista händelsen: **kedjan trunkerades eller huvudet manipulerades** | jämför huvudet med det senaste externa ankaret; ankaret talar om den verkliga längden |
| `anchor_ahead` | ett ankare hävdar en sequence som kedjan inte längre har: **trunkering efter ankaret** | allt mellan kedjans nuvarande slut och ankarets sequence är borta; ankaret bevisar att det fanns |
| `anchor_mismatch` | kedjan vid ankarets sequence har en annan hash än den ankaret registrerade: **omskriven konsekvent — med nyckeln — före det senaste ankaret** | signeringsnyckeln är komprometterad, eller en angripare på databasnivå hade den; det externa ankaret är ditt enda bevis. Betrakta nyckeln som komprometterad |
| `unsupported_hash_version` | en händelse hävdar en hash-version ramverket inte känner till | en nedgraderad driftsättning som läser nyare rader, eller en manipulerad rad |

Två egenskaper hos rapporten hjälper till att avgränsa omfattningen:

- **`signatures: "valid"` tillsammans med avvikelser** betyder att angriparen inte hade
  nyckeln. Allt de ändrade syns som en hash- eller signaturavvikelse; inget är dolt.
- **`anchor` är det senaste ankaret verifieraren hittade** (externt lager först, databasen
  som fallback). Allt vid eller under `anchor.sequence` som fortfarande verifieras är
  bekräftat av en oberoende kopia; allt över det är bara bekräftat av kedjan och
  signaturerna.

## 3. Hitta den senaste pålitliga punkten

De externa ankarna är förtroendegränsen. För den berörda kedjan:

```bash
# list the anchors in the external store (path = AUDIT_ANCHOR_PATH, ':' in the chain key becomes '_')
aws s3 ls s3://<bucket>/audit-anchors/tenant_0192a7c3-…/
```

Varje objekt är ett JSON-ankare (`sequence`, `event_hash`, `key_id`, `signature`,
`anchored_at`). Verifiera sedan kedjan **fram till** varje ankare, nyaste först, tills en
klarar sig:

```bash
php artisan audit:verify --chain=tenant:0192a7c3-… --to=48190 --json
php artisan audit:verify --chain=tenant:0192a7c3-… --to=47500 --json
```

Det högsta ankaret vars intervall verifieras intakt och vars `event_hash` motsvarar
kedjans hash vid den sequence är den **senaste pålitliga punkten**. Allt fram till den är
bevisat av en oberoende kopia; allt efter den är vad incidenten handlar om. Om
*databasens* ankare inte stämmer överens med de *externa* har databasens ankare
manipulerats — notera det, det berättar vad angriparen kunde nå.

Om inga externa ankare finns (en installation med enbart databasen) är den senaste
pålitliga punkten där signaturerna slutar vara konsekventa — och om nyckeln också var
tillgänglig för angriparen finns det ingen bevisbar punkt alls. Det är det scenario
`security:doctor --strict` och `AUDIT_REQUIRE_EXTERNAL_ANCHOR` finns för att förhindra;
skriv in det i incidentrapporten.

## 4. Avgränsa exponeringen

Med den senaste pålitliga punkten känd:

1. **Vilka tenants** (en tenant, tenanten): varje kedja i rapporten med `intact: false`.
   En avvikelse i en tenants kedja påverkar inte en annans; `global`-kedjan täcker
   tenant-lösa händelser (medlemskapsändringar, scope-bypasser, nekanden för okända
   tenants).
2. **Vilket tidsfönster**: `occurred_at` för den första avvikelsen fram till
   upptäcktstillfället. `audit_verification_runs` berättar när kedjan senast verifierades
   intakt — manipulationen skedde efter den körningen.
3. **Vem som kunde**: avvikelserna avgränsar angriparens åtkomst:
   - hash-avvikelser, giltiga signaturer → databasens skrivåtkomst, ingen nyckel;
   - `anchor_mismatch` eller på annat sätt konsekventa omskrivningar → databasen *och*
     nyckeln;
   - borttagna triggrar (`append_only_triggers` FAIL) → DDL-rättigheter;
   - databasens ankare omskrivna men de externa intakta → ingen åtkomst till bucketens
     raderingsbehörighet (Object Lock höll).
4. **Vad loggen säger om sig själv**: fråga kedjan runt tidsfönstret — händelserna *före*
   manipulationen visar ofta aktören:

```sql
SELECT sequence, occurred_at, actor_type, actor_id, action, resource_type, resource_id, request_id
FROM audit_events
WHERE chain_key = 'tenant:0192a7c3-…' AND sequence BETWEEN 47990 AND 48010
ORDER BY sequence;
```

`tenant.scope_bypassed`- och `tenant.membership.*`-händelser i `global`-kedjan, samt
toppar i `tenant.access_denied`, är de som ska läsas först.

## 5. Begränsa

I den här ordningen, varje steg först efter att det föregående är klart och noterat:

1. **Återkalla den åtkomst som gjorde det möjligt**: databasens autentiseringsuppgifter
   som läckte eller var överprivilegierade, kontot som körde DDL, driftsättningen som
   stängde av `AUDIT_ENFORCE_APPEND_ONLY`. Kör om audit-migreringens triggerinstallation
   om triggrarna togs bort (`php artisan migrate:refresh --path=…` är **inte** verktyget —
   det tar bort tabeller; återskapa triggrarna med de satser migreringen använder för din
   drivrutin, eller återställ från ögonblicksbilden och spela om (replay)).
2. **Rotera signeringsnyckeln** om analysen i steg 4 säger att angriparen hade den, eller
   om du inte kan utesluta det ([nyckelrotation](key-rotation.md#komprometterad-nyckel)).
3. **Rotera `AUDIT_PSEUDONYM_KEY`** bara om den läckte; den skyddar inte integritet, den
   skyddar identifierare i metadata.
4. **Suspendera berörda tenants** om komprometteringen är ett tenant-gränsproblem snarare
   än ett loggproblem: `status = suspended` på tenant-raden gör att varje förfrågan till
   den omedelbart blir en 403 (cachen invalideras vid sparning); jobb som redan köats för
   den körs ändå om du inte också pausar kön.
5. **”Reparera” inte kedjan.** Det finns ingen understödd operation som skriver om
   `audit_events`, och du bör inte vilja ha någon: avvikelserna är protokollet över
   incidenten. Kedjan fortsätter efter det manipulerade intervallet;
   `audit:verify --from=<first sequence after the incident>` verifierar resten.

## 6. Dokumentera

Incidentrapporten bör innehålla, som minimum:

- utdata från `audit:verify --json` och `security:doctor --json --strict` från steg 1,
  med tidsstämplar;
- det senaste pålitliga ankaret per berörd kedja (sequence, hash, lager, objektversion);
- avvikelsetabellen med din tolkning per reason;
- exponeringen (tenants, tidsfönster, åtkomstnivå);
- vad som återkallades, roterades, återställdes, och när;
- sequence från vilken kedjan anses ren igen.

Skriv en sammanfattning av det i audit-loggen själv, från `global`-kedjan, så att nästa
verifieringskörning bär med sig erkännandet (acknowledgement):

```php
Audit::action('security.incident.recorded')
    ->global()
    ->on('incident', 'INC-2026-014')
    ->with(['chains' => ['tenant:0192a7c3-…'], 'last_trusted_sequence' => 48190, 'reason' => 'event_hash_mismatch'])
    ->bySystem('incident-response')
    ->record();
```

## 7. Efter incidenten

- Behåll ögonblicksbilden, ankarexporten och JSON-rapporterna under din lagringsperiod;
  det är vad en revisor kommer att be om.
- Om den senaste pålitliga punkten var äldre än ditt ankarintervall skötte
  schemaläggaren inte sitt jobb före incidenten; fixa övervakningen
  ([övervakning](monitoring.md)) innan något annat.
- Om incidenten var möjlig eftersom nyckeln och databasen var nåbara från samma plats,
  dela upp dem nu ([förtroendegränser](../diagrams/trust-boundaries.md)).
- Om det var ett tenant-gränsproblem, lägg till det saknade testet i applikationens
  testsvit innan fixen går ut; `TenantContextMissing` och testet för läckage mellan
  tenants i [AGENTS.md](../../../AGENTS.md) är minimum.

## När det inte är en attack

De flesta första avvikelserna är operativa:

| Symptom | Vanlig orsak | Åtgärd |
| --- | --- | --- |
| `signature_key_unknown` på gamla händelser efter en driftsättning | en nyckel togs bort från `AUDIT_SIGNING_KEYS` för tidigt | lägg tillbaka den; se [avveckla en nyckel](key-rotation.md#avveckla-en-nyckel) |
| `anchor_ahead` efter en återställning | databasen återställdes från en backup äldre än det senaste ankaret | ankaret har rätt: återställningen förlorade händelser. Notera gapet; kedjan fortsätter från det återställda huvudet. Återställ databasen och ankarlagret *tillsammans* nästa gång |
| `head_mismatch` direkt efter en krasch | writerns transaktion committade händelsen men processen dog innan huvudet uppdaterades | ramverket uppdaterar båda i en och samma transaktion, så detta borde inte hända; om det ändå gör det, öppna ett issue med rapporten och behåll ögonblicksbilden |
| `signature_missing` för ett intervall | händelser skrevs medan ringen var tom (en driftsättning utan hemligheten) | intervallet är hashat och länkat men osignerat; notera det, fixa driftsättningen, och låt doctorns `signing_key`-kontroll bevaka det från och med nu |
| `unsupported_hash_version` | en äldre ramverksversion som läser rader skrivna av en nyare | rulla framåt (roll forward) |

Även då: kör den fullständiga verifieringen, behåll rapporten, och låt avvikelsen stå
kvar. Loggen ska minnas.

Relaterat: [Övervakning](monitoring.md) · [Nyckelrotation](key-rotation.md) ·
[Audit-skrivväg och verifiering](../diagrams/audit-write-and-verification.md) ·
[Felsökning](../guides/troubleshooting.md)
