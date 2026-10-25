<!-- source: docs/en/what-it-does-not-do.md sha256: 370b4e7766b0ea08f339a8ef6e93f6cd3b254ed23216482d5700abdf7aab92c9 -->
# Was das Framework nicht tut

*Deutsche Ausgabe — die [englische Fassung](../en/what-it-does-not-do.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Dies ist die wichtigste Seite der Dokumentation. Jeder Punkt hier ist eine
bewusste Grenze, kein Bug. Ihre Anwendung muss dafür sorgen.

## Tenant-Isolation

- **Es filtert nicht den Query Builder.** `DB::table('invoices')`, `DB::select()`, rohes SQL und Abfragen in Datenbank-Views oder Stored Procedures werden nicht berührt. Nur Eloquent-Models, die `BelongsToTenant` verwenden, werden gescoped. Die zusammengesetzten Fremdschlüssel aus `$table->tenantForeignId()` sind die einzige Schutzmaßnahme, die unterhalb von Eloquent greift: Sie verhindern tenantübergreifende *Relationen*, nicht tenantübergreifende *Lesezugriffe*.
- **Es verhindert `withoutGlobalScope(TenantScope::class)` nicht.** Das ist Eloquents eigenes Hintertürchen. Behandeln Sie jedes Vorkommen als Code-Review-Befund und verwenden Sie stattdessen `Tenancy::withoutTenant('reason', …)`; Letzteres löst ein Event aus und wird im Audit-Log festgehalten.
- **Es meldet nicht die eventlosen Schreibvorgänge, die es blockiert hat.** Ein Model, das im Kontext eines anderen Tenants geladen und mit aktivierten Events geschrieben wird, wirft `TenantMismatch`. Ohne Events (`saveQuietly()`, `withoutEvents()`) läuft dieselbe Prüfung aus der Save-Query und wirft ebenfalls, es sei denn, die Tenant-Spalte wurde nicht selektiert; dann ist das `UPDATE`/`DELETE` auf den aktiven Tenant beschränkt und betrifft schlicht keine Zeile, und Eloquent meldet trotzdem Erfolg. Selektieren Sie die Tenant-Spalte (sie ist standardmäßig enthalten), wenn Sie die Exception benötigen.
- **Es scoped `fresh()`, `refresh()` oder die Queue-Wiederherstellung einer bereits gehaltenen Model-Instanz nicht.** Diese laden anhand des Primärschlüssels neu, ohne den Tenant-Filter; Sie bekommen nur den Datensatz zurück, den Sie bereits hatten. Das Schreiben ist wie oben abgesichert.
- **Es scoped die Upsert-Konflikterkennung nicht.** `upsert()` setzt den aktiven Tenant auf den eingefügten Zeilen und aktualisiert die Tenant-Spalte bei einem Konflikt nie, aber die Datenbank erkennt Konflikte anhand jedes beliebigen Unique-Keys. Machen Sie Unique-Indizes auf tenant-gebundenen Tabellen tenant-qualifiziert (`unique(['tenant_id', 'number'])`) und übergeben Sie einen tenant-qualifizierten `$uniqueBy`. `insertUsing()` (Sub-Query-Inserts) ist überhaupt nicht abgesichert.
- **Es weiß nicht, welche Models tenant-gebunden sind.** Ein Model ohne den Trait ist global. Fügen Sie den Trait hinzu, oder übernehmen Sie den Architektur-Test aus `tests/Arch` in Ihre eigene Suite, um globale Models explizit aufzulisten.
- **Es scoped Relationen zu globalen Models nicht.** Wenn `Invoice` (gescoped) zu `Currency` (global) gehört, ist die Währung für alle sichtbar, wie beabsichtigt. Wenn `Invoice` zu `Customer` gehört und `Customer` den Trait nicht hat, ist das ein Fehler in Ihrem Model, nichts, was das Framework erkennen kann.
- **Es autorisiert keine Aktionen innerhalb eines Tenants.** Mitgliedschaft und Rollen beantworten „darf dieser Benutzer für diesen Tenant überhaupt handeln?“. Ob ein Mitglied diese bestimmte Rechnung löschen darf, ist Aufgabe Ihrer Policy (Gates, Policies).
- **Es bindet Sessions nicht von selbst an Tenants.** Der Session-Resolver liest nur einen Wert, den `SessionTenant::switchTo()` nach einer Mitgliedschaftsprüfung geschrieben hat. Wenn Sie den Session-Schlüssel selbst schreiben, umgehen Sie diese Prüfung.
- **Es schützt nicht vor einem kompromittierten Anwendungsserver.** Code, der innerhalb Ihres Prozesses läuft, kann `Tenancy::withoutTenant()` aufrufen; das Framework macht das sichtbar, nicht unmöglich.
- **Es verschiebt keine Daten zwischen Tenants.** Die Tenant-Spalte ist unveränderlich. Migrationen zwischen Tenants sind eine explizite, auditierte Operation, die Sie selbst mit `Tenancy::withoutTenant()` und rohen Inserts schreiben.

## Audit-Log

- **Es verhindert keine Manipulation; es macht Manipulation nachweisbar.** Datenbank-Trigger stoppen beiläufige `UPDATE`/`DELETE`-Vorgänge. Ein Datenbank-Superuser kann sie löschen. Die Hash-Kette, die HMAC-Signatur und die externen Anker existieren, damit das Spuren hinterlässt, wenn `audit:verify` läuft. Führen Sie es aus.
- **Es ist nur so manipulationssicher nachweisbar, wie Schlüssel und Anker von der Datenbank getrennt sind.** Wenn `AUDIT_SIGNING_KEYS` am selben Ort liegt wie die Datenbank-Zugangsdaten oder Anker nur in der Datenbank gespeichert werden, kann ein Angreifer mit Zugriff auf beides die Historie umschreiben. Legen Sie den Schlüssel in einen Secret-Manager und ankern Sie in Objektspeicher mit Object Lock oder in eine externe Log-Senke.
- **Es signiert oder ankert nicht automatisch.** Signieren geschieht bei jedem Schreibvorgang, sobald ein Schlüssel konfiguriert ist; Ankern geschieht, wenn `php artisan audit:anchor` läuft. Planen Sie es im Scheduler ein.
- **Es zeichnet nichts auf, was Sie ihm nicht mitteilen.** Abgesehen von den Sicherheitsereignissen des Tenant-Pakets (Verweigerungen, Umgehungen, Mitgliedschaftsänderungen, Wechsel) ist jedes Geschäftsereignis ein expliziter Aufruf von `Audit::action(…)->record()`. Model-Observer sind Ihre eigene Entscheidung.
- **Es macht den Audit-Schreibvorgang nicht von selbst atomar mit Ihrem Geschäfts-Schreibvorgang.** Fassen Sie auf derselben Verbindung beide in einem `Audit::transaction(fn () => …)` zusammen, wenn der Audit-Datensatz genau dann existieren muss, wenn auch die Geschäftsänderung existiert — das ist `DB::transaction()` plus die Wiederholungs- und Backoff-Regeln, die die Kette braucht, verwenden Sie also dieses und nicht den nackten Laravel-Aufruf; außerhalb einer Transaktion committet der Audit-Datensatz für sich allein. Zeigt `AUDIT_DB_CONNECTION` auf eine separate Verbindung, gibt es überhaupt keine gemeinsame Transaktion, sodass ein Absturz zwischen den beiden Schreibvorgängen den einen ohne den anderen zurücklassen kann.
- **Es garantiert keine Metadaten-Privatsphäre.** Der Sanitizer schwärzt gängige geheime Schlüssel und geheim aussehende Werte und pseudonymisiert Identifier. Er kann nicht jedes Geheimnis erkennen. Übergeben Sie nicht `$request->all()`.
- **Es löst nicht die DSGVO-Löschung.** Metadaten sind unveränderlich. Personenbezogene Daten gehören in die pseudonymisierten Felder (schlüsselbasierter Hash) oder außerhalb des Logs, referenziert über die Public ID. Eine Person zu löschen bedeutet dann, den referenzierten Datensatz zu löschen, nicht das Audit-Ereignis. `AUDIT_PSEUDONYM_KEY` nach einer Aufbewahrungsfrist zu rotieren, ist das Nächste, was dieses Design an Crypto-Shredding bietet; planen Sie die Aufbewahrung, bevor Sie live gehen.
- **Es speichert nicht den vorherigen Zustand einer Ressource.** Legen Sie das Diff in die Metadaten, wenn Sie es brauchen.
- **Es verifiziert Ketten nicht bei jedem Schreibvorgang.** Verifikation ist ein geplanter, in Blöcken ausgeführter Durchlauf (`audit:verify`); `security:doctor` verifiziert vor Ort nur das Ende jeder Kette.
- **Es macht Ihre Transaktionen nicht idempotent.** `Audit::transaction()` führt bei Nebenläufigkeitskonflikten die gesamte Closure erneut aus. Seiteneffekte, die nicht Teil der Datenbanktransaktion sind (E-Mails, HTTP-Aufrufe, Queue-Pushes außerhalb der Transaktion), würden sich wiederholen; halten Sie sie aus der Closure heraus.
- **Es ersetzt kein Monitoring.** `security:doctor` sagt Ihnen, ob die Installation die Garantien noch einhält; jemand muss ihn ausführen und das Ergebnis lesen. Ein fehlender Signaturschlüssel oder ein gelöschter Trigger bleibt bis dahin unbemerkt, außer beim Start in Produktion (fail-fast).

## Öffentliche Identifier

- **Sie sind keine Autorisierung.** Eine nicht erratbare ID stoppt Enumeration. Sie stoppt nicht jemanden, der die ID aus einem Log, einem Referrer-Header, einem Support-Ticket oder einem Export erhalten hat. Jede Route braucht weiterhin den Tenant-Scope und Ihre Policies.
- **Sie werden bei Bulk-Inserts nicht vergeben.** `Model::insert()` und `upsert()` umgehen Eloquent-Events. Erzeugen Sie IDs zuerst mit `PublicIdGenerator`.
- **Sie sind ohne Index nicht eindeutig.** `generateUnique()` funktioniert nach dem Prinzip Check-then-Insert. `$table->publicId()` fügt den Unique-Index hinzu; entfernen Sie ihn nicht.

## Betrieb

- **Kein Rate Limiting.** 404s für unbekannte Tenant-Identifier und 403s für verweigerte Mitgliedschaften sind billig, aber ein Scanner kann sie trotzdem erzeugen. Begrenzen Sie die Rate am Edge.
- **Keine Multi-Datenbank-Tenancy.** Eine Datenbank, ein Schema, eine Tenant-Spalte. Database-per-Tenant ist ein anderes Produkt.
- **Keine Oberfläche für Tenant-Provisionierung, keine Einladungen, kein Billing, kein SSO.** Mitgliedschaften sind eine Tabelle und eine API.
- **Keine Unterstützung für MySQL/MariaDB ohne Trigger-Rechte.** Die Migration benötigt `CREATE TRIGGER` (bei aktiviertem Binary Logging: `SUPER` oder `log_bin_trust_function_creators=1`). Sie schlägt laut fehl, statt ohne Schutz zu migrieren.
