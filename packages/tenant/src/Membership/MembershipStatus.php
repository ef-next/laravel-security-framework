<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Membership;

enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';

    public static function fromStored(mixed $value): self
    {
        // Unknown or missing status never grants access.
        return is_string($value) ? (self::tryFrom(strtolower(trim($value))) ?? self::Suspended) : self::Suspended;
    }
}
