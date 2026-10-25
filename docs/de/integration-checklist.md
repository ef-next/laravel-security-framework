<!-- source: docs/en/integration-checklist.md sha256: 18810320758af195a9f8bdc338a11123c08b41dfe93e32c09726a33bce118feb -->
# Integrations-Checkliste

*Deutsche Ausgabe — die [englische Fassung](../en/integration-checklist.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Arbeiten Sie dies vor dem ersten Deployment durch und erneut vor jedem
Release, das Tenancy oder Auditing betrifft.

## Einrichtung

- [ ] `composer require "efnext/security-framework:^1.0@beta"` (Pre-Release: Stability-Flag erforderlich, siehe README); die drei Provider werden automatisch erkannt (Auto-Discovery).
- [ ] Konfiguration veröffentlicht und geprüft: `tenant.php`, `audit.php`, `addressing.php`.
- [ ] `SECURITY_SERVICE_NAME` auf den Namen dieses Dienstes gesetzt (Global Object IDs).
- [ ] `tenant.memberships.user_key_type` stimmt mit dem Primärschlüssel Ihrer users-Tabelle überein.
- [ ] Migrationen in jeder Umgebung ausgeführt; die Audit-Migration war erfolgreich (sie bricht bei nicht unterstützten Treibern oder fehlenden Trigger-Rechten ab).
- [ ] Ausgabe von `php artisan audit:key:generate` im Secret-Manager gespeichert; `AUDIT_SIGNING_KEYS` und `AUDIT_SIGNING_ACTIVE_KEY` in jeder Umgebung vorhanden.
- [ ] `audit.anchoring.stores` enthält einen lesbaren Store außerhalb der Datenbank (`filesystem` auf einem Bucket mit Object Lock), an erster Stelle gelistet; `log` ist eine nützliche Ergänzung, aber nur schreibend.
- [ ] `AUDIT_REQUIRE_EXTERNAL_ANCHOR=true` in Produktion, sobald dieser Store existiert, damit ein Deployment ohne ihn nicht starten kann.
- [ ] `php artisan security:doctor --strict` besteht in jeder Umgebung, die produktionsähnlich sein soll, und die Deployment-Pipeline führt ihn nach jedem Rollout aus und stoppt bei Exit-Code 1 (strict = High-Security-Profil: Warnungen gelten als Fehlschläge, und `AUDIT_REQUIRE_EXTERNAL_ANCHOR` muss aktiv sein).

## Models

- [ ] Jedes tenant-gebundene Model verwendet `BelongsToTenant`; seine Tabelle wurde mit `$table->tenantId()` angelegt.
- [ ] Relationen zwischen tenant-gebundenen Tabellen verwenden `$table->tenantForeignId()` / `tenantForeignUuid()` / `tenantForeignUlid()` (zusammengesetzte Fremdschlüssel), nicht das einfache `foreignId()`.
- [ ] Optionale Relationen sind `tenantForeignId(..., nullable: true)` mit `cascadeOnDelete()`/`restrictOnDelete()`; kein `nullOnDelete()` auf einem zusammengesetzten Tenant-Schlüssel (wird zur Migrationszeit verweigert).
- [ ] Jedes Model, das bewusst global ist, wird in einem Kommentar oder einem Architektur-Test aufgeführt.
- [ ] Kein tenant-gebundenes Model führt die Tenant-Spalte in `$fillable` auf.
- [ ] Models, die in URLs exponiert werden, verwenden `HasPublicId` und `$table->publicId()`.
- [ ] Das User-Model verwendet `HasTenantMemberships`.

## Routen

- [ ] Jede Route, die Tenant-Daten liest oder schreibt, liegt innerhalb von `Route::middleware(['auth…', 'tenant'])`.
- [ ] Privilegierte Routen fügen `tenant.role:<roles>` hinzu.
- [ ] API-Routen, die mit Sanctum-Tokens bedient werden, fügen `tenant.token` hinzu (und erwägen `tenant.tokens.require_binding=true`).
- [ ] Tenant-Auswahl/-Wechsler verwendet `SessionTenant::switchTo()`; nichts anderes schreibt den Session-Schlüssel.
- [ ] Health-Checks, Webhooks und andere tenant-lose Routen berühren keine tenant-gebundenen Models (sie würden `TenantContextMissing` erhalten).

## Hintergrundarbeit

- [ ] Jobs, die für einen festen Tenant laufen müssen, implementieren `TenantAware` und verwenden `TenantContextMiddleware`.
- [ ] Geplante Befehle, die Tenant-Daten berühren, laufen über `tenant:run <tenant> <command>` oder wickeln ihre Logik in `Tenancy::run()` ein.
- [ ] Tenantübergreifende Wartung verwendet `Tenancy::withoutTenant('reason', …)` mit einem aussagekräftigen Grund; der Block ist so klein wie möglich.
- [ ] Octane: nichts anderes registriert einen langlebigen Tenant-Zustand; die Provider löschen den Kontext bei Request-Ende.

## Auditing

- [ ] Sicherheitsrelevante Geschäftsaktionen rufen `Audit::action(…)->record()` auf (Erzeugen/Aktualisieren/Löschen wichtiger Ressourcen, Exporte, Berechtigungsänderungen, Logins und Fehlschläge).
- [ ] Audit-Schreibvorgänge, die atomar mit der Geschäftsänderung sein müssen, laufen innerhalb von `Audit::transaction()`; die Closure enthält keine E-Mails, API-Aufrufe oder andere nicht-transaktionalen Seiteneffekte.
- [ ] Tenants werden mit `Audit::prepareChain($tenantId)` vorbereitet, wenn ihre ersten Audit-Ereignisse unter Last innerhalb größerer Transaktionen geschrieben werden.
- [ ] Metadaten enthalten niemals rohe Request-Eingaben; Geheimnisse werden nicht übergeben; personenbezogene Daten laufen über pseudonymisierte Schlüssel oder werden über die Public ID referenziert.
- [ ] `audit:verify` geplant (z. B. stündlich), `audit:anchor` geplant (z. B. alle fünf Minuten) und `security:doctor` geplant oder ins Monitoring eingebunden; ein fehlschlagender Lauf alarmiert jemanden.
- [ ] `ChainViolationDetected` hat einen Listener, der alarmiert.
- [ ] Aufbewahrungs- und Löschrichtlinie dokumentiert (siehe „Was es nicht tut“).

## Code-Review-Regeln

- [ ] Grep nach `withoutGlobalScope(` und `TenantScope::class`: jeder Treffer braucht eine Begründung.
- [ ] Grep nach `DB::table(` und `DB::select(` gegen tenant-gebundene Tabellen: jeder Treffer braucht einen expliziten Tenant-Filter.
- [ ] Grep nach `withoutTenant(`: jeder Treffer hat einen konkreten Grund-String und eine kleine Closure.
- [ ] Grep nach `->forceFill(` auf tenant-gebundenen Models: keiner berührt die Tenant-Spalte.

## Tests, die Sie selbst haben sollten

- [ ] Eine Anfrage von einem Mitglied von Tenant B an eine Ressource von Tenant A liefert 403/404 und erzeugt ein Audit-Ereignis.
- [ ] Ein Befehl, der ohne `tenant:run` ausgeführt wird, schlägt mit `TenantContextMissing` fehl (ja, ein fehlschlagender Test ist der Sinn der Sache).
- [ ] Ein manipulierter `actor_id` in `audit_events` wird von `audit:verify` gemeldet.
