<!-- source: docs/en/operations/key-rotation.md sha256: c12de75137df303747f5af56eb6c28219ab7d499b7c7b35c8b539d910773dbf3 -->
# Nyckelrotation

*Svensk utgåva — den [engelska versionen](../../en/operations/key-rotation.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Audit-paketet signerar varje händelse och varje ankare med en HMAC-nyckel från en
**nyckelring**. Rotation är designad för att vara odramatisk: lägg till en nyckel, gör den
aktiv, driftsätt. Inget omsigneras, inget migreras, och gamla signaturer fortsätter att
verifieras så länge deras nyckel finns kvar i ringen.

## Nyckelringen

```dotenv
AUDIT_SIGNING_KEYS="k1:base64:<32+ random bytes>,k2:base64:<32+ random bytes>"
AUDIT_SIGNING_ACTIVE_KEY=k2
```

- Poster är kommaseparerade, var och en `<id>:base64:<key>`. Id:n matchar
  `/^[A-Za-z0-9_.-]{1,32}$/`; nycklar är minst 32 byte efter avkodning.
- **Den aktiva nyckeln signerar.** Alla andra nycklar i ringen används bara för att
  verifiera.
- Varje händelse och ankare lagrar det `key_id` den signerades med, så verifieraren väljer
  rätt nyckel på egen hand.
- En felaktigt formaterad post, en nyckel som är för kort, ett aktivt id som inte finns i
  ringen, eller nycklar utan ett aktivt id → `InvalidSigningKey` vid uppstart. Ramverket
  startar inte med en trasig nyckelring; det faller inte tillbaka till ett osignerat läge.

Generera en nyckel:

```bash
php artisan audit:key:generate --id=k2
# prints two lines to append / set:
#   AUDIT_SIGNING_KEYS="k2:base64:…"
#   AUDIT_SIGNING_ACTIVE_KEY=k2
```

Kommandot skriver ut, det skriver inte till fil. Lägg värdet i din hemlighetshanterare
(secret manager); applikationen läser det från miljön vid uppstart.

## Var nyckeln finns

Nyckeln får inte vara läsbar från de platser där databasen är läsbar. Detta är den enskilt
viktigaste operativa egenskapen hos audit-loggen: en angripare med databasåtkomst *och*
nyckeln kan skriva om historiken konsekvent fram till det senaste externa ankaret, utan att
signaturerna avslöjar det. Konkret:

- Hemlighetshanterare (Vault, AWS Secrets Manager, GCP Secret Manager, …) → miljövariabel
  vid uppstart. Inte i databasen, inte i en konfigurationstabell, inte i en fil som
  webbservern kan servera.
- En **annan vault-sökväg / IAM-policy** än databasens autentiseringsuppgifter.
  Applikationsvärden håller båda vid körning — det är oundvikligt — men en DBA, en
  backup-operatör eller en läckt databasdump får inte följa med nyckeln.
- Verifierarvärden (där `audit:verify` körs under en incident) får ringen skrivskyddad
  (read-only); den behöver aldrig den aktiva nyckeln för att signera.
- `AUDIT_PSEUDONYM_KEY` (peppret för hashade identifierare i metadata) är en andra
  hemlighet med samma regler. Osatt härleds den från `APP_KEY`; sätt den separat i
  produktion så att rotation av `APP_KEY` inte ändrar pseudonymer.

## Schemalagd rotation

Rotera enligt ett schema (årligen är vanligt; din policy avgör) och vid varje misstanke.
Proceduren är densamma:

1. **Generera** en ny nyckel: `php artisan audit:key:generate --id=k3`.
2. **Lägg till** den i `AUDIT_SIGNING_KEYS` i hemlighetshanteraren. Behåll varje befintlig
   post. Ändra inte den aktiva nyckeln än.
3. **Driftsätt** (eller starta om) så att varje instans känner till `k3`. Med flera
   instanser, vänta tills alla kör med den nya ringen — en instans som signerar med `k3`
   medan en annan inte har laddat den skulle fortfarande skriva giltiga händelser, men den
   andra instansens doctor och verifierare skulle rapportera `signature_key_unknown` tills
   den startar om.
4. **Aktivera**: sätt `AUDIT_SIGNING_ACTIVE_KEY=k3`, driftsätt igen.
5. **Verifiera**: `php artisan security:doctor --strict` (kontrollen `signing_key`
   rapporterar `n key(s) in the ring, active: k3`) och `php artisan audit:verify`.
   Händelser efter bytet bär `key_id = k3`; tidigare verifieras fortfarande med `k2`.
6. **Ankra** en gång explicit: `php artisan audit:anchor --force`, så att det första
   ankaret signerat med den nya nyckeln finns direkt.

Två driftsättningar istället för en är medvetet: en ring som får en ny nyckel är alltid
säker att rulla ut gradvis; en ring vars aktiva nyckel ändras är bara säker när varje
instans känner till den nyckeln.

## Avveckla en nyckel

Ta bort en nyckel från ringen bara när **ingen händelse eller ankare signerad med den
längre finns inom din lagringsperiod (retention period)**. Verifieraren rapporterar varje
händelse vars `key_id` inte finns i ringen som `signature_key_unknown`; det är en
avvikelse, och `audit:verify` kommer att avsluta med kod 1 så länge sådana händelser finns.

Innan du tar bort `k1`:

```sql
SELECT chain_key, MIN(occurred_at), MAX(occurred_at), COUNT(*)
FROM audit_events WHERE key_id = 'k1' GROUP BY chain_key;
SELECT COUNT(*) FROM audit_anchors WHERE key_id = 'k1';
```

Om båda är tomma (eller varje rad ligger utanför lagringsperioden och har rensats bort av
din lagringsprocess), ta bort posten, driftsätt, och kör `audit:verify` en gång till. En
avvecklad nyckel bör ändå arkiveras offline — en revisor kan be dig verifiera ett
exporterat intervall igen flera år senare.

## Komprometterad nyckel

Betrakta en nyckel som komprometterad om den fanns i en läckt dump, på en förlorad laptop,
i en loggrad, i en CI-artefakt, eller innehades av någon som slutat och tagit den med sig.

1. **Rotera omedelbart** (steg 1–6 ovan, i ett svep om nödvändigt: att lägga till `k3` och
   aktivera den i en enda driftsättning är acceptabelt under press — det korta fönstret av
   `signature_key_unknown` på en instans som ännu inte startats om är att föredra framför
   ytterligare en timme med en komprometterad aktiv nyckel).
2. **Ta inte bort den komprometterade nyckeln än.** Händelserna den signerade är
   fortfarande bevis; frågan är om de manipulerades.
3. **Verifiera från en ren värd**: kör `audit:verify --json` på en maskin som aldrig
   innehaft den komprometterade nyckeln, som läser databasen och det externa ankarlagret.
   Signaturer gjorda med `k2` verifieras fortfarande — poängen med körningen är *ankarna*:
   varje externt ankare skrivet före komprometteringen är ett signerat utlåtande om kedjan
   som angriparen inte kunde ha förfalskat i det lagret. Om kedjan fortfarande matchar
   dessa ankare är historiken fram till det sista ankaret före komprometteringen intakt.
4. **Avgränsa exponeringen**: från det senaste ankaret du litar på fram till rotationen är
   händelserna ”signerade med en nyckel en angripare också hade”. De är inte nödvändigtvis
   falska — men de kan inte *bevisas* sanna enbart genom signaturen. Anteckna det fönstret
   i dina incidentanteckningar; ankarna och applikationsloggarna från den perioden är det
   styrkande beviset.
5. Fortsätt med [incidenthantering](incident-response.md) om någon kontroll misslyckas.

## Rotera `APP_KEY`

`APP_KEY` är Laravels krypteringsnyckel, inte audit-signeringsnyckeln. Att rotera den
påverkar inte signaturer. Den påverkar däremot `AUDIT_PSEUDONYM_KEY` när den är osatt
(härledd från `APP_KEY`): identifierare hashade in i metadata före och efter rotationen
skulle inte längre matcha varandra. Sätt `AUDIT_PSEUDONYM_KEY` explicit *innan* du roterar
`APP_KEY` för första gången, och håll den stabil.

## Checklista

- [ ] nycklar genererade med `audit:key:generate`, ≥ 32 byte, id:n du kan läsa i en rapport
      (`k2026-01`, inte `key`)
- [ ] nyckelring och aktiv nyckel i hemlighetshanteraren; databasens
      autentiseringsuppgifter på en annan sökväg
- [ ] rotation i två driftsättningar (lägg till, sedan aktivera) på installationer med
      flera instanser
- [ ] `security:doctor --strict` och `audit:verify` gröna efter varje steg
- [ ] nyckeln borttagen först efter lagringsperioden; arkiverad offline därefter
- [ ] `AUDIT_PSEUDONYM_KEY` satt explicit i produktion

Relaterat: [Audit-guide](../guides/auditing.md) · [Produktionsguide](../guides/production.md) ·
[Förtroendegränser](../diagrams/trust-boundaries.md) · [Konfiguration → signering](../reference/configuration.md)
