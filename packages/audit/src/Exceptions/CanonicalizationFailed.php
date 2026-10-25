<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Exceptions;

use RuntimeException;

final class CanonicalizationFailed extends RuntimeException implements AuditException
{
    public static function because(string $reason): self
    {
        return new self('Audit record cannot be canonicalised: '.$reason);
    }
}
