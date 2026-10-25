<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Membership;

use DateTimeImmutable;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Contracts\MembershipChecker;
use Illuminate\Database\ConnectionResolverInterface;

final class DatabaseMembershipChecker implements MembershipChecker
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly string $table = 'tenant_memberships',
        private readonly ?string $connection = null,
        private readonly string $userColumn = 'user_id',
        private readonly string $tenantColumn = 'tenant_id',
    ) {}

    public function membershipFor(string $actorId, TenantId $tenantId): ?Membership
    {
        $actorId = trim($actorId);

        if ($actorId === '') {
            return null;
        }

        $row = $this->connections->connection($this->connection)
            ->table($this->table)
            ->where($this->userColumn, $actorId)
            ->where($this->tenantColumn, $tenantId->toString())
            ->first(['role', 'status', 'expires_at']);

        if ($row === null) {
            return null;
        }

        $data = (array) $row;
        $expiresAt = isset($data['expires_at']) && is_string($data['expires_at']) && $data['expires_at'] !== ''
            ? new DateTimeImmutable($data['expires_at'])
            : null;

        return new Membership(
            actorId: $actorId,
            tenantId: $tenantId,
            role: Roles::normalize(isset($data['role']) ? (string) $data['role'] : null),
            status: MembershipStatus::fromStored($data['status'] ?? null),
            expiresAt: $expiresAt,
        );
    }
}
