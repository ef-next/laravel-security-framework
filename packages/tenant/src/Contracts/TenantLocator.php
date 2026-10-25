<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Contracts;

use Efnext\Security\Tenant\Locating\TenantRecord;

/**
 * Maps a raw identifier (internal id, public id, global object id or slug)
 * to an existing tenant. Returns null for unknown identifiers.
 */
interface TenantLocator
{
    public function locate(string $identifier): ?TenantRecord;
}
