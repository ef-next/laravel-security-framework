<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Opts the tenant column into mass assignment explicitly (imports, admin tooling).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $number
 */
final class FillableInvoice extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'invoices';

    protected $fillable = ['number', 'total', 'tenant_id'];
}
