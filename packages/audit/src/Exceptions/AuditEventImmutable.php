<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Exceptions;

use LogicException;

final class AuditEventImmutable extends LogicException implements AuditException
{
    public static function create(): self
    {
        return new self('Audit records are append-only; they can neither be updated nor deleted.');
    }
}
