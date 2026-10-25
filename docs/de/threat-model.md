<!-- source: docs/en/threat-model.md sha256: caf952ccd030a169aaa07d1cd250683f8a4ea58ee8b759c253dbc8a8e703483c -->
# Bedrohungsmodell

*Deutsche Ausgabe — die [englische Fassung](../en/threat-model.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Schutzgüter: Tenant-Daten (Vertraulichkeit, Integrität), der Audit-Trail
(Integrität, Verfügbarkeit der Beweise), Tenant- und
Mitgliedschaftsdatensätze (Integrität).

## Angreifer und was das Framework dagegen tut

| Angreifer | Fähigkeit | Relevante Kontrollen | Restrisiko |
| --- | --- | --- | --- |
| **A1 – Fremder Tenant-Benutzer** | Authentifizierter Benutzer von Tenant (Mandant) B, der Anfragen mit dem Identifier von Tenant A sendet (Header, Route, Subdomain). | `ResolveTenant` + `EnsureTenantMember`: Mitgliedschaftsprüfung vor dem Controller; 403 und ein Audit-Ereignis bei Fehlschlag. `TenantScope` bei jeder Model-Abfrage. `EnsureTokenTenant` für tenant-gebundene API-Tokens. | Routen, denen die Middleware-Gruppe `tenant` fehlt. Globale Models, die eigentlich tenant-gebunden hätten sein sollen. |
| **A2 – Enumerierender Außenstehender** | Unauthentifizierte Anfragen, die Tenant-Identifier oder Resource-IDs erraten. | Public IDs mit 80 Bit Entropie; 404 ohne Echo des Identifiers; Negative-Cache für unbekannte Identifier; fehlerhaft geformte Identifier erreichen nie die Datenbank. | Unterschiede in der Antwortzeit zwischen „unbekannter Tenant“ und „bekannter Tenant, keine Mitgliedschaft“ (404 vs. 403, so vorgesehen). Rate Limiting ist Aufgabe der Anwendung. |
| **A3 – Unachtsame Entwicklerin oder unachtsamer Entwickler** | Vergisst Middleware, ein `where`, oder führt einen Befehl/Job ohne Tenant aus. | Fail-closed-Scope: kein Tenant bedeutet `TenantContextMissing`, kein Lesen der gesamten Tabelle. Unveränderliche Tenant-Spalte. Tenant-Spalte von Mass Assignment ausgeschlossen. Queue-Weitergabe standardmäßig aktiv. Architektur-Tests. | `DB::table()`, rohes SQL, `withoutGlobalScope()`. Das sind Punkte für das Code-Review; das sagt auch die Dokumentation. |
| **A4 – Böswillige Innentäterin oder böswilliger Innentäter mit Anwendungszugriff** | Kann Code deployen oder Tinker ausführen. | `withoutTenant()` erfordert einen Grund und wird mit dem Ort des Aufrufers auditiert; ein Bypass wird nie an Jobs weitergegeben. | Kann durch eine Bibliothek nicht verhindert werden. Der Audit-Trail ist die Kontrolle. |
| **A5 – Angreifer auf Datenbankebene ohne den Signaturschlüssel** | Kann nach dem Entfernen der Trigger `UPDATE`/`DELETE` ausführen; kann Hashes neu berechnen. | Trigger (erste Hürde). HMAC-Signaturen über jeden Ereignis-Hash mit einem Schlüssel außerhalb der Datenbank: neu berechnete Hashes bestehen die Signaturprüfung nicht. Kettenkopf und externe Anker: Abschneiden wird erkannt. | Besitzt der Angreifer auch den Signaturschlüssel (derselbe Secret-Store, derselbe Host), können Signaturen gefälscht werden. Halten Sie den Schlüssel getrennt; ankern Sie extern. |
| **A6 – Angreifer auf Datenbankebene mit dem Signaturschlüssel** | Alles, was A5 kann, plus gültige Signaturen. | Externe Anker in einem Store, den der Angreifer nicht erreichen kann (Object Lock, Log-Senke). `AnchorMismatch`/`AnchorAhead` bei der Verifikation. | Ereignisse nach dem letzten Anker können unerkannt umgeschrieben werden. Häufig ankern. |
| **A7 – Kompromittierter Anwendungsserver** | Volle Kontrolle über den PHP-Prozess, einschließlich des Signaturschlüssels im Speicher. | Innerhalb des Prozesses keine. Externe Anker begrenzen das Zeitfenster; eine Verifikation von einem separaten Host erkennt Umschreibungen, die älter sind als der letzte Anker. | Alles innerhalb des Anker-Intervalls. Trennen Sie den Schlüsselzugriff des Verifiers von der Anwendung, falls dieser Angreifer für Sie relevant ist. |
| **A8 – Uhrmanipulation / Uhrenabweichung** | Server mit falschen Uhren. | Reihenfolge nach Sequenz, nie nach Zeit. `occurredAt`, das mehr als fünf Minuten in der Zukunft liegt, wird abgelehnt. | Ein in die Vergangenheit datiertes `occurredAt` wird akzeptiert und wie angegeben gehasht; die Sequenz zeigt weiterhin die wahre Reihenfolge. |
| **A9 – Log-Poisoning** | Angreifer kontrolliert Metadaten-Inhalte (z. B. Benutzereingaben), um Secrets oder riesige Payloads einzuschleusen. | Sanitizer: Key-Patterns, secret-förmige Werte, Grenzen für Tiefe/Schlüssel/Länge, Steuerzeichen aus dem Request-Kontext entfernt. | Secrets unter unbekannten Schlüsseln, die keinem bekannten Muster entsprechen. Keine Rohdaten von Eingaben loggen. |

## Explizit außerhalb des Anwendungsbereichs

- Authentifizierung (verwenden Sie Laravels Guards, Sanctum, Passport, SSO).
- Autorisierung innerhalb eines Tenants (Policies, Gates).
- Kontrollen auf Netzwerkebene, WAF, Rate Limiting.
- Verschlüsselung im Ruhezustand, Backups, Disaster Recovery.
- Isolation nach dem Prinzip einer Datenbank pro Tenant.

## Annahmen

- Die Anwendung bootet die drei Service Provider und führt die Migrationen
  aus.
- Tenant-gebundene Models verwenden `BelongsToTenant`; globale Models sind
  bewusst global.
- Routen, die Tenant-Daten berühren, verwenden nach der Authentifizierung
  die Middleware-Gruppe `tenant`.
- `audit:verify` und `audit:anchor` sind eingeplant, und ihre Fehlschläge
  alarmieren jemanden.
- `AUDIT_SIGNING_KEYS` wird in einem Secret-Manager gespeichert, nicht in
  der Datenbank und nicht neben den Datenbank-Zugangsdaten.
