<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Exceptions;

use InvalidArgumentException;

final class InvalidTenantId extends InvalidArgumentException implements TenantException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'Invalid tenant id "%s": expected 1-191 printable characters matching %s.',
            mb_strlen($value) > 64 ? mb_substr($value, 0, 61).'...' : $value,
            '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D'
        ));
    }
}
