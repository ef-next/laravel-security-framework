<!-- source: docs/en/guides/auditing.md sha256: 21964ce49e2f8cbff03bb2c405d4f942a9c0ad4339ab855c24130e3787cc039c -->
# Auditing-Leitfaden

*Deutsche Ausgabe — die [englische Fassung](../../en/guides/auditing.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Rezepte für `efnext/security-audit`: was aufgezeichnet wird, wie es
aufgezeichnet wird, was im Log landet, und wie Sie erfahren, wenn jemand
daran etwas verändert hat. Das zugrunde liegende Modell steht in
[Konzepte](../concepts.md); der Schreibpfad und die Verifikation sind in
[diagrams/audit-write-and-verification.md](../diagrams/audit-write-and-verification.md)
dargestellt.

## 1. Ein Geschäftsereignis aufzeichnen

```php
use Efnext\Security\Audit\Facades\Audit;

Audit::action('invoice.paid')
    ->on($invoice)                                    // any ExternallyAddressable model …
    ->with(['total_cents' => 12500, 'method' => 'sepa'])
    ->record();

Audit::action('auth.login_failed')
    ->on('user', $email)                              // … or an explicit type + id
    ->denied()
    ->with(['reason' => 'password'])
    ->record();
```

Für Sie ausgefüllt: der Tenant (aus dem aktiven Kontext), der Akteur (der
authentifizierte Benutzer, oder `anonymous`, oder `system('console')`), IP,
User-Agent, Request-ID und Correlation-ID, die Zeit, die Sequenznummer, der
Hash des Vorgängers, der Ereignis-Hash und die Signatur. All das ist Teil des
Hashes.

Benennung: Aktionen folgen dem Muster `noun.verb_past` in lower snake case
(`invoice.paid`, `member.role_changed`); Ressourcentypen sind Substantive im
Singular, klein geschrieben. Das Log überlebt Ihren Code — wählen Sie Namen,
die Sie in fünf Jahren noch lesen können.

**Ihr Test.**

```php
it('records a paid invoice on the tenant chain', function (): void {
    Tenancy::run($this->acme->tenantId(), function (): void {
        $invoice = Invoice::create(['number' => 'A-1']);
        Audit::action('invoice.paid')->on($invoice)->record();

        $event = AuditEvent::query()->forResource('invoice', $invoice->public_id)->firstOrFail();
        expect($event->action)->toBe('invoice.paid')->and($event->sequence)->toBe(1);
    });
});
```

## 2. Geschäftsänderung und Audit-Datensatz in einer Transaktion

```php
Audit::transaction(function () use ($invoice): void {
    $invoice->markPaid();
    Audit::action('invoice.paid')->on($invoice)->record();
});
```

`Audit::transaction()` ist `DB::transaction()` plus das, was die Kette
braucht: Ist keine Transaktion offen, wiederholt es die gesamte Closure mit
gestreutem Back-off bei Nebenläufigkeitsfehlern (InnoDB-Deadlocks,
PostgreSQL-Serialisierungsfehler, MariaDBs Snapshot-Isolation-Fehler 1020),
weil gleichzeitige Schreiber desselben Tenants sich an der Kopf-Zeile der
Kette serialisieren. Innerhalb einer bestehenden Transaktion läuft es einmal.

Regeln: Die Closure kann mehr als einmal laufen — keine E-Mails, keine
API-Aufrufe, keine nicht-idempotenten Seiteneffekte darin; stellen Sie sie
erst in die Queue, nachdem sie zurückgekehrt ist. Verwenden Sie nie ein
blankes `DB::transaction()` um einen Audit-Schreibvorgang: Die
Wiederholungsregeln fehlen dann, und unter Last können die ersten Ereignisse
neuer Ketten auf InnoDB in einen Deadlock geraten. Rufen Sie für einen
brandneuen Tenant einmal `Audit::prepareChain($tenantId)` auf (wenn Sie den
Tenant erzeugen), damit die Kopf-Zeile existiert, bevor der erste Schwung an
Ereignissen eintrifft.

## 3. Akteure

```php
Audit::action('export.downloaded')->on($report)->by(Actor::service('reporting-worker'))->record();
Audit::action('retention.applied')->on('tenant', $tenantId)->bySystem('scheduler')->record();
```

```php
// Let your user model describe itself (label, service accounts, impersonation)
final class User extends Authenticatable implements AuditActor
{
    public function toAuditActor(): Actor
    {
        return $this->impersonator
            ? Actor::user($this->id, "{$this->email} (impersonated by {$this->impersonator->email})")
            : Actor::user($this->id, $this->email);
    }
}
```

Akteurtyp, ID und Label werden gehasht; Labels werden so gespeichert, wie sie
übergeben werden — legen Sie keine Geheimnisse hinein.

## 4. Metadaten: was Sie übergeben, was damit geschieht

Übergeben Sie die Felder, um die es bei dem Ereignis geht — Beträge,
Zustände, Identifier verwandter Objekte (als Public IDs). Niemals
`$request->all()`, niemals ganze Models.

Der Sanitiser wendet danach, in dieser Reihenfolge, an: Er schwärzt
Schlüssel, deren Tokens auf ein Geheimnis-Muster passen (`password`,
`token`, `api_key`, `authorization`, `iban`, `cvv`, …) → `[REDACTED]`;
pseudonymisiert Identifier (`email`, `username`, `phone`, …) zu `email_hash`
= HMAC des Werts mit einem von `APP_KEY` (oder `AUDIT_PSEUDONYM_KEY`)
abgeleiteten Pepper; schwärzt *Werte*, die wie Geheimnisse aussehen,
unabhängig vom Schlüssel (JWTs, Bearer-Strings, PEM-Schlüssel, Kartennummern,
Cloud-Keys); begrenzt Tiefe (6), Schlüssel pro Ebene (100) und Stringlänge
(1.024).

```php
->with(['customer_email' => 'zoe@example.test', 'card_number' => '4111 1111 1111 1111', 'note' => 'ok'])
// stored: {"customer_email_hash": "9f2c…", "card_number": "[REDACTED]", "note": "ok"}
```

Metadaten sind unveränderlich und *bereinigt, nicht verschlüsselt*.
Personenbezogene Daten, die löschbar sein müssen, gehören außerhalb des
Logs, referenziert per Public ID; das Rotieren von `AUDIT_PSEUDONYM_KEY` nach
Ablauf der Aufbewahrungsfrist kommt dem Crypto-Shredding am nächsten, das das
Design bietet. Wenn Sie den vorherigen Zustand einer Ressource benötigen,
legen Sie selbst ein Diff in die Metadaten.

## 5. Tenantlose und globale Ereignisse

Unter der Standard-Policy (`AUDIT_TENANT_POLICY=required`) wird ein Ereignis
ohne Tenant verweigert — das fängt Codepfade ab, die ihren Kontext verloren
haben. Ereignisse, die wirklich tenantlos sind, gehen explizit auf die
`global`-Kette:

```php
Audit::action('deploy.completed')->on('release', 'v1.0.0-beta.1')->global()->bySystem('ci')->record();
```

Die eigenen Sicherheitsereignisse des Tenant-Pakets
(`tenant.access_denied`, `tenant.scope_bypassed`, `tenant.membership.*`,
`tenant.switched`) werden automatisch aufgezeichnet und landen auf der
globalen Kette, wenn kein Tenant bekannt ist, unabhängig davon, was die
Policy sagt.

## 6. Das Log lesen

```php
AuditEvent::query()->forResource('invoice', $invoice->public_id)->orderBy('sequence')->get();
AuditEvent::query()->forChain(ChainKey::fromString('tenant:'.$tenantId))->where('action', 'like', 'invoice.%')->latest('occurred_at')->paginate();
```

`AuditEvent` ist nur lesbar (Aktualisieren oder Löschen wirft eine
Exception). Spalten: `chain_key`, `tenant_id`, `sequence`, `previous_hash`,
`event_hash`, `hash_version`, `key_id`, `signature`, `occurred_at`,
`occurred_at_us`, `recorded_at`, `actor_type`, `actor_id`, `actor_label`,
`action`, `resource_type`, `resource_id`, `outcome`, `metadata` (kanonisches
JSON), `request_id`, `correlation_id`, `ip_address`, `user_agent`.
`recorded_at` ist die einzige beschreibende Spalte außerhalb des Hashes (sie
wird vom Server nach dem Hashing gesetzt).

## 7. Verifizieren

```bash
php artisan audit:verify                       # every chain
php artisan audit:verify --chain=acme-uuid --from=1000
```

```php
$report = Audit::verifyTenant($tenantId);       // or verifyGlobal(), verify(ChainKey, from, to)
$report->isIntact();                           // false → look at ->violations (reason, sequence, event id, details)
```

Was Ereignis für Ereignis geprüft wird: Sequenz-Kontinuität, Vorgänger-Hash,
neu berechneter Ereignis-Hash, Signatur (gültig / unbekannter Schlüssel /
fehlend), Hash-Version; und am Ende: Die Kopf-Zeile stimmt mit dem letzten
Ereignis überein, der neueste Anker stimmt mit der Kette bei dieser Sequenz
überein und liegt nicht vor ihr. Jeder Lauf wird in
`audit_verification_runs` aufgezeichnet; `security:doctor` verwendet diese
Datensätze für seine Prüfung `verification_recency` und verifiziert selbst
die letzten 200 Ereignisse jeder Kette.

Große Installationen verifizieren inkrementell: Halten Sie die zuletzt
verifizierte Sequenz pro Kette und rufen Sie `Audit::verify($chain,
$lastVerified + 1)` auf; der Verifier prüft die Vorgänger-Verknüpfung,
sodass sich die Teile aneinanderreihen.

**Alerting.** Eine Kette mit Verstößen löst `ChainViolationDetected` aus
(der Report ist enthalten) und lässt `audit:verify` mit Exit-Code 1 enden.
Hören Sie auf das Event; alarmieren Sie jemanden. Was als Nächstes zu tun
ist, steht in
[operations/incident-response.md](../operations/incident-response.md).

## 8. Anker

`audit:anchor` kopiert den Kopf jeder Kette (Sequenz, Hash, Signatur) in die
konfigurierten Stores. Mit nur `database` kann ein Angreifer, der die Kette
umschreiben kann, auch den Anker umschreiben, deshalb warnt der Doctor. Das
Produktions-Setup ist `AUDIT_ANCHOR_STORES=filesystem,database` mit einer
Disk auf Objektspeicher mit Object Lock, und
`AUDIT_REQUIRE_EXTERNAL_ANCHOR=true`, sodass die Produktion ohne ihn den
Start verweigert. Das kopierbare Rezept steht im
[Produktionsleitfaden](production.md).

## 9. Signaturschlüssel

```bash
php artisan audit:key:generate --id=k2     # rotation: append to AUDIT_SIGNING_KEYS, move AUDIT_SIGNING_ACTIVE_KEY
```

Alte Schlüssel bleiben in der Liste, damit alte Signaturen weiterhin
verifizieren; entfernen Sie einen erst, wenn jedes damit signierte Ereignis
das Aufbewahrungsfenster verlassen hat. Die Schlüssel müssen in einem
Secret-Manager liegen, nie neben den Datenbank-Zugangsdaten — ein Angreifer
auf Datenbankebene kann mit dem Schlüssel die Historie konsistent
umschreiben. Details in
[operations/key-rotation.md](../operations/key-rotation.md).

## 10. Separate Audit-Verbindung

`AUDIT_DB_CONNECTION` verlagert die Audit-Tabellen in eine eigene Datenbank
und einen eigenen Benutzer (Rechte: `SELECT, INSERT` auf Events, Anchors und
Runs; `SELECT, INSERT, UPDATE` auf `audit_chain_heads`). Sie gewinnen
Privilegientrennung und verlieren Atomarität mit Geschäfts-Schreibvorgängen:
`Audit::transaction()` kann sich nicht über zwei Verbindungen erstrecken,
sodass ein Absturz zwischen den beiden Schreibvorgängen den einen ohne den
anderen zurücklässt. Der Doctor sagt Ihnen, welchen Kompromiss Sie
eingegangen sind.

## Was das Audit-Paket nicht tut

- Manipulation verhindern (es macht sie nachweisbar — führen Sie
  `audit:verify` aus);
- signieren oder ankern ohne Schlüssel und ohne den Scheduler;
- irgendetwas aufzeichnen, das Sie ihm nicht mitteilen (außer den
  Sicherheitsereignissen des Tenant-Pakets);
- Metadaten verschlüsseln oder Löschung lösen;
- bei jedem Schreibvorgang verifizieren (die Verifikation ist geplant und in
  Blöcke aufgeteilt).

Vollständige Liste: [Was es nicht tut](../what-it-does-not-do.md).
