<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Listeners;

use Efnext\Security\Audit\Contracts\AuditWriter;
use Efnext\Security\Audit\Enums\Outcome;
use Efnext\Security\Audit\Records\AuditEntry;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Events\MembershipChanged;
use Efnext\Security\Tenant\Events\TenantAccessDenied;
use Efnext\Security\Tenant\Events\TenantScopeBypassed;
use Efnext\Security\Tenant\Events\TenantSwitched;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Turns the tenant package's security events into audit records:
 *
 *   tenant.access_denied        (denied)   membership/role/token checks
 *   tenant.scope_bypassed       (allowed)  Tenancy::withoutTenant() entered
 *   tenant.membership.granted / role_changed / status_changed / revoked
 *   tenant.switched             (allowed)  session tenant switch
 *
 * Events without a tenant (a bypass entered outside any tenant context, a
 * denial for an unknown tenant) are recorded on the global chain regardless of
 * audit.tenant_policy: they are the most security-relevant events of all and
 * must never be dropped silently.
 */
final class RecordTenantSecurityEvents
{
    public function __construct(
        private readonly AuditWriter $writer,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(TenantAccessDenied::class, [$this, 'onAccessDenied']);
        $events->listen(TenantScopeBypassed::class, [$this, 'onScopeBypassed']);
        $events->listen(MembershipChanged::class, [$this, 'onMembershipChanged']);
        $events->listen(TenantSwitched::class, [$this, 'onTenantSwitched']);
    }

    public function onAccessDenied(TenantAccessDenied $event): void
    {
        $this->write(
            tenantId: $event->tenantId,
            action: 'tenant.access_denied',
            resourceType: 'tenant',
            resourceId: $event->tenantId?->toString() ?? 'unknown',
            outcome: Outcome::Denied,
            metadata: [
                'reason' => $event->reason->value,
                'actor_id' => $event->actorId,
                'required_roles' => $event->requiredRoles,
            ],
        );
    }

    public function onScopeBypassed(TenantScopeBypassed $event): void
    {
        $this->write(
            tenantId: $event->context?->tenantId,
            action: 'tenant.scope_bypassed',
            resourceType: 'tenant',
            resourceId: $event->context?->tenantId->toString() ?? 'all',
            outcome: Outcome::Allowed,
            metadata: ['reason' => $event->reason, 'caller' => $event->caller],
        );
    }

    public function onMembershipChanged(MembershipChanged $event): void
    {
        $this->write(
            tenantId: $event->tenantId,
            action: 'tenant.membership.'.$event->change,
            resourceType: 'tenant_membership',
            resourceId: $event->actorId,
            outcome: Outcome::Allowed,
            metadata: array_filter([
                'role' => $event->role,
                'previous_role' => $event->previousRole,
                'status' => $event->status,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    public function onTenantSwitched(TenantSwitched $event): void
    {
        $this->write(
            tenantId: $event->to,
            action: 'tenant.switched',
            resourceType: 'tenant',
            resourceId: $event->to->toString(),
            outcome: Outcome::Allowed,
            metadata: ['from' => $event->from?->toString(), 'actor_id' => $event->actorId],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function write(?TenantId $tenantId, string $action, string $resourceType, string $resourceId, Outcome $outcome, array $metadata): void
    {
        $this->writer->record(new AuditEntry(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            outcome: $outcome,
            metadata: $metadata,
            tenantId: $tenantId,
            global: $tenantId === null,
        ));
    }
}
