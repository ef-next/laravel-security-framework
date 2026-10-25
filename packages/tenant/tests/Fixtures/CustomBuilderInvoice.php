<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $number
 */
#[UseEloquentBuilder(GuardedInvoiceBuilder::class)]
final class CustomBuilderInvoice extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'invoices';

    protected $guarded = [];
}
