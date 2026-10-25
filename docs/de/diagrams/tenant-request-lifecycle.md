<!-- source: docs/en/diagrams/tenant-request-lifecycle.md sha256: 7ff46c8b3bc830535be3be3f8267b99f7899b166209f7461b85880fc0c00d1ed -->
# Lebenszyklus einer Tenant-Anfrage

*Deutsche Ausgabe — die [englische Fassung](../../en/diagrams/tenant-request-lifecycle.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

**Leserfrage:** Wie wird aus einer Anfrage ein aktiver Tenant (Mandant), wo
genau wird der Zugriff verweigert, und warum kann ein Controller niemals die
Zeilen eines anderen Tenants sehen?

```mermaid
sequenceDiagram
    autonumber
    participant C as Client
    participant A as auth middleware
    participant R as tenant.resolve
    participant L as TenantLocator<br/>(cache → database)
    participant M as tenant.member / tenant.role
    participant T as tenant.token
    participant B as SubstituteBindings
    participant K as Controller
    participant S as TenantScope

    C->>A: request with credentials and X-Tenant-Id: acme
    A-->>C: 401 if not authenticated
    A->>R: authenticated user
    R->>R: resolver chain: header → route → session → subdomain
    R-->>C: 400 no identifier found (checked: …)
    R->>L: locate("acme")
    L->>L: validate shape (uuid / ten_… / global id / slug)
    L-->>R: TenantRecord or null (unknown ids cached briefly)
    R-->>C: 404 Tenant not found.
    R-->>C: 403 Tenant is suspended.
    R->>R: activate TenantContext (tenant id, source: header)
    R->>M: context active
    M->>M: membership for (user, tenant): status, expiry, role
    M-->>C: 403 not a member / inactive / expired / role insufficient<br/>+ audit event tenant.access_denied
    M->>T: (only on routes with tenant.token)
    T-->>C: 403 token bound to another tenant / not bound
    T->>B: route model binding
    B->>S: Invoice::where(public_id, …)
    S->>S: AND tenant_id = active tenant
    S-->>B: model or null
    B-->>C: 404 (foreign or unknown id)
    B->>K: Invoice of *this* tenant
    K->>S: every further query on tenant-owned models
    S-->>K: rows of this tenant only
    K-->>C: 200
    Note over R,S: on response: context cleared (also for Octane)
```

## In Worten

1. **Erst Authentifizierung.** Das Framework löst niemals einen Tenant für
   eine anonyme Anfrage auf einer `tenant`-Route auf; `auth` läuft davor.
2. **Resolution wählt aus, sie gewährt nichts.** `tenant.resolve` fragt die
   konfigurierten Resolver nach einem Identifier (standardmäßig der Header).
   Der Locator validiert dessen Form, schlägt ihn nach (zuerst Cache, dann
   Datenbank) und liefert einen Datensatz mit dem Status des Tenants zurück.
   Fehlender Identifier → 400, unbekannt → 404, nicht aktiv → 403. Für den
   Rest der Anfrage wird ein `TenantContext` aktiviert.
3. **Mitgliedschaft gewährt.** `tenant.member` (Teil der Gruppe `tenant`)
   verlangt eine aktive, nicht abgelaufene Mitgliedschaft des authentifizierten
   Benutzers in diesem Tenant; `tenant.role:admin` zusätzlich eine
   ausreichende Rolle; `tenant.token` (optional), dass der Sanctum-Token an
   diesen Tenant gebunden ist. Jede Verweigerung ist ein Audit-Ereignis.
4. **Binding geschieht innerhalb des Tenants.** Das Framework ordnet seine
   Middleware direkt vor `SubstituteBindings` ein, sodass `{invoice}`
   aufgelöst wird, während der Tenant-Scope bereits aktiv ist: Die Rechnung
   eines anderen Tenants ergibt einen 404, und eine fehlerhaft gebildete
   Public ID erreicht die Datenbank nie.
5. **Der Controller kann nicht entkommen.** Jede Abfrage auf ein
   `BelongsToTenant`-Model trägt `tenant_id = <active tenant>`; ohne Kontext
   wirft der Scope (`TenantContextMissing`), statt alles zurückzugeben.
6. **Aufräumen.** Der Kontext wird gelöscht, sobald die Antwort gesendet wird,
   sowie bei Octanes Request-/Task-Terminierungs-Events.

## Was das Bild nicht sagt

- Der Scope deckt Eloquent ab. `DB::table('invoices')`, rohes SQL und Models
  ohne den Trait werden nicht gefiltert — das ist eine Code-Review-Regel, und
  die zusammengesetzten Fremdschlüssel sind das Sicherheitsnetz unterhalb von
  Eloquent.
- Die Mitgliedschaft entscheidet, *ob* ein Benutzer im Tenant handeln darf.
  *Was* er mit einer bestimmten Rechnung tun darf, bleibt unverändert Ihre
  Policy-Schicht.
- Die Rollenprüfung in Schritt 3 ist standardmäßig hierarchisch (`owner`
  erfüllt `admin`); `memberships.hierarchical = false` macht sie exakt.

Siehe auch: [Tenancy-Leitfaden](../guides/tenancy.md) ·
[Exceptions](../reference/exceptions.md) ·
[Konfiguration → Resolution, Lookup](../reference/configuration.md)
