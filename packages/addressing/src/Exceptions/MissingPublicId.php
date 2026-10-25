<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Exceptions;

final class MissingPublicId extends AddressingException
{
    public static function forModel(string $model, string $column): self
    {
        return new self(sprintf('%s has no %s yet. Public ids are assigned when the model is created.', $model, $column));
    }
}
