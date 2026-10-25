<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Membership;

use DateTimeInterface;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Eloquent\Tenant;
use Efnext\Security\Tenant\Eloquent\TenantMembership;
use Efnext\Security\Tenant\Events\MembershipChanged;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

/**
 * Membership API for user (actor) models.
 *
 * Reads answer "may this user act for tenant X?"; writes fire MembershipChanged
 * so the audit package can record them.
 *
 * @mixin Model
 */
trait HasTenantMemberships
{
    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(self::tenantMembershipModel(), 'user_id', $this->getKeyName());
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        /** @var class-string<Tenant> $tenantModel */
        $tenantModel = config('tenant.model', Tenant::class);
        $table = (new (self::tenantMembershipModel()))->getTable();

        return $this->belongsToMany($tenantModel, $table, 'user_id', 'tenant_id')
            ->withPivot(['role', 'status', 'expires_at']);
    }

    /**
     * Tenants this user may currently act for (active, not expired).
     *
     * @return list<TenantId>
     */
    public function activeTenantIds(): array
    {
        if ($this->getKey() === null) {
            return [];
        }

        /** @var Collection<int, TenantMembership> $memberships */
        $memberships = $this->tenantMemberships()->get();

        $ids = [];

        foreach ($memberships as $membership) {
            $value = $membership->toMembership();

            if ($value->grantsAccess()) {
                $ids[] = $value->tenantId;
            }
        }

        return $ids;
    }

    public function tenantMembership(TenantId|string $tenantId): ?Membership
    {
        if ($this->getKey() === null) {
            return null;
        }

        $id = self::normalizeTenantId($tenantId);

        /** @var TenantMembership|null $row */
        $row = $this->tenantMemberships()->where('tenant_id', $id->toString())->first();

        return $row?->toMembership();
    }

    public function isMemberOf(TenantId|string $tenantId): bool
    {
        return $this->tenantMembership($tenantId)?->grantsAccess() ?? false;
    }

    /**
     * @param  string|list<string>  $roles
     */
    public function hasTenantRole(TenantId|string $tenantId, string|array $roles): bool
    {
        $membership = $this->tenantMembership($tenantId);

        if ($membership === null || ! $membership->grantsAccess()) {
            return false;
        }

        /** @var Roles $vocabulary */
        $vocabulary = app(Roles::class);

        return $vocabulary->satisfies($membership->role, is_string($roles) ? Roles::parse($roles) : $roles);
    }

    public function grantTenantMembership(TenantId|string $tenantId, ?string $role = null, ?DateTimeInterface $expiresAt = null): Membership
    {
        $this->assertPersisted();

        $id = self::normalizeTenantId($tenantId);
        /** @var Roles $vocabulary */
        $vocabulary = app(Roles::class);
        $role = Roles::normalize($role) ?? (string) config('tenant.memberships.default_role', 'member');

        if (! $vocabulary->isKnown($role)) {
            throw new InvalidArgumentException(sprintf('Unknown tenant role "%s". Configured roles: %s.', $role, implode(', ', $vocabulary->all())));
        }

        /** @var TenantMembership $membership */
        $membership = $this->tenantMemberships()->firstOrNew(['tenant_id' => $id->toString()]);
        $previousRole = $membership->exists ? Roles::normalize(self::stringOrNull($membership->getAttribute('role'))) : null;
        $wasNew = ! $membership->exists;

        $membership->forceFill([
            'role' => $role,
            'status' => MembershipStatus::Active->value,
            'expires_at' => $expiresAt,
        ])->save();

        event(new MembershipChanged(
            change: $wasNew ? MembershipChanged::GRANTED : MembershipChanged::ROLE_CHANGED,
            tenantId: $id,
            actorId: (string) $this->getKey(),
            role: $role,
            previousRole: $previousRole,
            status: MembershipStatus::Active->value,
        ));

        return $membership->toMembership();
    }

    public function changeTenantMembershipStatus(TenantId|string $tenantId, MembershipStatus $status): void
    {
        $this->assertPersisted();

        $id = self::normalizeTenantId($tenantId);

        /** @var TenantMembership|null $membership */
        $membership = $this->tenantMemberships()->where('tenant_id', $id->toString())->first();

        if ($membership === null) {
            throw new LogicException(sprintf('No membership for tenant "%s" to change.', $id->toString()));
        }

        $membership->forceFill(['status' => $status->value])->save();

        event(new MembershipChanged(
            change: MembershipChanged::STATUS_CHANGED,
            tenantId: $id,
            actorId: (string) $this->getKey(),
            role: Roles::normalize(self::stringOrNull($membership->getAttribute('role'))),
            status: $status->value,
        ));
    }

    public function revokeTenantMembership(TenantId|string $tenantId): void
    {
        if ($this->getKey() === null) {
            return;
        }

        $id = self::normalizeTenantId($tenantId);

        /** @var TenantMembership|null $membership */
        $membership = $this->tenantMemberships()->where('tenant_id', $id->toString())->first();

        if ($membership === null) {
            return;
        }

        $role = Roles::normalize(self::stringOrNull($membership->getAttribute('role')));
        $membership->delete();

        event(new MembershipChanged(
            change: MembershipChanged::REVOKED,
            tenantId: $id,
            actorId: (string) $this->getKey(),
            previousRole: $role,
        ));
    }

    /**
     * @return class-string<TenantMembership>
     */
    private static function tenantMembershipModel(): string
    {
        /** @var class-string<TenantMembership> $model */
        $model = config('tenant.memberships.model', TenantMembership::class);

        return $model;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function normalizeTenantId(TenantId|string $tenantId): TenantId
    {
        return $tenantId instanceof TenantId ? $tenantId : TenantId::fromString($tenantId);
    }

    private function assertPersisted(): void
    {
        if ($this->getKey() === null) {
            throw new LogicException('Tenant memberships require a persisted user model.');
        }
    }
}
