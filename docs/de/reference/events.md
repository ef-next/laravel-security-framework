<!-- source: docs/en/reference/events.md sha256: 7dd18d5527290ac6b539e67814bd19c161b3345928ee9d0bb5eece78e941b45f -->
# Ereignisse

*Deutsche Ausgabe — die [englische Fassung](../../en/reference/events.md) ist maßgeblich. Code, Befehle, Konfigurationsschlüssel, Meldungen und Diagrammbeschriftungen bleiben englisch.*

Alle Ereignisse sind `final readonly` Klassen mit öffentlichen
Konstruktor-Eigenschaften. „Auditiert“ bedeutet, dass das Audit-Paket das
Ereignis automatisch aufzeichnet (`audit.record_tenant_events`, standardmäßig
an); Ereignisse ohne Tenant werden unabhängig davon, was
`audit.tenant_policy` sagt, in die `global`-Kette geschrieben.

## Tenant-Paket — `Efnext\Security\Tenant\Events`

| Ereignis | Eigenschaften | Ausgelöst, wenn | Auditiert als |
| --- | --- | --- | --- |
| `TenantContextActivated` | `TenantContext $context` | ein Kontext aktiviert wird (`Tenancy::activate()`, `Tenancy::run()`, Job-Start) | nicht aufgezeichnet |
| `TenantScopeBypassed` | `string $reason`, `?TenantContext $context`, `string $caller` (`file.php:line` des aufrufenden Codes) | `Tenancy::withoutTenant('reason', …)` betreten wird | `tenant.scope_bypassed`, outcome `allowed`, metadata `{reason, caller}`; auf der globalen Kette, wenn kein Tenant aktiv war |
| `TenantAccessDenied` (Ereignis; die Exception trägt denselben Kurznamen) | `?TenantId $tenantId`, `?string $actorId`, `DenialReason $reason`, `list<string> $requiredRoles` | `tenant.member`, `tenant.role`, `tenant.token` oder `SessionTenant::switchTo()` eine Anfrage abweisen | `tenant.access_denied`, outcome `denied`, metadata `{reason, actor_id, required_roles}` |
| `MembershipChanged` | `string $change` (`granted`, `revoked`, `role_changed`, `status_changed`), `TenantId $tenantId`, `string $actorId`, `?string $role`, `?string $previousRole`, `?string $status` | `grantTenantMembership()`, `changeTenantMembershipStatus()`, `revokeTenantMembership()` | `tenant.membership.<change>`, resource `tenant_membership/<actorId>` |
| `TenantSwitched` | `string $actorId`, `?TenantId $from`, `TenantId $to` | `SessionTenant::switchTo()` | `tenant.switched`, metadata `{from, actor_id}` |

Werte von `DenialReason`: `unauthenticated`, `no_membership`,
`membership_inactive`, `membership_expired`, `role_insufficient`,
`token_not_bound`, `token_tenant_mismatch`.

## Audit-Paket — `Efnext\Security\Audit\Events`

| Ereignis | Eigenschaften | Ausgelöst, wenn | Verwendung |
| --- | --- | --- | --- |
| `AuditEventRecorded` | `AuditEvent $event` | innerhalb der Schreibtransaktion, nachdem der Kettenkopf vorangeschritten ist | das Streamen von Ereignissen an ein SIEM, Metriken; erledigen Sie hier keine langsame Arbeit, die Transaktion ist noch offen |
| `ChainViolationDetected` | `VerificationReport $report` | `audit:verify`, die Tail-Prüfung des Doctors oder `Audit::verify*()` finden eine Kette, die nicht intakt ist | **Alerting** — dies ist das Signal, für das die gesamte Konstruktion existiert |

Das Addressing-Paket löst keine Ereignisse aus.

## Lauschen

```php
use Efnext\Security\Audit\Events\ChainViolationDetected;

Event::listen(ChainViolationDetected::class, function (ChainViolationDetected $event): void {
    Alert::page('audit-integrity', $event->report->chainKey->toString(), $event->report->toArray());
});
```

Listener laufen synchron; alles, was mit dem Netzwerk spricht, gehört in eine
Queue.
