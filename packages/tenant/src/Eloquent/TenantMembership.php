<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Membership\Membership;
use Efnext\Security\Tenant\Membership\MembershipStatus;
use Efnext\Security\Tenant\Membership\Roles;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links an actor (user) to a tenant with a role and a status.
 *
 * Not tenant-scoped on purpose: a user's memberships span tenants and are
 * read before any tenant context exists.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string|null $role
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class TenantMembership extends Model
{
    use HasUlids;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'status', 'expires_at'];

    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function getTable(): string
    {
        $configured = config('tenant.memberships.table', 'tenant_memberships');

        return is_string($configured) && $configured !== '' ? $configured : 'tenant_memberships';
    }

    public function getConnectionName(): ?string
    {
        $configured = config('tenant.connection');

        return is_string($configured) && $configured !== '' ? $configured : parent::getConnectionName();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        /** @var class-string<Tenant> $model */
        $model = config('tenant.model', Tenant::class);

        return $this->belongsTo($model, 'tenant_id');
    }

    public function toMembership(): Membership
    {
        $expiresAt = $this->getAttribute('expires_at');

        return new Membership(
            actorId: (string) $this->getAttribute('user_id'),
            tenantId: TenantId::fromString((string) $this->getAttribute('tenant_id')),
            role: Roles::normalize(is_string($this->getAttribute('role')) ? $this->getAttribute('role') : null),
            status: MembershipStatus::fromStored($this->getAttribute('status')),
            expiresAt: $expiresAt instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($expiresAt) : null,
        );
    }
}
