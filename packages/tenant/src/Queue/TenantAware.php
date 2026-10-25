<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Queue;

use Efnext\Security\Tenant\Context\TenantId;

/**
 * A job that decides its own tenant. Add TenantContextMiddleware to the job's
 * middleware() and the tenant returned here is activated before handle() runs,
 * taking precedence over any propagated context.
 */
interface TenantAware
{
    public function tenantId(): TenantId;
}
