<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Exceptions;

final class InvalidGlobalObjectId extends AddressingException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'Invalid global object id "%s": expected "<service>:<resource-type>:<public-id>".',
            mb_strlen($value) > 96 ? mb_substr($value, 0, 93).'...' : $value
        ));
    }

    public static function invalidSegment(string $name, string $value): self
    {
        return new self(sprintf(
            'Invalid global object id %s "%s": must match /^[a-z][a-z0-9-]{0,62}$/.',
            $name,
            mb_strlen($value) > 64 ? mb_substr($value, 0, 61).'...' : $value
        ));
    }
}
