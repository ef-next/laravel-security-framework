<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Events;

use Efnext\Security\Tenant\Context\TenantContext;

final readonly class TenantContextActivated
{
    public function __construct(
        public TenantContext $context,
    ) {}
}
