<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $tenant_id
 * @property int $invoice_id
 * @property string $description
 */
final class InvoiceLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'invoice_lines';

    protected $guarded = [];
}
