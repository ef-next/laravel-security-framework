<?php

declare(strict_types=1);

namespace App\Models;

use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;
use Efnext\Security\Addressing\Eloquent\HasPublicId;
use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant-owned model with a public id.
 *
 *  - BelongsToTenant: every query carries "tenant_id = <active tenant>"; without an
 *    active tenant the query throws instead of returning everything. The tenant column
 *    is set from the context on create and cannot be changed afterwards.
 *  - HasPublicId: "cus_7f3k9zq2m4x8c1bt", assigned on create, used for route binding.
 *  - HasGlobalObjectId: "billing:customer:cus_…" for other services and the audit log.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $public_id
 * @property string $name
 * @property string $email
 */
class Customer extends Model implements ExternallyAddressable
{
    use BelongsToTenant;
    use HasGlobalObjectId;
    use HasPublicId;

    // tenant_id is deliberately NOT fillable: the framework sets it from the active context.
    protected $fillable = ['name', 'email'];

    public static function publicIdPrefix(): string
    {
        return 'cus';
    }

    public static function resourceType(): string
    {
        return 'customer';
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
