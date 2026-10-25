<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Efnext\Security\Tenant\Eloquent\GuardsTenantColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * A custom builder that keeps the tenant guard: accepted by BelongsToTenant.
 *
 * @extends Builder<CustomBuilderInvoice>
 */
final class GuardedInvoiceBuilder extends Builder
{
    use GuardsTenantColumn;

    public function numbered(string $number): self
    {
        $this->where('number', $number);

        return $this;
    }
}
