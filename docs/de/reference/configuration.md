<!-- source: docs/en/reference/configuration.md sha256: 6fa8b447781a1b0c9b6cd38e3933860ddd0723d99bd01e2d8ac9380a33040dd6 -->
# Konfigurationsreferenz

*Deutsche Ausgabe — die [englische Fassung](../../en/reference/configuration.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Jeder Schlüssel der drei veröffentlichten Konfigurationsdateien, mit seinem
Standardwert, der Umgebungsvariable, die ihn speist (falls vorhanden), und der
Auswirkung auf die Sicherheitsgarantien. Guides erklären Aufgaben; diese Seite
erklärt die Stellschrauben.

- `config/addressing.php` — 5 Schlüssel
- `config/tenant.php` — 25 Schlüssel
- `config/audit.php` — 32 Schlüssel

Konventionen: „env `X`, Standard …“ bedeutet `env('X', …)`; ein leerer String
in `.env` gilt für jeden String-Schlüssel (Connection, Store, Column …) als
„nicht gesetzt“, weshalb `.env.example` `AUDIT_DB_CONNECTION=` auflisten kann.
Boolesche Env-Werte für die Schalter Fail-Fast, Append-Only und externer Anker
werden mit `filter_var(FILTER_VALIDATE_BOOL)` geparst, sodass `true/false`,
`1/0`, `on/off` und `yes/no` alle funktionieren; das Literal `true`/`false`
ist am wenigsten überraschend. `.env.example` im Repository-Root listet jede
der folgenden Umgebungsvariablen mit ihrem Standardwert.

## `addressing.php`

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `addressing.service_name` | `app` | `SECURITY_SERVICE_NAME` | Erstes Segment jeder Global Object ID (`<service>:<type>:<public id>`); Kleinbuchstaben, Ziffern, Bindestriche; eindeutig über all Ihre Services hinweg. | IDs anderer Services lösen hier niemals auf. **Ändern Sie ihn niemals, nachdem IDs ausgegeben wurden** — jede ausgegebene Global Object ID würde nicht mehr auflösen. |
| `addressing.public_ids.column` | `public_id` | `SECURITY_PUBLIC_ID_COLUMN` | Standardspalte für `HasPublicId`-Models, `$table->publicId()`, Route-Binding und den Schutz vor Mass Assignment; pro Model überschreibbar mit `publicIdColumn()`. | — |
| `addressing.public_ids.encoding` | `base32` | `SECURITY_PUBLIC_ID_ENCODING` | `base32` (Crockford, 16 Zeichen für 10 Bytes) oder `hex`. | Jeder andere Wert wirft bei der ersten ID-Generierung eine Exception. |
| `addressing.public_ids.entropy_bytes` | `10` | `SECURITY_PUBLIC_ID_ENTROPY_BYTES` | Zufällige Bytes pro ID (standardmäßig 80 Bit). | Unter 8 Bytes verweigert der Generator (`InvalidPublicId`): Das Framework gibt keine erratbaren IDs aus. |
| `addressing.public_ids.max_attempts` | `5` | `SECURITY_PUBLIC_ID_MAX_ATTEMPTS` | Wiederholungsversuche bei einer (praktisch unmöglichen) Kollision; nur sinnvoll mit dem `UNIQUE`-Index, den `publicId()` anlegt. | Nach dem letzten Versuch wird `PublicIdGenerationFailed` geworfen, niemals eine wiederverwendete ID. |

## `tenant.php`

### Maßgebliche Quelle

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `tenant.model` | `Efnext\Security\Tenant\Eloquent\Tenant` | — | Tenant-Model für Relationen und Mitgliedschaften (eine Subklasse ist zulässig). | — |
| `tenant.table` | `tenants` | — | Tenants-Tabelle (Migration, Model, Locator, zusammengesetzte Fremdschlüssel). | Eine Änderung nach `migrate` bricht die `tenantForeignId()`-Constraints, die darauf verweisen. |
| `tenant.connection` | `null` | `TENANT_DB_CONNECTION` | Connection für Tenants und Mitgliedschaften; leer = Standard. | — |
| `tenant.column` | `tenant_id` | — | Spalte auf tenant-gebundenen Tabellen; pro Model überschreibbar mit `$tenantColumn`. | Die Spalte, nach der `TenantScope` filtert und die das Framework aus dem Kontext befüllt. Niemals in `$fillable`. |

### Resolution — Woher der Identifier kommt

Resolver laufen in der aufgeführten Reihenfolge; der erste, der einen
Identifier findet, gewinnt; der Identifier wird anschließend nachgeschlagen
(nächster Abschnitt) und muss zu einem bestehenden Tenant passen, sonst
erhält die Anfrage ein 404.

| Schlüssel | Standard | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- |
| `tenant.resolution.chain` | `['header', 'route', 'session']` | Geordnete Resolver: `header`, `route`, `session`, `subdomain`, oder eine Klasse, die `TenantResolver` implementiert. | Eine leere Chain oder ein unbekannter Eintrag wirft beim Boot eine Exception. `subdomain` ist nicht in der Standard-Chain und benötigt `base_domains`. |
| `tenant.resolution.header` | `['X-Tenant-Id']` | Header, die vom `header`-Resolver gelesen werden; der erste nicht-leere gewinnt. | Der Header ist ein *Identifier*, niemals ein Nachweis: Die Mitgliedschaft wird anschließend durch `tenant.member` erzwungen. |
| `tenant.resolution.route_parameters` | `['tenant']` | Routenparameter, die vom `route`-Resolver gelesen werden (ein gebundenes Model wird auf seinen Schlüssel reduziert). | — |
| `tenant.resolution.session_key` | `efnext.tenant` | Session-Schlüssel, der vom `session`-Resolver gelesen und von `SessionTenant::switchTo()` geschrieben wird. | Ein Wechsel löst `TenantSwitched` aus, was auditiert wird. |
| `tenant.resolution.subdomain.base_domains` | `[]` | Basisdomains für den `subdomain`-Resolver; das einzelne Label links von einer Basisdomain ist der Identifier. | Der Host-Vergleich erfolgt in Kleinbuchstaben und verlangt den Punkt, sodass `evilexample.test` nicht auf `example.test` passt; verschachtelte Labels werden ignoriert. |

### Lookup — Wie aus einem Identifier ein Tenant wird

| Schlüssel | Standard | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- |
| `tenant.lookup.by` | `['id', 'public_id', 'global_object_id', 'slug']` | Strategien, die der Reihe nach versucht werden: UUID/ULID-ID, Public ID `ten_…`, `<service>:tenant:ten_…` (nur dieser Service), Slug. | Jede Strategie validiert die *Form*, bevor abgefragt wird; Identifier über 255 Zeichen werden von vornherein abgelehnt. Unbekannte Strategienamen werden ignoriert. |
| `tenant.lookup.slug_column` | `slug` | Spalte für die `slug`-Strategie. | Nur `^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$` wird akzeptiert. |
| `tenant.lookup.status_column` | `status` | Spalte, die den Tenant-Status liefert. | Fail closed: Ein unbekannter oder fehlender Status ist `suspended`, niemals `active`; verweist dies auf die falsche Spalte, werden alle Tenants ausgesperrt (403). |

### Cache

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `tenant.cache.enabled` | `true` | `TENANT_CACHE_ENABLED` | Umhüllt den Locator mit `CachedTenantLocator`. | Das Deaktivieren schaltet auch den Negative-Cache ab, der die Enumeration von Identifiern erschwert. |
| `tenant.cache.store` | `null` | `TENANT_CACHE_STORE` | Cache-Store; leer = Anwendungsstandard. | In Produktion warnt der Doctor, wenn der effektive Store `array` oder `null` ist: Verwenden Sie redis, memcached oder database. |
| `tenant.cache.ttl` | `60` | `TENANT_CACHE_TTL` | Sekunden, für die ein gefundener Tenant (ID, Status, Public ID, Slug, Name) gecacht wird. | Sperrungen, die außerhalb des `Tenant`-Models vorgenommen werden, wirken sich innerhalb dieser Zeit aus; das Speichern des Models leert den Cache sofort. |
| `tenant.cache.negative_ttl` | `5` | `TENANT_CACHE_NEGATIVE_TTL` | Sekunden, für die ein unbekannter Identifier gemerkt wird. | `0` deaktiviert das Negative Caching. Ein Tenant, der abgefragt wird, bevor er angelegt wurde, gilt bis zu dieser Dauer als „unbekannt“. |
| `tenant.cache.prefix` | `efnext:tenant:` | — | Präfix des Cache-Schlüssels; Schlüssel sind `prefix + sha256(identifier)`. | Rohe Identifier erscheinen niemals in Cache-Schlüsseln. |

### Mitgliedschaften und Rollen

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `tenant.memberships.model` | `…\TenantMembership` | — | Mitgliedschafts-Model (eine Subklasse ist zulässig). | — |
| `tenant.memberships.table` | `tenant_memberships` | — | Mitgliedschafts-Tabelle. | — |
| `tenant.memberships.user_key_type` | `unsignedBigInteger` | `TENANT_USER_KEY_TYPE` | Typ von `users.id`: `unsignedBigInteger`, `uuid`, `ulid`, `string`. | **Wird nur zur Migrationszeit gelesen.** Muss vor dem ersten `migrate` zur users-Tabelle passen. |
| `tenant.memberships.roles` | `['owner', 'admin', 'member', 'viewer']` | — | Rollenvokabular, höchste zuerst. | Eine Rolle außerhalb dieser Liste kann nicht vergeben werden und erfüllt niemals eine Prüfung; eine leere Liste wirft beim Boot eine Exception. |
| `tenant.memberships.hierarchical` | `true` | — | Eine erforderliche Rolle wird durch diese Rolle oder jede höhere erfüllt. | `false` schränkt den Zugriff ein: `tenant.role:admin` weist dann auch Owner ab. |
| `tenant.memberships.default_role` | `member` | — | Rolle, die `grantTenantMembership()` ohne explizite Rolle vergibt. | Niedrig-privilegiert halten; muss eine der `roles` sein. |

### Queue

| Schlüssel | Standard | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- |
| `tenant.queue.propagate` | `true` | Bettet den aktiven Tenant in jede Payload eines Queue-Jobs ein und stellt ihn wieder her, bevor der Job läuft. | Mit `false` laufen Jobs ohne Tenant und treffen auf die fail-closed Scopes — das ist das beabsichtigte Ergebnis; machen Sie den Tenant mit `TenantAware` explizit. Eine `withoutTenant()`-Umgehung wird bewusst **nicht** propagiert. |

### API-Token (Sanctum, optional)

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `tenant.tokens.require_binding` | `false` | `TENANT_TOKENS_REQUIRE_BINDING` | Ob `tenant.token` Tokens ablehnt, die keine `tenant:<id>`-Ability tragen. | Der Standard ist permissiv. Unabhängig davon muss ein Token, das *doch* Tenant-Abilities trägt, den aktiven Tenant enthalten (Vergleich in konstanter Zeit); Cookie-Sessions sind davon nie betroffen. Nur auf Routen erzwungen, die `tenant.token` verwenden. |

## `audit.php`

### Speicherung

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `audit.connection` | `null` | `AUDIT_DB_CONNECTION` | Dedizierte Connection für die Audit-Tabellen; leer = Standard. Minimale Rechte: `SELECT, INSERT` auf `audit_events`, `audit_anchors`, `audit_verification_runs`; `SELECT, INSERT, UPDATE` auf `audit_chain_heads`. | Der Treiber dieser Connection entscheidet über Produktion: SQLite oder alles außer pgsql/mysql/mariadb verweigert den Start. Eine von der Anwendung abweichende Connection ist ein WARN des Doctors: `Audit::transaction()` ist dann nicht mehr über beide hinweg atomar. |
| `audit.table` | `audit_events` | — | Hash-verkettete Ereignisse. | Hier werden Append-Only-Trigger installiert. |
| `audit.heads_table` | `audit_chain_heads` | — | Eine Zeile pro Kette (`last_sequence`, `last_hash`), bei jedem Schreibvorgang mit `FOR UPDATE` gesperrt. | Die einzige Audit-Tabelle, die legitim aktualisiert wird; kein Trigger. |
| `audit.anchors_table` | `audit_anchors` | — | Der `database`-Anker-Store. | Trigger installiert; Anker hier zählen niemals als *extern*. |
| `audit.verification_runs_table` | `audit_verification_runs` | — | Läufe von `audit:verify` und die Tail-Prüfungen des Doctors. | Wird von `verification_recency` des Doctors gelesen. |

### Durchsetzung von Append-Only

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `audit.enforce_append_only` | `true` | `AUDIT_ENFORCE_APPEND_ONLY` | Installiert `BEFORE UPDATE/DELETE`-Trigger auf Events und Ankern (PostgreSQL, MySQL, MariaDB, SQLite; andere Treiber brechen die Migration ab). | **Wird zur Migrationszeit gelesen**; `false` protokolliert eine laute Warnung und installiert nichts, und es zurückzudrehen erfordert erneut die Migration. In Produktion verweigert `false` den Start und ist ein FAIL des Doctors. Setzen Sie es niemals auf false, nur um eine Umgebung zum Laufen zu bringen. |

### Tenant-Richtlinie

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `audit.tenant_policy` | `required` | `AUDIT_TENANT_POLICY` | `required`: Jedes Ereignis benötigt einen Tenant (aus dem Eintrag oder dem aktiven Kontext); `nullable`: Ereignisse ohne Tenant bilden die `global`-Kette. | Fail closed: Jeder Wert außer dem Literal `nullable` verhält sich wie `required`. `Audit::action()->global()` und die Sicherheitsereignisse des Tenant-Pakets erreichen unabhängig von der Policy immer die globale Kette. |

### Schreiben

| Schlüssel | Standard | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- |
| `audit.write.attempts` | `5` | Wiederholungen der eigenen Transaktion des Writers bei Deadlocks / Serialisierungsfehlern, mit wachsendem Jitter. | Nur wenn der Writer die Transaktion besitzt; innerhalb Ihrer eigenen läuft es einmal — deshalb gehören Geschäfts- und Audit-Schreibvorgänge in `Audit::transaction()`. |

### Signieren

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `audit.signing.keys` | `''` | `AUDIT_SIGNING_KEYS` | `k1:base64:<32+ bytes>[,k2:base64:…]`; generieren mit `audit:key:generate`. | Leer → Ereignisse werden gehasht, aber **nicht signiert**; Produktion verweigert den Start, FAIL des Doctors. Fehlerhafte Einträge, IDs außerhalb von `[A-Za-z0-9_.-]{1,32}` oder Schlüssel unter 32 Bytes werfen `InvalidSigningKey`. Bewahren Sie die Schlüssel in einem Secret-Manager auf, niemals neben den Datenbank-Zugangsdaten. |
| `audit.signing.active_key` | `''` | `AUDIT_SIGNING_ACTIVE_KEY` | Schlüssel, der für neue Signaturen verwendet wird; die anderen verifizieren nur. | Rotation = den neuen Schlüssel hinzufügen, diesen darauf zeigen lassen, den alten weiterhin aufgeführt lassen. Ein aktiver Schlüssel, der nicht im Schlüsselbund ist, wirft eine Exception. |

### Ankern

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `audit.anchoring.stores` | `['database']` | `AUDIT_ANCHOR_STORES` (kommagetrennt) | Stores, in die `audit:anchor` schreibt, externer Store zuerst: `database`, `filesystem`, `log`, oder der Klassenname Ihres eigenen `AnchorStore`. Ein leerer Wert fällt auf `database` zurück. | `log` und `NullAnchorStore` (und Subklassen) sind Write-Only und zählen niemals als lesbar; ohne lesbaren Store verweigert Produktion den Start. Nur-Datenbank ist ein WARN des Doctors, ein FAIL unter `require_external_anchor` oder `--strict`. **`AUDIT_ANCHOR_DISK` auf einen Bucket zu setzen bewirkt für sich allein nichts**; `filesystem` muss in dieser Liste stehen. |
| `audit.anchoring.filesystem.disk` | `local` | `AUDIT_ANCHOR_DISK` | Laravel-Disk für den `filesystem`-Store (ein JSON-Dokument pro Anker). | `local` liegt auf dem Anwendungshost, der nicht unabhängig vom Datenbankhost ist. Das dokumentierte Ziel ist Objektspeicher mit Object Lock (Compliance-Modus, Aufbewahrung ≥ Audit-Aufbewahrung). Unter `require_external_anchor` muss die Disk existieren und beim Boot instanziierbar sein. |
| `audit.anchoring.filesystem.path` | `audit-anchors` | `AUDIT_ANCHOR_PATH` | Verzeichnispräfix auf der Disk. | — |
| `audit.anchoring.log.channel` | `null` | `AUDIT_ANCHOR_LOG_CHANNEL` | Log-Channel des Write-Only-`log`-Stores; leer = Standard-Logger. | Kann die Anforderung „lesbar“ oder „extern“ nicht erfüllen; leiten Sie ihn an ein SIEM weiter. |

### Verifikation

| Schlüssel | Standard | Zweck |
| --- | --- | --- |
| `audit.verification.chunk_size` | `1000` | Ereignisse, die pro Abfrage beim Durchlaufen einer Kette geladen werden. Nur Performance. |

### Schwellenwerte für `security:doctor`

| Schlüssel | Standard | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- |
| `audit.doctor.max_anchor_age_minutes` | `60` | Eine Kette, deren Kopf nicht verankert ist und deren neuestes Ereignis älter ist als dieser Wert → FAIL `anchor_freshness`. | Setzen Sie ihn auf ein paar Vielfache über dem `audit:anchor`-Intervall. Unter `require_external_anchor` zählen nur externe Anker. |
| `audit.doctor.max_verification_age_hours` | `24` | Keine intakte vollständige Verifikation innerhalb dieses Fensters → WARN; ein letzter Lauf mit Verstößen → FAIL unabhängig vom Alter. | Nicht kürzer als der Zeitplan von `audit:verify`. |
| `audit.doctor.tail_events` | `200` | Ereignisse pro Kette, die von jedem Doctor-Lauf sofort erneut verifiziert werden (übersprungen mit `--no-verify`). | Die Kosten wachsen mit dem Wert; es ersetzt nicht `audit:verify`. |

### Fail-Fast in der Produktion

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `audit.production.fail_fast` | `true` | `AUDIT_FAIL_FAST` | Verweigert bei `APP_ENV=production` den Start mit einer Konfiguration, die die Garantien aufhebt (kein Schlüssel, SQLite, Append-Only aus, kein lesbarer Anker-Store), indem `InsecureConfiguration` geworfen wird. | Außerhalb der Produktion ohne Wirkung. `false` ist die bewusste, vorübergehende Ausnahme und ein WARN des Doctors. |
| `audit.production.require_external_anchor` | `false` | `AUDIT_REQUIRE_EXTERNAL_ANCHOR` | High-Security-Profil: Produktion verweigert den Start ebenfalls, sofern nicht ein lesbarer Store *außerhalb* der Audit-Datenbank konfiguriert ist und antwortet. | Doctor: Nur-Datenbank wird zu FAIL, Freshness zählt nur externe Anker, externe Stores werden lesend getestet. `security:doctor --strict` schlägt bei `anchor_stores` fehl, solange dies aus ist — ein Store, der nur konfiguriert ist, kann später entfernt werden, ohne dass etwas den Start verweigert. |

### Request-Kontext

| Schlüssel | Standard | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- |
| `audit.request.enrich` | `true` | Fügt jedem Ereignis Request-ID, Correlation-ID, Client-IP und User-Agent hinzu. | Die vier Felder sind Teil des Hashes. Schreibvorgänge aus Konsole und Queue werden niemals dem Platzhalter-Request von Laravel zugeschrieben. Werte sind längenbegrenzt (64/64/45/255) und von Steuerzeichen befreit. |
| `audit.request.request_id_header` | `X-Request-Id` | Header, der als `request_id` gespeichert wird. | Vom Client gelieferter Kontext, keine Authentifizierung. |
| `audit.request.correlation_id_header` | `X-Correlation-Id` | Header, der als `correlation_id` gespeichert wird. | Dasselbe. |

### Sanitising von Metadaten

Schlüssel werden tokenisiert (`passwordConfirmation` → `password`,
`confirmation`; `x-api-key` → `x`, `api`, `key`); ein Schlüssel wird
geschwärzt, wenn eines der Muster als zusammenhängende Token-Folge auftritt,
sodass `author` und `session_count` erhalten bleiben, während `session_id`
und `apiKey` es nicht tun. Werte werden zusätzlich auf geheim aussehende
Formen durchsucht (JWTs, Bearer-Strings, PEM-Schlüssel, Kartennummern,
Cloud-Zugangsschlüssel).

| Schlüssel | Standard | Env | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- | --- |
| `audit.sanitizer.marker` | `[REDACTED]` | — | Ersatz für geschwärzte Schlüssel und geheim aussehende Werte. | Jede Entfernung hinterlässt einen Marker, sodass das Log zeigt, dass dort etwas war. |
| `audit.sanitizer.redact_key_patterns` | 34 Muster (`password`, `secret`, `token`, `api key`, `private key`, `authorization`, `cookie`, `otp`, `iban`, `card number`, `cvv`, `pin`, …) | — | Schlüssel, deren Token-Folge übereinstimmt, werden durch den Marker ersetzt. | Das Entfernen von Einträgen erweitert, was das unveränderliche Log erreicht. Metadaten werden sanitisiert, nicht verschlüsselt. |
| `audit.sanitizer.pseudonymize_key_patterns` | `email`, `e mail`, `mail`, `username`, `user name`, `login`, `phone`, `mobile`, `identifier` | — | String-Werte unter passenden Schlüsseln werden zu `<key>_hash` = HMAC-SHA256 mit dem Pepper. | Nur String-Werte; ohne Pepper wird der Wert zum Marker, niemals zu einem wörterbuchangreifbaren Klartext-Hash. |
| `audit.sanitizer.allow_keys` | `token_type`, `email_verified`, `email_verified_at`, `login_count`, `mail_driver` | — | Exakte Schlüssel, die *anhand des Schlüssels* niemals geschwärzt oder gehasht werden. | Werte werden weiterhin auf geheim aussehende Formen durchsucht. Jede Ergänzung ist eine bewusste Lücke. |
| `audit.sanitizer.max_depth` | `6` | — | Tiefere Arrays werden zu `['_depth_limited' => true]`. | Begrenzt die Payload-Größe. |
| `audit.sanitizer.max_keys` | `100` | — | Schlüssel pro Array / Elemente pro Liste; der Rest wird verworfen und als `_truncated` markiert. | Dasselbe. |
| `audit.sanitizer.max_string_length` | `1024` | — | Längere Strings werden abgeschnitten und mit `…[truncated]` versehen. | Dasselbe; begrenzt, wie viel von einem versehentlich geloggten Blob gespeichert wird. |
| `audit.sanitizer.pseudonym_key` | `null` | `AUDIT_PSEUDONYM_KEY` | Pepper; leer = ein von `APP_KEY` abgeleiteter Schlüssel (HKDF). | Ihn zu rotieren kommt dem Crypto-Shredding des Logs am nächsten. Bewahren Sie ihn zusammen mit den Signaturschlüsseln auf. |

### Tenant-Sicherheitsereignisse

| Schlüssel | Standard | Zweck | Sicherheitswirkung |
| --- | --- | --- | --- |
| `audit.record_tenant_events` | `true` | Zeichnet `TenantAccessDenied`, `TenantScopeBypassed`, `MembershipChanged` und `TenantSwitched` automatisch auf als `tenant.access_denied`, `tenant.scope_bypassed`, `tenant.membership.*`, `tenant.switched`. | Mit `false` werden `Tenancy::withoutTenant()`-Umgehungen nicht mehr auditiert — die Eigenschaft, auf die sich das Framework verlässt, wenn es tenant-übergreifende Arbeit erlaubt. Ereignisse ohne Tenant gehen unabhängig von der Policy an die globale Kette. |

## Schlüssel ohne Umgebungsvariable

Ändern Sie diese nur in der veröffentlichten Konfigurationsdatei: `tenant.model`, `tenant.table`,
`tenant.column`, `tenant.resolution.*`, `tenant.lookup.*`, `tenant.cache.prefix`,
`tenant.memberships.{model,table,roles,hierarchical,default_role}`,
`tenant.queue.propagate`, `audit.{table,heads_table,anchors_table,verification_runs_table}`,
`audit.write.attempts`, `audit.verification.chunk_size`, `audit.doctor.*`,
`audit.request.*`, `audit.sanitizer.*` (außer `pseudonym_key`), `audit.record_tenant_events`.
