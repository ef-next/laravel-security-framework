<?php

declare(strict_types=1);

namespace App\Models;

use Efnext\Security\Tenant\Membership\HasTenantMemberships;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The skeleton's User model plus two traits:
 *
 *  - HasTenantMemberships: which tenants this user may act in, and with which role
 *    (grantTenantMembership(), hasTenantRole(), tenants(), ...).
 *  - HasApiTokens (Sanctum): the example authenticates API requests with tokens; a
 *    token can be bound to one tenant with the "tenant:<uuid>" ability.
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasTenantMemberships;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
