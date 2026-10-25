<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fully guarded model: mass assignment is refused altogether and must stay that way.
 */
final class LockedInvoice extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'invoices';

    protected $guarded = ['*'];
}
