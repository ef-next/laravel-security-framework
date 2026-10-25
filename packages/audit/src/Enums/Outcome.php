<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Enums;

use Efnext\Security\Audit\Exceptions\InvalidAuditEntry;

enum Outcome: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Failed = 'failed';

    public static function normalize(self|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom(strtolower(trim($value)))
            ?? throw InvalidAuditEntry::because(sprintf('unknown outcome "%s" (allowed, denied, failed).', $value));
    }
}
