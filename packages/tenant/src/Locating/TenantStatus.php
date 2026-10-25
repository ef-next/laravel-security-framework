<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Locating;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public static function fromStored(mixed $value): self
    {
        // Unknown or missing status never means "active": fail closed.
        return is_string($value) ? (self::tryFrom(strtolower(trim($value))) ?? self::Suspended) : self::Suspended;
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
