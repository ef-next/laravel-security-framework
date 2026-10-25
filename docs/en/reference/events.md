# Events

All events are `final readonly` classes with public constructor properties.
"Audited" means the audit package records the event automatically
(`audit.record_tenant_events`, on by default); events without a tenant are
written to the `global` chain whatever `audit.tenant_policy` says.

## Tenant package — `Efnext\Security\Tenant\Events`

| Event | Properties | Dispatched when | Audited as |
| --- | --- | --- | --- |
| `TenantContextActivated` | `TenantContext $context` | a context is activated (`Tenancy::activate()`, `Tenancy::run()`, job start) | not recorded |
| `TenantScopeBypassed` | `string $reason`, `?TenantContext $context`, `string $caller` (`file.php:line` of the calling code) | `Tenancy::withoutTenant('reason', …)` is entered | `tenant.scope_bypassed`, outcome `allowed`, metadata `{reason, caller}`; on the global chain when no tenant was active |
| `TenantAccessDenied` (event; the exception has the same short name) | `?TenantId $tenantId`, `?string $actorId`, `DenialReason $reason`, `list<string> $requiredRoles` | `tenant.member`, `tenant.role`, `tenant.token` or `SessionTenant::switchTo()` deny a request | `tenant.access_denied`, outcome `denied`, metadata `{reason, actor_id, required_roles}` |
| `MembershipChanged` | `string $change` (`granted`, `revoked`, `role_changed`, `status_changed`), `TenantId $tenantId`, `string $actorId`, `?string $role`, `?string $previousRole`, `?string $status` | `grantTenantMembership()`, `changeTenantMembershipStatus()`, `revokeTenantMembership()` | `tenant.membership.<change>`, resource `tenant_membership/<actorId>` |
| `TenantSwitched` | `string $actorId`, `?TenantId $from`, `TenantId $to` | `SessionTenant::switchTo()` | `tenant.switched`, metadata `{from, actor_id}` |

`DenialReason` values: `unauthenticated`, `no_membership`, `membership_inactive`,
`membership_expired`, `role_insufficient`, `token_not_bound`,
`token_tenant_mismatch`.

## Audit package — `Efnext\Security\Audit\Events`

| Event | Properties | Dispatched when | Use it for |
| --- | --- | --- | --- |
| `AuditEventRecorded` | `AuditEvent $event` | inside the write transaction, after the chain head has been advanced | streaming events to a SIEM, metrics; do not do slow work here, the transaction is still open |
| `ChainViolationDetected` | `VerificationReport $report` | `audit:verify`, the doctor's tail check or `Audit::verify*()` find a chain that is not intact | **alerting** — this is the signal the whole design exists for |

The addressing package dispatches no events.

## Listening

```php
use Efnext\Security\Audit\Events\ChainViolationDetected;

Event::listen(ChainViolationDetected::class, function (ChainViolationDetected $event): void {
    Alert::page('audit-integrity', $event->report->chainKey->toString(), $event->report->toArray());
});
```

Listeners run synchronously; queue anything that talks to the network.
