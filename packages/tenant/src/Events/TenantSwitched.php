<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Events;

use Efnext\Security\Tenant\Context\TenantId;

/**
 * Fired when a user deliberately switches the tenant remembered in the session.
 */
final readonly class TenantSwitched
{
    public function __construct(
        public string $actorId,
        public ?TenantId $from,
        public TenantId $to,
    ) {}
}
