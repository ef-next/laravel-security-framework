<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Exceptions;

final class InvalidPublicId extends AddressingException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'Invalid public id "%s": expected "<prefix>_<body>" with a lowercase prefix (2-12 chars) and a lowercase alphanumeric body (8-64 chars).',
            self::truncate($value)
        ));
    }

    public static function invalidPrefix(string $prefix): self
    {
        return new self(sprintf(
            'Invalid public id prefix "%s": must match /^[a-z][a-z0-9]{1,11}$/.',
            self::truncate($prefix)
        ));
    }

    public static function insufficientEntropy(int $bytes, int $minimum): self
    {
        return new self(sprintf('Public ids require at least %d bytes of entropy, %d given.', $minimum, $bytes));
    }

    private static function truncate(string $value): string
    {
        return mb_strlen($value) > 64 ? mb_substr($value, 0, 61).'...' : $value;
    }
}
