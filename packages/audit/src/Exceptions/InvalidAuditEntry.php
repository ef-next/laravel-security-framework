<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Exceptions;

use InvalidArgumentException;

final class InvalidAuditEntry extends InvalidArgumentException implements AuditException
{
    public static function because(string $reason): self
    {
        return new self('Invalid audit entry: '.$reason);
    }

    public static function tenantRequired(): self
    {
        return new self('Invalid audit entry: a tenant is required (tenant policy "required") and none was given or active. Pass tenantId explicitly or run inside a tenant context.');
    }
}
