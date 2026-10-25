<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Events;

use Efnext\Security\Tenant\Context\TenantId;

/**
 * Fired for every membership mutation made through HasTenantMemberships.
 */
final readonly class MembershipChanged
{
    public const string GRANTED = 'granted';

    public const string REVOKED = 'revoked';

    public const string ROLE_CHANGED = 'role_changed';

    public const string STATUS_CHANGED = 'status_changed';

    public function __construct(
        public string $change,
        public TenantId $tenantId,
        public string $actorId,
        public ?string $role = null,
        public ?string $previousRole = null,
        public ?string $status = null,
    ) {}
}
