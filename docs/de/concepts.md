<!-- source: docs/en/concepts.md sha256: 07a994b2b1ad95a9ac507c2b3d25af1a88c8a2318f4c1a6b8953930efef0e322 -->
# Konzepte

*Deutsche Ausgabe — die [englische Fassung](../en/concepts.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Die Ideen hinter den drei Paketen, in der Reihenfolge, in der Sie ihnen
begegnen. Jeder Abschnitt endet mit dem, was das Konzept *nicht* tut; die
vollständige Liste steht in [Was es nicht tut](what-it-does-not-do.md).

## Fail closed

Die einzige Konstruktionsregel des Frameworks: fail closed (im Zweifel
verweigern). Fehlt eine sicherheitsrelevante Voraussetzung, wird die Operation
verweigert, niemals nur eingeschränkt fortgesetzt. Eine Abfrage auf ein
tenant-gebundenes Model ohne Tenant wirft eine Exception, statt alles
zurückzugeben; die Produktion verweigert den Start ohne Signaturschlüssel,
statt unsignierte Ereignisse zu schreiben; eine fehlerhaft gebildete Public ID
ist ein 404, noch bevor auf die Datenbank zugegriffen wird; ein
zusammengesetzter Fremdschlüssel, der nicht portierbar wäre, wird schon beim
Schreiben der Migration verweigert, nicht erst, wenn sie auf einer anderen
Datenbank läuft.

Die Konsequenz für Sie: Die Fehlschläge sind laut und früh. Eine
`TenantContextMissing` in einem Test ist nichts, was man abfangen sollte —
sie weist auf einen Codepfad hin, der in Produktion ohne Tenant laufen würde.

## Tenant-Kontext

Ein **Tenant-Kontext** ist der Tenant, für den die aktuelle Arbeitseinheit —
HTTP-Request, Queue-Job, Konsolenbefehl, Closure — handelt. Er wird genau
einmal pro Arbeitseinheit hergestellt und danach wieder gelöscht:

- durch die Middleware `tenant.resolve` aus einem *Identifier* in der Anfrage
  (Header `X-Tenant-Id`, Routenparameter, Session, Subdomain — in der
  konfigurierten Reihenfolge);
- durch die Queue-Integration, aus der Payload eines Jobs, der innerhalb eines
  Kontexts versendet wurde;
- durch `Tenancy::run($tenant, fn () => …)` oder `php artisan tenant:run`, explizit.

Ein Identifier ist kein Beweis. Die Resolution beantwortet nur die Frage
„welcher Tenant ist mit dieser Anfrage gemeint“; `tenant.member` beantwortet
dann „darf dieser Benutzer darin handeln“ (aktive, nicht abgelaufene
Mitgliedschaft; bei Bedarf eine Rolle). Beides geschieht vor dem
Route-Model-Binding, sodass `{invoice}` in einer URL *innerhalb* des Tenants
aufgelöst wird.

Tenant-gebundene Models verwenden `BelongsToTenant`. Ein globaler Scope
filtert jede Abfrage nach dem aktiven Tenant; ohne Kontext wirft der Scope
eine Exception. Beim Erzeugen wird die Tenant-Spalte gesetzt; beim
Aktualisieren und Löschen wird geprüft, dass der Datensatz zum aktiven Tenant
gehört, sowohl in PHP als auch erneut im SQL-`WHERE`, sodass `saveQuietly()`
oder ein entfernter Event-Listener nichts daran ändern. Einen Datensatz zu
einem anderen Tenant zu verschieben wird verweigert.

Tenantübergreifende Arbeit ist möglich, aber explizit:
`Tenancy::withoutTenant('nightly aggregation', fn () => …)` hebt den Scope
innerhalb der Closure auf, löst ein Event mit dem Grund und der aufrufenden
Codestelle aus, und das Audit-Paket zeichnet es auf. Innerhalb eines solchen
Blocks hält `Model::forTenant($id)` Abfragen explizit.

*Tut nicht:* `DB::table()`, rohes SQL oder Models ohne den Trait scopen;
entscheiden, was ein Mitglied *innerhalb* eines Tenants tun darf — das ist
Ihre Policy-Schicht.

## Zusammengesetzte Fremdschlüssel

`$table->tenantId()` fügt einer tenant-gebundenen Tabelle
`UNIQUE (tenant_id, id)` hinzu; `$table->tenantForeignId('invoice_id',
'invoices')` auf einer Kindtabelle erzeugt einen Fremdschlüssel auf
`(tenant_id, invoice_id)`, der auf `(tenant_id, id)` verweist. Die Datenbank
selbst verweigert dann eine Position, die auf die Rechnung eines anderen
Tenants verweist — unabhängig davon, was sie geschrieben hat. Das ist die
einzige Schutzmaßnahme, die unterhalb von Eloquent greift.

*Tut nicht:* tenantübergreifende *Lesezugriffe* über rohes SQL verhindern;
`ON DELETE SET NULL` erlauben (ein zusammengesetzter Schlüssel kann nicht nur
eine Spalte auf NULL setzen; verwenden Sie `nullable: true` mit Cascade oder
Restrict und lösen Sie die Verknüpfung per Update).

## Public und Global IDs

Interne Auto-Increment-IDs verraten das Volumen und laden zur Enumeration
ein. `HasPublicId` gibt einem Model eine nicht erratbare, lesbare ID mit
einem Typ-Präfix (`inv_7f3k9zq2m4x8c1bt`, 80 Bit Zufälligkeit), beim Erzeugen
vergeben, unveränderlich, automatisch vom Route-Model-Binding verwendet.
`HasGlobalObjectId` erweitert sie zu einem Namen, der dienstübergreifend
eindeutig ist: `billing:invoice:inv_7f3k9zq2m4x8c1bt` — die Form, die andere
Dienste, Audit-Trails und Support-Tickets verwenden sollten.

*Tut nicht:* irgendetwas autorisieren. Eine ID zu kennen ist keine
Berechtigung; die Tenant-Middleware und Ihre Policies entscheiden weiterhin.

## Manipulationssicher nachweisbares Audit-Log

Jedes Audit-Ereignis gehört zu einer **Kette** — eine pro Tenant plus eine
`global`-Kette — und trägt eine Sequenznummer, den Hash seines Vorgängers,
seinen eigenen Hash über eine kanonische JSON-Kodierung von allem, was es
beschreibt (Akteur, Aktion, Ressource, Ergebnis, Metadaten, Request-Kontext,
Zeitstempel), sowie eine HMAC-Signatur dieses Hashes mit einem Schlüssel, der
außerhalb der Datenbank liegt.

Drei Schichten machen Änderungen sichtbar:

1. **Datenbank-Trigger** verweigern `UPDATE` und `DELETE` auf Ereignissen und
   Ankern. Ein Superuser kann sie löschen — deshalb sind sie nicht die
   einzige Schicht.
2. **Hash-Kette und Signatur.** Das Umschreiben eines Ereignisses bricht den
   Hash jedes späteren Ereignisses; alle Hashes neu zu berechnen ist für
   jemanden mit Datenbankzugriff möglich, aber die Signaturen brauchen den
   Schlüssel. `audit:verify` durchläuft die Ketten und meldet jede
   Inkonsistenz.
3. **Anker.** `audit:anchor` schreibt den aktuellen Kopf jeder Kette (Sequenz
   und Hash) in einen Anker-Store. Ein Store *außerhalb* der Datenbank —
   Objektspeicher mit Object Lock, ein WORM-Log — ist das, was vollständige
   Umschreibungen und Kürzungen erkennbar macht: Der Verifier vergleicht die
   Kette mit dem Anker.

Der Verifier und `security:doctor` sind Ihr Weg, es *herauszufinden*.
Verdrahten Sie `ChainViolationDetected` mit Ihrem Alerting.

*Tut nicht:* Manipulation verhindern (sie macht sie nachweisbar); ohne den
Scheduler signieren oder ankern; Metadaten privat halten (sie werden
bereinigt, nicht verschlüsselt); Löschung lösen (Ereignisse sind unveränderlich
— halten Sie personenbezogene Daten davon fern oder pseudonymisieren Sie sie).

## Akteure, Metadaten, Sanitising

Der Akteur ist der authentifizierte Benutzer (oder das eigene
`toAuditActor()` des Models), `anonymous` für nicht authentifizierte
Anfragen, `system('console')` außerhalb von HTTP, oder was auch immer
`->by()` angibt. Metadaten sind, was auch immer Sie an `->with()` übergeben —
nachdem der Sanitiser geheim aussehende Schlüssel und Werte geschwärzt
(`password`, `token`, JWT-Formen, Kartennummern…), Identifier pseudonymisiert
(`email` → `email_hash`) und Tiefe, Größe und Stringlänge begrenzt hat.
Übergeben Sie die Felder, um die es bei dem Ereignis geht, niemals
`$request->all()`.

## Garantie → Bedingung → Restrisiko → Diagnose

Jedes Versprechen in dieser Dokumentation hat diese Form, denn eine
Sicherheitseigenschaft, die „meistens“ gilt, ist keine Eigenschaft. Die
Garantientabelle der README nennt für jede die Bedingung; `security:doctor`
prüft die Bedingungen, die er prüfen kann; das Bedrohungsmodell nennt, was
übrig bleibt. Wenn Sie das Framework erweitern, behalten Sie diese Form bei.

## Glossar

| Begriff | Bedeutung |
| --- | --- |
| Tenant-Kontext | der Tenant, für den die aktuelle Arbeitseinheit handelt |
| Resolver | findet einen Tenant-*Identifier* in einer Anfrage |
| Locator | bildet einen Identifier auf einen Tenant-Datensatz ab (ID, Status, Public ID, Slug) |
| Mitgliedschaft | die Beziehung eines Benutzers zu einem Tenant: Rolle, Status, Ablauf |
| Kette | die Folge der Audit-Ereignisse eines Tenants (oder `global`) |
| Kopf | die letzte Sequenz und der letzte Hash einer Kette, gehalten in `audit_chain_heads` |
| Anker | eine Kopie eines Kopfes, signiert, (idealerweise) außerhalb der Datenbank gespeichert |
| Verifikationslauf | ein aufgezeichneter Durchlauf von `audit:verify` oder des Doctors über eine Kette |
| Public ID | `prefix_body`, nicht erratbarer Identifier pro Model |
| Global Object ID | `service:type:public_id`, dienstübergreifend eindeutig |
| Strict-Profil | `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` beim Start erzwungen, `security:doctor --strict` als Deployment-Gate |
