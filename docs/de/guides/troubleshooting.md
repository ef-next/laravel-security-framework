<!-- source: docs/en/guides/troubleshooting.md sha256: 7e0ed631e9264a8f20f5d3f788df4cbd487226a91502ee3c4be9e7c37c825688 -->
# Fehlersuche

*Deutsche Ausgabe — die [englische Fassung](../../en/guides/troubleshooting.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Nach Symptom. Jede Framework-Meldung benennt, was falsch ist, und, wo es
sinnvoll ist, was zu tun ist; diese Seite liefert den Kontext dazu. Im
Zweifel `php artisan security:doctor` ausführen — dessen Ausgabe gehört als
Erstes in jedes Issue.

## Anfragen

| Symptom | Wahrscheinliche Ursache | Diagnose | Behebung |
| --- | --- | --- | --- |
| `400 No tenant identifier found in the request (checked: …)` | der Client sendet keine `X-Tenant-Id` (oder Routenparameter / Session / Subdomain, je nachdem, was die Resolution-Chain auflistet) | die Meldung listet die geprüften Quellen auf | den Identifier senden; für Routen, die auch ohne Tenant laufen dürfen, `tenant.resolve:optional` verwenden |
| `404 Tenant not found.` | unbekannter Identifier, oder ein gültiger in einer Form, die das Lookup nicht akzeptiert, oder eine Slug-Spalte, die nicht `slug` heißt | `php artisan tinker` → `app(TenantLocator::class)->locate('acme')` | `tenant.lookup.by` und `slug_column` prüfen; der Identifier muss eine UUID, `ten_…`, `<service>:tenant:ten_…` oder ein Slug sein |
| `403 Tenant is suspended.` / `archived` | Tenant-Status ist nicht `active` — oder die Status-Spalte enthält einen Wert, den das Framework nicht kennt (fail closed → suspended) | die Zeile ansehen | `status = active` setzen; `tenant.lookup.status_column` prüfen |
| `403 You are not a member of this tenant.` | korrektes Verhalten für Nicht-Mitglieder; auch bei einer Mitgliedschaft mit falschem `user_id`-Typ | `$user->tenantMembership($tenantId)` | die Mitgliedschaft gewähren; prüfen, ob `TENANT_USER_KEY_TYPE` zur Migrationszeit mit `users.id` übereinstimmte |
| `403 Your membership … has expired / is not active` | `expires_at` in der Vergangenheit oder Status `invited`/`suspended` | wie oben | erneuern oder aktivieren |
| `403 Your role … does not permit this action.` | `tenant.role:x` mit einer Rolle, die nicht `x` oder höher ist | `hasTenantRole()` | die Rolle gewähren; `memberships.hierarchical` prüfen |
| `403 This API token is not valid for this tenant.` | Token an einen anderen Tenant gebunden (`tenant:<uuid>`-Ability) | die Abilities des Tokens dekodieren | ein Token für diesen Tenant ausstellen; die Ability benötigt die UUID, nicht den Slug |
| `403 This API token is not bound to a tenant.` | `TENANT_TOKENS_REQUIRE_BINDING=true` und ein ungebundenes Token | — | gebundene Tokens ausstellen; im Audit-Log nachsehen, wer noch ungebundene verwendet |
| `401` auf einer Tenant-Route | nicht authentifiziert, oder `auth` fehlt vor `tenant` | — | `['auth:sanctum', 'tenant']` |
| `404` für eine ID, die existiert | fehlerhafte Public ID (es wird keine Abfrage ausgeführt), falsches Präfix, oder der Datensatz gehört einem anderen Tenant (korrekt) | `PublicId::tryFromString($value)` | die ID aus der API-Antwort verwenden; den aktiven Tenant prüfen |
| `500 TenantContextMissing` in einem Controller | `tenant.resolve` fehlt, oder eine eigene Middleware-Reihenfolge setzt das Route-Model-Binding davor | `security:doctor` → `middleware_priority`; die Middleware-Liste der Route | die Gruppe `tenant` verwenden; Laravels Middleware-Priorität nicht von Hand umsortieren |

## Code und Tests

| Symptom | Wahrscheinliche Ursache | Behebung |
| --- | --- | --- |
| `TenantContextMissing: cannot query <Model> without an active tenant` in einem Job, Befehl oder Test | Codepfad ohne Tenant | `Tenancy::run()`, ein `TenantAware`-Job, `tenant:run`, oder ein begründetes `Tenancy::withoutTenant('reason', …)` — niemals abfangen |
| `TenantMismatch: Refusing to update <Model> owned by tenant A while tenant B is active` | ein Model, das in einem Kontext geladen und in einem anderen geschrieben wird (gequeutes Model, gecachte Instanz, Test-Helper) | die Arbeitseinheit innerhalb eines einzigen `Tenancy::run()` halten |
| `TenantColumnImmutable` | Code versucht, einen Datensatz zwischen Tenants zu verschieben, oder ein `update()` fasst `tenant_id` an | im Ziel-Tenant neu anlegen; die Spalte aus dem Update entfernen |
| `LogicException: … does not guard the tenant column` | ein eigener Eloquent-Builder auf einem `BelongsToTenant`-Model | `use GuardsTenantColumn` im Builder |
| `LogicException: A composite tenant foreign key cannot use ON DELETE SET NULL` | `nullOnDelete()` auf `tenantForeign*()` | `nullable: true` mit `cascadeOnDelete()`/`restrictOnDelete()`; per Update aushängen |
| `MissingPublicId: <Model> has no public_id yet` | `publicId()` vor `save()` | zuerst erstellen |
| `InvalidPublicId … with the wrong prefix` | eine vorab gesetzte Public ID eines anderen Model-Typs | vom Framework zuweisen lassen |
| `InvalidAuditEntry: a tenant is required (tenant policy "required")` | Audit-Schreibvorgang außerhalb eines Tenant-Kontexts | `->global()` für wirklich tenant-lose Ereignisse, `->forTenant()`, oder innerhalb eines Kontexts ausführen |
| `InvalidAuditEntry: call ->on(...)` | Builder ohne Resource verwendet | `->on($model)` oder `->on('type', 'id')` |
| `CanonicalizationFailed` | Objekte/Resources/NaN in den Metadaten | Skalare, Arrays, DateTimes übergeben |
| `AuditEventImmutable` | Code versucht, ein `AuditEvent` zu aktualisieren oder zu löschen | — (Absicht) |
| Deadlocks / `could not serialize access` / MariaDB-Fehler 1020 bei Audit-Schreibvorgängen | Business-Transaktion mit `DB::transaction()` | `Audit::transaction()`; `Audit::prepareChain()` für neue Tenants |

## Migrationen und Datenbank

| Symptom | Wahrscheinliche Ursache | Behebung |
| --- | --- | --- |
| `SQLSTATE[HY000]: … Access denied; you need (at least one of) the SUPER privilege(s) … TRIGGER` (MySQL/MariaDB) | Binary Logging aktiv, Migrations-Benutzer ohne `SUPER` | `SET GLOBAL log_bin_trust_function_creators = 1` oder die Migration als privilegierter Benutzer ausführen |
| `UnsupportedDatabaseDriver: Append-only triggers are not implemented for the "…" driver` | SQL Server, Oracle, … | PostgreSQL, MySQL oder MariaDB verwenden |
| `Foreign key constraint is incorrectly formed` (errno 150) | ein zusammengesetzter Tenant-Schlüssel mit `SET NULL`, oder der übergeordneten Tabelle fehlt `UNIQUE (tenant_id, id)` (mit einem einfachen `foreignUuid` statt `tenantId()` erstellt) | siehe oben; übergeordnete Tabellen mit `$table->tenantId()` erstellen |
| `Duplicate entry` bei `number` über Tenants hinweg | Unique-Index ohne die Tenant-Spalte | `$table->unique(['tenant_id', 'number'])` |
| `NOT NULL constraint failed: invoices.public_id` bei Bulk-Insert | `insert()` umgeht Eloquent-Events | über das Model erstellen oder IDs mit `PublicIdGenerator` erzeugen |
| `tenant_memberships.user_id`-Typkonflikt / Fremdschlüssel zu users schlägt fehl | `TENANT_USER_KEY_TYPE` stimmte beim Ausführen der Migration nicht mit `users.id` überein | setzen und die Mitgliedschafts-Migration auf einem frischen Schema erneut ausführen |

## Boot und Konfiguration

| Symptom | Wahrscheinliche Ursache | Behebung |
| --- | --- | --- |
| `InsecureConfiguration: Refusing to boot the audit log in production …` | `APP_ENV=production` ohne Schlüssel / SQLite / append-only aus / kein lesbarer Anker-Store / externer Anker erforderlich, aber fehlend | konfigurieren, was die aufgeführten Zeilen benennen; **nicht** `AUDIT_FAIL_FAST=false` setzen, um daran vorbeizukommen |
| `InvalidSigningKey` | fehlerhaftes `AUDIT_SIGNING_KEYS`, Schlüssel kürzer als 32 Bytes, aktiver Schlüssel nicht in der Liste | `php artisan audit:key:generate`; Format `k1:base64:<key>` |
| `security:doctor --strict` schlägt bei `anchor_stores` fehl, obwohl Anker funktionieren | `AUDIT_REQUIRE_EXTERNAL_ANCHOR` ist aus — strict verlangt, dass das Profil *erzwungen*, nicht nur konfiguriert ist | auf `true` setzen |
| Doctor: `Only the database store is readable` | `AUDIT_ANCHOR_STORES` fehlt `filesystem` (nur `AUDIT_ANCHOR_DISK` zu setzen bewirkt nichts) | `AUDIT_ANCHOR_STORES=filesystem,database` |
| Doctor: `External store "filesystem" is not readable` | Disk falsch konfiguriert, falsche Zugangsdaten, Bucket-Policy verweigert `ListBucket`/`GetObject` | die Disk testen mit `Storage::disk('audit-anchors')->put('probe', '1')` |
| Doctor: `anchor_freshness` FAIL | Scheduler läuft nicht, oder `max_anchor_age_minutes` liegt unter dem Anker-Intervall | `schedule:run` prüfen; den Schwellenwert anpassen |
| Doctor: `verification_recency` WARN | `audit:verify` nicht eingeplant oder schlägt fehl | einplanen; die Ausgabe lesen |
| Doctor: `Store "array" … negative cache ineffective` (WARN) | Tenant-Cache in Produktion auf dem array-Treiber | `TENANT_CACHE_STORE=redis` |
| `InvalidArgumentException: audit.anchoring.stores entry "…" is neither database, filesystem, log nor a class` | Tippfehler in `AUDIT_ANCHOR_STORES` | den Namen korrigieren; ein eigener Store wird als vollqualifizierter Klassenname angegeben |
| `InvalidArgumentException: tenant.resolution.chain must contain at least one resolver` | Resolution-Chain in der veröffentlichten Config geleert | mindestens `header` auflisten |

## Verifikationsfehler

`audit:verify` beendet sich mit 1 und benennt jeden Verstoß mit einem Grund
und einer Sequenznummer:

| Grund | Bedeutung | Wo nachsehen |
| --- | --- | --- |
| `event_hash_mismatch` | der Inhalt der Zeile weicht von dem ab, was gehasht wurde | diese Zeile wurde verändert |
| `previous_hash_mismatch` / `missing_predecessor` / `sequence_gap` | eine Zeile wurde vor dieser entfernt oder ersetzt | die Zeilen zwischen der letzten intakten und dieser Sequenznummer |
| `signature_invalid` / `signature_missing` / `signature_key_unknown` | ohne den Schlüssel umgeschrieben, oder ein Schlüssel wurde aus der Liste entfernt | Schlüsselliste und Rotationshistorie |
| `head_mismatch` / `anchor_ahead` | die Kette wurde gekürzt, nachdem der Kettenkopf oder Anker geschrieben wurde | mit den externen Ankern vergleichen |
| `anchor_mismatch` | konsistent umgeschrieben (mit dem Schlüssel), aber vor dem letzten Anker | der Anker ist die Vertrauensgrenze; Incident Response starten |
| `unsupported_hash_version` | ein Ereignis beansprucht eine Hash-Version, die dieses Framework nicht kennt | ein Downgrade, oder eine manipulierte Zeile |

Danach: [operations/incident-response.md](../operations/incident-response.md).

## Hilfe bekommen

- `php artisan security:doctor --json` und die Framework-Version
  (`composer show efnext/security-framework`) in jedem Report.
- Sicherheitslücken: privat, siehe `SECURITY.md` — nicht in einem Issue.
