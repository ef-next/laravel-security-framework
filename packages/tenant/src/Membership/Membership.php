<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Membership;

use DateTimeImmutable;
use DateTimeInterface;
use Efnext\Security\Tenant\Context\TenantId;

final readonly class Membership
{
    public function __construct(
        public string $actorId,
        public TenantId $tenantId,
        public ?string $role,
        public MembershipStatus $status,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function isExpired(?DateTimeInterface $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable;

        return $this->expiresAt <= $now;
    }

    public function grantsAccess(?DateTimeInterface $now = null): bool
    {
        return $this->status === MembershipStatus::Active && ! $this->isExpired($now);
    }

    public function denialReason(?DateTimeInterface $now = null): ?DenialReason
    {
        if ($this->status !== MembershipStatus::Active) {
            return DenialReason::MembershipInactive;
        }

        if ($this->isExpired($now)) {
            return DenialReason::MembershipExpired;
        }

        return null;
    }
}
