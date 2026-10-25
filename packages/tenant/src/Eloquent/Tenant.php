<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Eloquent;

use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Addressing\Eloquent\HasGlobalObjectId;
use Efnext\Security\Addressing\Eloquent\HasPublicId;
use Efnext\Security\Addressing\Identifiers\PublicId;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Locating\CachedTenantLocator;
use Efnext\Security\Tenant\Locating\TenantRecord;
use Efnext\Security\Tenant\Locating\TenantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shipped tenant model. Extend it or configure your own in tenant.model.
 *
 * @property string $id
 * @property string $public_id
 * @property string|null $slug
 * @property string $name
 * @property string $status
 */
class Tenant extends Model implements ExternallyAddressable
{
    use HasGlobalObjectId;
    use HasPublicId;
    use HasUuids;

    protected $fillable = ['name', 'slug', 'status'];

    protected $attributes = [
        'status' => 'active',
    ];

    protected static function booted(): void
    {
        $flush = static function (Tenant $tenant): void {
            $locator = app()->bound(CachedTenantLocator::class) ? app(CachedTenantLocator::class) : null;

            $locator?->forget($tenant->toRecord());

            foreach (['slug', 'public_id'] as $column) {
                $original = $tenant->getOriginal($column);

                if (is_string($original) && $original !== '') {
                    $locator?->forgetIdentifier($original);
                }
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }

    public function getTable(): string
    {
        $configured = config('tenant.table', 'tenants');

        return is_string($configured) && $configured !== '' ? $configured : 'tenants';
    }

    public function getConnectionName(): ?string
    {
        $configured = config('tenant.connection');

        return is_string($configured) && $configured !== '' ? $configured : parent::getConnectionName();
    }

    public static function publicIdPrefix(): string
    {
        return 'ten';
    }

    public static function resourceType(): string
    {
        return 'tenant';
    }

    public function tenantId(): TenantId
    {
        return TenantId::fromString((string) $this->getKey());
    }

    public function status(): TenantStatus
    {
        return TenantStatus::fromStored($this->getAttribute('status'));
    }

    public function isActive(): bool
    {
        return $this->status()->isActive();
    }

    public function toRecord(): TenantRecord
    {
        $slug = $this->getAttribute('slug');
        $name = $this->getAttribute('name');

        return new TenantRecord(
            id: $this->tenantId(),
            status: $this->status(),
            publicId: $this->publicIdOrNull(),
            slug: is_string($slug) ? $slug : null,
            name: is_string($name) ? $name : null,
        );
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        /** @var class-string<TenantMembership> $model */
        $model = config('tenant.memberships.model', TenantMembership::class);

        return $this->hasMany($model, 'tenant_id', $this->getKeyName());
    }

    private function publicIdOrNull(): ?PublicId
    {
        $value = $this->getAttribute($this->publicIdColumn());

        return is_string($value) ? PublicId::tryFromString($value) : null;
    }
}
