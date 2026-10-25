<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Events;

use Efnext\Security\Tenant\Context\TenantContext;

/**
 * Fired when code enters Tenancy::withoutTenant(). Audit it.
 */
final readonly class TenantScopeBypassed
{
    public function __construct(
        public string $reason,
        public ?TenantContext $context,
        public string $caller,
    ) {}
}
