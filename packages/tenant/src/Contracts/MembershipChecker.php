<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Contracts;

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Membership\Membership;

/**
 * Answers "what is this actor's membership in this tenant?".
 * Null means no membership at all; status and expiry are evaluated by the caller.
 */
interface MembershipChecker
{
    public function membershipFor(string $actorId, TenantId $tenantId): ?Membership;
}
