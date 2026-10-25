<!-- source: docs/en/reference/exceptions.md sha256: 0acd1797c23f0fddfc8e83221070e8add7baa976c1806aaf70d9c9c679cd369a -->
# Exceptions

*Deutsche Ausgabe — die [englische Fassung](../../en/reference/exceptions.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Zwei Familien. **Request-Fehler** sind `HttpException`s, die Laravel als
4xx-Antwort rendert; das Framework wirft sie aus der Middleware, und Ihre
Anwendung soll sie nicht abfangen. **Programmier- und Konfigurationsfehler**
sind `LogicException`/`InvalidArgumentException`/`RuntimeException`s, die
bedeuten, dass der Code oder das Deployment falsch ist; sie abzufangen
verbirgt das Problem — beheben Sie die Ursache, die die Meldung benennt.
Jede Meldung sagt, was falsch ist, und, wo es sinnvoll ist, was zu tun ist.

## Tenant — `Efnext\Security\Tenant\Exceptions` (alle implementieren `TenantException`)

| Exception | HTTP | Geworfen, wenn | Meldung | Behebung |
| --- | --- | --- | --- | --- |
| `TenantIdentifierMissing` | **400** | `tenant.resolve` findet keinen Identifier (Header, Routenparameter, Session, Subdomain) | `No tenant identifier found in the request (checked: …)` | Client sendet den Identifier; oder `tenant.resolve:optional` für Routen, die auch ohne Tenant laufen dürfen |
| `TenantNotFound` | **404** | der Identifier passt zu keinem Tenant | `Tenant not found.` (Identifier wird aus dem Body herausgehalten) | — |
| `TenantSuspended` | **403** | der Status des Tenants ist nicht `active` (ein unbekannter Status zählt als suspended) | `Tenant is suspended.` / `Tenant is archived.` | Tenant reaktivieren |
| `TenantAccessDenied` | **403** | `tenant.member`, `tenant.role`, `tenant.token` oder ein Session-Wechsel verweigern den Zugriff; `reason()` liefert die `DenialReason` | `You are not a member of this tenant.`, `Your membership … is not active.`, `… has expired.`, `Your role … does not permit this action.`, `This API token is not bound to a tenant.`, `… is not valid for this tenant.` | Mitgliedschaft/Rolle gewähren, Token erneuern |
| `TenantContextMissing` | 500 | ein tenant-gebundenes Model wird ohne aktiven Tenant abgefragt, erstellt, aktualisiert oder gelöscht; `Tenancy::require()` ohne Kontext | `No tenant context: cannot query <Model> without an active tenant.` + Hinweis | Middleware `tenant` hinzufügen, den Code in `Tenancy::run()` einwickeln, oder eine tenant-übergreifende Operation mit `Tenancy::withoutTenant('reason', …)` deklarieren. **Niemals abfangen.** |
| `TenantMismatch` | 500 | ein für einen Tenant geladenes oder erstelltes Model geschrieben wird, während ein anderer Tenant aktiv ist | `Refusing to create/update/delete <Model> owned by tenant "…" while tenant "…" is active.` | Der Code vermischt Kontexte; `Tenancy::run()` pro Tenant verwenden |
| `TenantColumnImmutable` | 500 | die Tenant-Spalte eines persistierten Datensatzes geändert wird (Model oder Query Builder) | `<Model>: tenant_id of a persisted record must not change. Moving data between tenants is not supported.` | Den Datensatz im Ziel-Tenant neu anlegen |
| `InvalidTenantId` | 500 | `TenantId::fromString()` mit einem fehlerhaft gebildeten Wert | `Invalid tenant id "…": expected 1-191 printable characters …` | `TenantId::tryFromString()` für nicht vertrauenswürdige Eingaben verwenden |

Außerdem wirft das Tenant-Paket: `Illuminate\Auth\AuthenticationException`
(401) aus `tenant.member` bei nicht authentifizierten Anfragen;
`InvalidArgumentException` für `withoutTenant('')`, eine unbekannte Rolle in
`grantTenantMembership()`, eine leere Rollenliste, oder einen fehlerhaften
Eintrag in `tenant.resolution.chain`; `LogicException` für `nullOnDelete()`
auf einem zusammengesetzten Tenant-Schlüssel, einen benutzerdefinierten
Eloquent-Builder, der `GuardsTenantColumn` nicht verwendet,
`TenantContextMiddleware` auf einem Job, der nicht `TenantAware` ist, sowie
Mitgliedschafts-Helfer auf einem nicht gespeicherten Benutzer.

## Audit — `Efnext\Security\Audit\Exceptions` (alle implementieren `AuditException`)

Keine ist eine `HttpException`; unabgefangen erscheinen sie in HTTP als 500.

| Exception | Geworfen, wenn | Meldung | Behebung |
| --- | --- | --- | --- |
| `InvalidAuditEntry` | action, resource type/id oder metadata validieren nicht; `->on()` fehlt; kein Tenant unter der Policy `required` | `Invalid audit entry: …` / `… a tenant is required (tenant policy "required") and none was given or active.` | Den Aufruf korrigieren; `->global()` oder `->forTenant()`, wo die Policy es erlaubt |
| `AuditEventImmutable` | `AuditEvent` wird über Eloquent aktualisiert oder gelöscht | `Audit records are append-only; they can neither be updated nor deleted.` | — (Absicht) |
| `InvalidSigningKey` | `AUDIT_SIGNING_KEYS` / `AUDIT_SIGNING_ACTIVE_KEY` fehlerhaft gebildet, Schlüssel < 32 Bytes, aktiver Schlüssel nicht im Schlüsselbund | `Invalid audit signing key configuration: … Expected AUDIT_SIGNING_KEYS="k1:base64:<key>,…"` | `php artisan audit:key:generate` |
| `InsecureConfiguration` | Boot in `APP_ENV=production` mit aktivem `AUDIT_FAIL_FAST` und einer Konfiguration, die eine Garantie aufhebt (kein Schlüssel, SQLite, Append-Only aus, kein lesbarer Anker-Store, externer Anker erforderlich, aber fehlend) | `Refusing to boot the audit log in production with an insecure configuration:` + eine Zeile pro Problem | Konfigurieren, was die Zeilen benennen; `AUDIT_FAIL_FAST=false` nur als bewusste, vorübergehende Ausnahme |
| `UnsupportedDatabaseDriver` | die Migration oder der Doctor trifft auf einen Treiber ohne Append-Only-Trigger | `Append-only triggers are not implemented for the "<driver>" driver (supported: pgsql, mysql, mariadb, sqlite).` | Eine unterstützte Datenbank verwenden |
| `CanonicalizationFailed` | metadata kann nicht kanonisiert werden (Objekte, Resources, NaN/Inf) | `Audit record cannot be canonicalised: …` | Skalare, Arrays, DateTimes, Stringables übergeben |

Außerdem: `InvalidArgumentException` für einen unbekannten Eintrag in
`AUDIT_ANCHOR_STORES`; `RuntimeException`, wenn eine Kettenkopf-Zeile mitten
im Schreibvorgang verschwindet.

## Addressing — `Efnext\Security\Addressing\Exceptions` (erweitern `AddressingException`)

Route-Binding wirft niemals bei einer fehlerhaft gebildeten ID:
`resolveRouteBinding()` gibt `null` zurück, was ein 404 ergibt.

| Exception | Geworfen, wenn | Behebung |
| --- | --- | --- |
| `InvalidPublicId` | `PublicId::of()/fromString()` mit einem falschen Präfix oder Body; eine vorab gesetzte ID mit falschem Präfix bei `creating`; Entropie unter 8 Bytes | Präfix `[a-z][a-z0-9]{1,11}`, Body `[a-z0-9]{8,64}`; `tryFromString()` für Eingaben verwenden; `entropy_bytes ≥ 8` beibehalten |
| `InvalidGlobalObjectId` | `GlobalObjectId::of()/fromString()` mit fehlerhaft gebildeten Segmenten | `<service>:<type>:<public id>`, Segmente `[a-z][a-z0-9-]{0,62}`; `tryFromString()` für Eingaben |
| `MissingPublicId` | `publicId()` auf einem Model, das noch nicht erstellt wurde | Das Model zuerst erstellen |
| `PublicIdImmutable` | die Public-ID-Spalte eines persistierten Models geändert wird | — (Absicht) |
| `PublicIdGenerationFailed` | keine eindeutige ID nach `max_attempts` (bei 80 Bit praktisch unerreichbar) | Entropie-Einstellung und die System-Zufallsquelle prüfen |

Außerdem: `InvalidArgumentException('Unknown public id encoding …')` für ein
fehlerhaftes `addressing.public_ids.encoding`.
