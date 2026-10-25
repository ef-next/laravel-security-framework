<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Exceptions;

use InvalidArgumentException;

final class InvalidSigningKey extends InvalidArgumentException implements AuditException
{
    public static function because(string $reason): self
    {
        return new self('Invalid audit signing key configuration: '.$reason.' Expected AUDIT_SIGNING_KEYS="k1:base64:<key>,k2:base64:<key>" with at least 32 bytes per key and AUDIT_SIGNING_ACTIVE_KEY naming one of them.');
    }
}
