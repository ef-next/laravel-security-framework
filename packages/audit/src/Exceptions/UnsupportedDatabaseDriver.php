<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Exceptions;

use RuntimeException;

final class UnsupportedDatabaseDriver extends RuntimeException implements AuditException
{
    public static function forAppendOnly(string $driver): self
    {
        return new self(sprintf(
            'Append-only triggers are not implemented for the "%s" driver (supported: pgsql, mysql, mariadb, sqlite). Refusing to migrate without protection; set audit.enforce_append_only=false only if you enforce immutability elsewhere.',
            $driver
        ));
    }
}
