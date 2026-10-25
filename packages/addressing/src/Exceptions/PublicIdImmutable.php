<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Exceptions;

final class PublicIdImmutable extends AddressingException
{
    public static function forModel(string $model, string $column): self
    {
        return new self(sprintf('%s: the %s of a persisted model must not change.', $model, $column));
    }
}
