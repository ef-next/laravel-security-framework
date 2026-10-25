<!-- source: docs/en/operations/key-rotation.md sha256: c12de75137df303747f5af56eb6c28219ab7d499b7c7b35c8b539d910773dbf3 -->
# Schlüsselrotation

*Deutsche Ausgabe — die [englische Fassung](../../en/operations/key-rotation.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Das Audit-Paket signiert jedes Ereignis und jeden Anker mit einem
HMAC-Schlüssel aus einem **Schlüsselbund** (Keyring). Rotation ist bewusst
unspektakulär gestaltet: einen Schlüssel hinzufügen, ihn aktiv setzen,
deployen. Nichts wird neu signiert, nichts wird migriert, und alte
Signaturen bleiben verifizierbar, solange ihr Schlüssel im Ring verbleibt.

## Der Schlüsselbund

```dotenv
AUDIT_SIGNING_KEYS="k1:base64:<32+ random bytes>,k2:base64:<32+ random bytes>"
AUDIT_SIGNING_ACTIVE_KEY=k2
```

- Einträge sind durch Kommas getrennt, jeweils `<id>:base64:<key>`. IDs
  entsprechen `/^[A-Za-z0-9_.-]{1,32}$/`; Schlüssel sind nach der Dekodierung
  mindestens 32 Byte lang.
- **Der aktive Schlüssel signiert.** Jeder andere Schlüssel im Ring
  verifiziert nur.
- Jedes Ereignis und jeder Anker speichert die `key_id`, mit der es signiert
  wurde, sodass der Verifier selbstständig den richtigen Schlüssel wählt.
- Ein fehlerhaft geformter Eintrag, ein zu kurzer Schlüssel, eine aktive ID,
  die nicht im Ring vorhanden ist, oder Schlüssel ohne aktive ID →
  `InvalidSigningKey` beim Boot. Das Framework startet nicht mit einem
  defekten Ring; es fällt nicht in einen unsignierten Modus zurück.

Einen Schlüssel erzeugen:

```bash
php artisan audit:key:generate --id=k2
# prints two lines to append / set:
#   AUDIT_SIGNING_KEYS="k2:base64:…"
#   AUDIT_SIGNING_ACTIVE_KEY=k2
```

Der Befehl gibt den Wert aus, er schreibt ihn nicht. Legen Sie den Wert in
Ihrem Secret-Manager ab; die Anwendung liest ihn beim Boot aus der Umgebung.

## Wo der Schlüssel liegt

Der Schlüssel darf nicht von dort lesbar sein, wo auch die Datenbank lesbar
ist. Das ist die wichtigste betriebliche Eigenschaft des Audit-Logs
überhaupt: Ein Angreifer mit Datenbankzugriff *und* dem Schlüssel kann die
Historie bis zum letzten externen Anker konsistent umschreiben, und die
Signaturen werden es Ihnen nicht verraten. Konkret:

- Secret-Manager (Vault, AWS Secrets Manager, GCP Secret Manager, …) →
  Umgebungsvariable beim Boot. Nicht in der Datenbank, nicht in einer
  Config-Tabelle, nicht in einer Datei, die der Webserver ausliefern kann.
- Ein **anderer Vault-Pfad / eine andere IAM-Policy** als die
  Datenbank-Zugangsdaten. Der Anwendungshost hält beides zur Laufzeit — das
  ist unvermeidlich —, aber ein DBA, ein Backup-Operator oder ein
  durchgesickerter Datenbank-Dump dürfen nicht mit dem Schlüssel einhergehen.
- Der Verifier-Host (auf dem `audit:verify` bei einem Incident läuft) erhält
  den Ring nur lesend; er benötigt den aktiven Schlüssel nie zum Signieren.
- `AUDIT_PSEUDONYM_KEY` (der Pepper für gehashte Identifier in den Metadaten)
  ist ein zweites Secret mit denselben Regeln. Ist er nicht gesetzt, wird er
  von `APP_KEY` abgeleitet; setzen Sie ihn in der Produktion separat, damit
  eine Rotation von `APP_KEY` die Pseudonyme nicht verändert.

## Geplante Rotation

Rotieren Sie nach einem Zeitplan (jährlich ist üblich; Ihre Richtlinie
entscheidet) und bei jedem Verdacht. Der Ablauf ist derselbe:

1. **Erzeugen** Sie einen neuen Schlüssel:
   `php artisan audit:key:generate --id=k3`.
2. **Anhängen**: Fügen Sie ihn in `AUDIT_SIGNING_KEYS` im Secret-Manager
   hinzu. Behalten Sie jeden bestehenden Eintrag bei. Ändern Sie den aktiven
   Schlüssel noch nicht.
3. **Deployen** (oder neu starten), sodass jede Instanz `k3` kennt. Warten
   Sie bei mehreren Instanzen, bis alle mit dem neuen Ring laufen — eine
   Instanz, die mit `k3` signiert, während eine andere ihn noch nicht
   geladen hat, würde weiterhin gültige Ereignisse schreiben, aber der
   Doctor und der Verifier der zweiten Instanz würden bis zu deren Neustart
   `signature_key_unknown` melden.
4. **Aktivieren**: `AUDIT_SIGNING_ACTIVE_KEY=k3` setzen, erneut deployen.
5. **Verifizieren**: `php artisan security:doctor --strict` (der Check
   `signing_key` meldet `n key(s) in the ring, active: k3`) und
   `php artisan audit:verify`. Ereignisse nach dem Wechsel tragen
   `key_id = k3`; frühere verifizieren weiterhin mit `k2`.
6. **Ankern** Sie einmal explizit: `php artisan audit:anchor --force`, damit
   der erste mit dem neuen Schlüssel signierte Anker sofort existiert.

Zwei Deployments statt eines sind bewusst so gewählt: Ein Ring, der einen
Schlüssel hinzugewinnt, lässt sich immer gefahrlos schrittweise ausrollen;
ein Ring, dessen aktiver Schlüssel sich ändert, ist erst sicher, sobald jede
Instanz diesen Schlüssel kennt.

## Schlüssel ausmustern

Entfernen Sie einen Schlüssel nur dann aus dem Ring, wenn **kein damit
signiertes Ereignis und kein damit signierter Anker mehr innerhalb Ihrer
Aufbewahrungsfrist liegt**. Der Verifier meldet jedes Ereignis, dessen
`key_id` nicht im Ring ist, als `signature_key_unknown`; das ist ein
Verstoß, und `audit:verify` gibt so lange den Exit-Code 1 zurück, wie solche
Ereignisse existieren.

Vor dem Entfernen von `k1`:

```sql
SELECT chain_key, MIN(occurred_at), MAX(occurred_at), COUNT(*)
FROM audit_events WHERE key_id = 'k1' GROUP BY chain_key;
SELECT COUNT(*) FROM audit_anchors WHERE key_id = 'k1';
```

Sind beide leer (oder liegt jede Zeile außerhalb der Aufbewahrungsfrist und
wurde von Ihrem Aufbewahrungsprozess gelöscht), entfernen Sie den Eintrag,
deployen Sie, und führen Sie `audit:verify` noch einmal aus. Ein
ausgemusterter Schlüssel sollte dennoch offline archiviert werden — ein
Auditor könnte Sie Jahre später bitten, einen exportierten Bereich erneut zu
verifizieren.

## Kompromittierter Schlüssel

Behandeln Sie einen Schlüssel als kompromittiert, wenn er in einem
durchgesickerten Dump, auf einem verlorenen Laptop, in einer Log-Zeile, in
einem CI-Artefakt vorkam oder von jemandem gehalten wurde, der das
Unternehmen mit ihm verlassen hat.

1. **Sofort rotieren** (Schritte 1–6 oben, notfalls in einem Zug: `k3`
   hinzuzufügen und in einem einzigen Deployment zu aktivieren ist unter
   Druck akzeptabel — das kurze Zeitfenster von `signature_key_unknown` auf
   einer noch nicht neu gestarteten Instanz ist einer weiteren Stunde mit
   einem kompromittierten aktiven Schlüssel vorzuziehen).
2. **Entfernen Sie den kompromittierten Schlüssel noch nicht.** Die damit
   signierten Ereignisse sind weiterhin Beweismittel; die Frage ist, ob sie
   manipuliert wurden.
3. **Von einem sauberen Host aus verifizieren**: Führen Sie
   `audit:verify --json` auf einer Maschine aus, die den kompromittierten
   Schlüssel nie besaß, und lesen Sie dabei die Datenbank und den externen
   Anker-Store. Mit `k2` erstellte Signaturen verifizieren weiterhin — der
   Sinn des Laufs sind die *Anker*: Jeder vor der Kompromittierung
   geschriebene externe Anker ist eine signierte Aussage über die Kette, die
   der Angreifer in diesem Store nicht fälschen konnte. Stimmt die Kette
   weiterhin mit diesen Ankern überein, ist die Historie bis zum letzten
   Anker vor der Kompromittierung intakt.
4. **Grenzen Sie die Exposition ein**: Vom letzten Anker, dem Sie vertrauen,
   bis zur Rotation sind Ereignisse „mit einem Schlüssel signiert, den auch
   ein Angreifer hatte“. Sie sind nicht zwangsläufig falsch — aber sie
   können allein durch die Signatur nicht als wahr *bewiesen* werden. Halten
   Sie dieses Zeitfenster in Ihren Incident-Notizen fest; die Anker und die
   Anwendungslogs aus diesem Zeitraum sind die untermauernden Beweise.
5. Fahren Sie mit [Incident Response](incident-response.md) fort, falls ein
   Check fehlschlägt.

## Rotation von `APP_KEY`

`APP_KEY` ist Laravels Verschlüsselungsschlüssel, nicht der
Audit-Signaturschlüssel. Seine Rotation berührt keine Signaturen. Sie wirkt
sich jedoch auf `AUDIT_PSEUDONYM_KEY` aus, wenn dieser nicht gesetzt ist
(abgeleitet von `APP_KEY`): In Metadaten gehashte Identifier würden vor und
nach der Rotation nicht mehr übereinstimmen. Setzen Sie
`AUDIT_PSEUDONYM_KEY` explizit, *bevor* Sie `APP_KEY` zum ersten Mal
rotieren, und halten Sie ihn stabil.

## Checkliste

- [ ] Schlüssel mit `audit:key:generate` erzeugt, ≥ 32 Byte, IDs, die Sie in
      einem Bericht lesen können (`k2026-01`, nicht `key`)
- [ ] Ring und aktiver Schlüssel im Secret-Manager; Datenbank-Zugangsdaten
      auf einem anderen Pfad
- [ ] Rotation in zwei Deployments (hinzufügen, dann aktivieren) bei
      Multi-Instanz-Setups
- [ ] `security:doctor --strict` und `audit:verify` nach jedem Schritt grün
- [ ] Schlüssel erst nach Ablauf der Aufbewahrungsfrist entfernt; danach
      offline archiviert
- [ ] `AUDIT_PSEUDONYM_KEY` in der Produktion explizit gesetzt

Verwandte Themen: [Auditing-Leitfaden](../guides/auditing.md) ·
[Produktionsleitfaden](../guides/production.md) ·
[Vertrauensgrenzen](../diagrams/trust-boundaries.md) ·
[Konfiguration → Signierung](../reference/configuration.md)
