<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Events;

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Membership\DenialReason;

/**
 * Fired when membership, role or token checks reject a request.
 */
final readonly class TenantAccessDenied
{
    public function __construct(
        public ?TenantId $tenantId,
        public ?string $actorId,
        public DenialReason $reason,
        /** @var list<string> */
        public array $requiredRoles = [],
    ) {}
}
