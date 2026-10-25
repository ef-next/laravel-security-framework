<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Contracts;

use Efnext\Security\Tenant\Resolution\Candidate;
use Illuminate\Http\Request;

/**
 * Finds a raw tenant identifier in a request. Does not validate or look it up.
 */
interface TenantResolver
{
    public function resolve(Request $request): ?Candidate;

    /** Human-readable description used in error messages, e.g. "header X-Tenant-Id". */
    public function describe(): string;
}
