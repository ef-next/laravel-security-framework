<!-- source: docs/en/threat-model.md sha256: caf952ccd030a169aaa07d1cd250683f8a4ea58ee8b759c253dbc8a8e703483c -->
# Hotmodell

*Svensk utgåva — den [engelska versionen](../en/threat-model.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Tillgångar: tenant-data (konfidentialitet, integritet), revisionsspåret (audit trail)
(integritet, tillgänglighet av bevis), tenant- och medlemskapsregister (integritet).

## Angripare och vad ramverket gör åt dem

| Angripare | Förmåga | Relevanta kontroller | Kvarstående risk |
| --- | --- | --- | --- |
| **A1 – Främmande tenant-användare** | Autentiserad användare hos tenant B som skickar förfrågningar med tenant A:s identifierare (header, route, subdomän). | `ResolveTenant` + `EnsureTenantMember`: medlemskapskontroll före controllern; 403 och en audit-händelse vid nekande. `TenantScope` på varje modellfråga. `EnsureTokenTenant` för tenant-bundna API-tokens. | Routes som saknar `tenant`-middlewaregruppen. Globala modeller som borde ha varit tenant-bundna. |
| **A2 – Enumererande utomstående** | Oautentiserade förfrågningar som gissar tenant-identifierare eller resurs-id:n. | Publika id:n med 80 bitars entropi; 404 utan att eka tillbaka identifieraren; negativ cache för okända identifierare; felaktigt formaterade identifierare når aldrig databasen. | Skillnader i svarstid mellan ”okänd tenant” och ”känd tenant, inget medlemskap” (404 mot 403, medvetet). Rate limiting är applikationens ansvar. |
| **A3 – Vårdslös utvecklare** | Glömmer middleware, ett `where`, eller kör ett kommando/jobb utan tenant. | Fail closed-scope (nekar hellre än släpper igenom): ingen tenant ger `TenantContextMissing`, inte en fulltabellsläsning. Oföränderlig (immutable) tenant-kolumn. Tenant-kolumnen exkluderad från mass assignment. Köpropagering som standard. Arkitekturtester. | `DB::table()`, rå SQL, `withoutGlobalScope()`. Dessa är kodgranskningsfrågor; dokumentationen säger det. |
| **A4 – Illvillig insider med applikationsåtkomst** | Kan driftsätta kod eller köra Tinker. | `withoutTenant()` kräver en anledning och registreras i audit-loggen med anroparens plats; bypass propageras aldrig till jobb. | Kan inte förhindras av ett bibliotek. Revisionsspåret är kontrollen. |
| **A5 – Angripare på databasnivå utan signeringsnyckeln** | Kan köra `UPDATE`/`DELETE` efter att ha tagit bort triggrarna; kan räkna om hashar. | Triggrar (första hindret). HMAC-signaturer över varje händelsehash med en nyckel utanför databasen: omräknade hashar klarar inte signaturverifieringen. Kedjehuvud och externa ankare: trunkering upptäcks. | Om angriparen även har signeringsnyckeln (samma secret store, samma värd) kan signaturer förfalskas. Håll nyckeln separat; ankra externt. |
| **A6 – Angripare på databasnivå med signeringsnyckeln** | Allt A5 kan göra plus giltiga signaturer. | Externa ankare i ett lager angriparen inte kan nå (object lock, log sink). `AnchorMismatch`/`AnchorAhead` vid verifiering. | Händelser efter det senaste ankaret kan skrivas om oupptäckt. Ankra ofta. |
| **A7 – Komprometterad applikationsserver** | Full kontroll över PHP-processen, inklusive signeringsnyckeln i minnet. | Inget inuti processen. Externa ankare begränsar fönstret; verifiering från en separat värd upptäcker omskrivningar äldre än det senaste ankaret. | Allt inom ankarintervallet. Separera verifierarens nyckelåtkomst från applikationen om denna angripare är relevant för dig. |
| **A8 – Klockmanipulation / klockskillnad (skew)** | Servrar med fel klocka. | Ordning efter sekvensnummer, aldrig efter tid. `occurredAt` mer än fem minuter i framtiden avvisas. | Bakdaterad `occurredAt` inom det förflutna accepteras och hashas som den anges; sekvensen avslöjar ändå den verkliga ordningen. |
| **A9 – Logförgiftning (log poisoning)** | Angriparen styr innehållet i metadata (t.ex. användarinmatning) för att injicera hemligheter eller enorma payloads. | Sanitizer: nyckelmönster, hemlighetslika värden, gränser för djup/nyckel/längd, kontrolltecken borttagna från request-kontexten. | Hemligheter under okända nycklar som inte matchar ett känt mönster. Logga inte rå indata. |

## Explicit utanför scope

- Autentisering (använd Laravels guards, Sanctum, Passport, SSO).
- Auktorisering inom en tenant (en tenant, tenanten) (Policies, Gates).
- Kontroller på nätverksnivå, WAF, rate limiting.
- Kryptering i vila (encryption at rest), backuper, katastrofåterställning (disaster
  recovery).
- Isolering med en databas per tenant.

## Antaganden

- Applikationen startar (boots) de tre service providers och kör migreringarna.
- Tenant-bundna modeller använder `BelongsToTenant`; globala modeller är medvetet
  globala.
- Routes som rör tenant-data använder `tenant`-middlewaregruppen efter autentisering.
- `audit:verify` och `audit:anchor` är schemalagda och deras fel larmar någon.
- `AUDIT_SIGNING_KEYS` lagras i en hemlighetshanterare (secret manager), inte i databasen
  och inte tillsammans med databasens autentiseringsuppgifter.
