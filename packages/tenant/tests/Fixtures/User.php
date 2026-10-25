<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Efnext\Security\Tenant\Membership\HasTenantMemberships;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $email
 */
final class User extends Authenticatable
{
    use HasTenantMemberships;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];
}
