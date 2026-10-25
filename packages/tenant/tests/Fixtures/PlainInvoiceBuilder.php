<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;

/**
 * A custom builder without the tenant guard: refused by BelongsToTenant.
 *
 * @extends Builder<UnguardedBuilderInvoice>
 */
final class PlainInvoiceBuilder extends Builder {}
