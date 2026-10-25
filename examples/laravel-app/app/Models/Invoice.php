<?php

declare(strict_types=1);

namespace App\Models;

use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;
use Efnext\Security\Addressing\Eloquent\HasPublicId;
use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $tenant_id
 * @property int $customer_id
 * @property string $public_id
 * @property string $number
 * @property int $total_cents
 * @property string $status draft | open | paid
 * @property \Illuminate\Support\Carbon|null $paid_at
 */
class Invoice extends Model implements ExternallyAddressable
{
    use BelongsToTenant;
    use HasGlobalObjectId;
    use HasPublicId;

    protected $fillable = ['customer_id', 'number', 'total_cents', 'status'];

    protected $attributes = ['status' => 'open'];

    public static function publicIdPrefix(): string
    {
        return 'inv';
    }

    public static function resourceType(): string
    {
        return 'invoice';
    }

    protected function casts(): array
    {
        return [
            'total_cents' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * The business change. The audit record for it is written by the caller inside
     * the same Audit::transaction() (see InvoiceController::pay()).
     */
    public function markPaid(): void
    {
        $this->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
    }
}
