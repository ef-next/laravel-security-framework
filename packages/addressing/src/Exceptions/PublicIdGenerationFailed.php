<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Exceptions;

final class PublicIdGenerationFailed extends AddressingException
{
    public static function afterAttempts(string $table, string $column, int $attempts): self
    {
        return new self(sprintf(
            'Could not generate a unique public id for %s.%s after %d attempts. Check the entropy setting and the system random source.',
            $table,
            $column,
            $attempts
        ));
    }
}
