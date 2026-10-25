<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

#[UseEloquentBuilder(PlainInvoiceBuilder::class)]
final class UnguardedBuilderInvoice extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'invoices';

    protected $guarded = [];
}
