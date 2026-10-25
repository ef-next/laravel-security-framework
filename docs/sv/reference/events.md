<!-- source: docs/en/reference/events.md sha256: 7dd18d5527290ac6b539e67814bd19c161b3345928ee9d0bb5eece78e941b45f -->
# Händelser

*Svensk utgåva — den [engelska versionen](../../en/reference/events.md) gäller vid avvikelser. Kod, kommandon, konfigurationsnycklar, meddelanden och diagramtexter är på engelska.*

Alla händelser är `final readonly`-klasser med publika
konstruktoregenskaper. ”Loggas” (audited) betyder att audit-paketet
registrerar händelsen automatiskt (`audit.record_tenant_events`, på som
standard); händelser utan tenant skrivs till `global`-kedjan oavsett vad
`audit.tenant_policy` säger.

## Tenant-paketet — `Efnext\Security\Tenant\Events`

| Händelse | Egenskaper | Skickas när | Loggas som |
| --- | --- | --- | --- |
| `TenantContextActivated` | `TenantContext $context` | en kontext aktiveras (`Tenancy::activate()`, `Tenancy::run()`, jobbstart) | registreras inte |
| `TenantScopeBypassed` | `string $reason`, `?TenantContext $context`, `string $caller` (`file.php:line` för den anropande koden) | `Tenancy::withoutTenant('reason', …)` anropas | `tenant.scope_bypassed`, outcome `allowed`, metadata `{reason, caller}`; på den globala kedjan när ingen tenant var aktiv |
| `TenantAccessDenied` (händelse; undantaget har samma korta namn) | `?TenantId $tenantId`, `?string $actorId`, `DenialReason $reason`, `list<string> $requiredRoles` | `tenant.member`, `tenant.role`, `tenant.token` eller `SessionTenant::switchTo()` nekar en request | `tenant.access_denied`, outcome `denied`, metadata `{reason, actor_id, required_roles}` |
| `MembershipChanged` | `string $change` (`granted`, `revoked`, `role_changed`, `status_changed`), `TenantId $tenantId`, `string $actorId`, `?string $role`, `?string $previousRole`, `?string $status` | `grantTenantMembership()`, `changeTenantMembershipStatus()`, `revokeTenantMembership()` | `tenant.membership.<change>`, resource `tenant_membership/<actorId>` |
| `TenantSwitched` | `string $actorId`, `?TenantId $from`, `TenantId $to` | `SessionTenant::switchTo()` | `tenant.switched`, metadata `{from, actor_id}` |

`DenialReason`-värden: `unauthenticated`, `no_membership`,
`membership_inactive`, `membership_expired`, `role_insufficient`,
`token_not_bound`, `token_tenant_mismatch`.

## Audit-paketet — `Efnext\Security\Audit\Events`

| Händelse | Egenskaper | Skickas när | Används till |
| --- | --- | --- | --- |
| `AuditEventRecorded` | `AuditEvent $event` | inuti skrivtransaktionen, efter att kedjans huvud har flyttats fram | att strömma händelser till ett SIEM, mätvärden; gör inget långsamt arbete här, transaktionen är fortfarande öppen |
| `ChainViolationDetected` | `VerificationReport $report` | `audit:verify`, doctors tail-kontroll eller `Audit::verify*()` hittar en kedja som inte är intakt | **larmning** — det här är signalen som hela designen finns för |

Addressing-paketet skickar inga händelser.

## Lyssna

```php
use Efnext\Security\Audit\Events\ChainViolationDetected;

Event::listen(ChainViolationDetected::class, function (ChainViolationDetected $event): void {
    Alert::page('audit-integrity', $event->report->chainKey->toString(), $event->report->toArray());
});
```

Lyssnare körs synkront; köa allt som kommunicerar med nätverket.
