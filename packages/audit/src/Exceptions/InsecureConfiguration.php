<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Exceptions;

use RuntimeException;

/**
 * Thrown at boot in production when the configuration silently removes the
 * framework's guarantees (no signing key, SQLite, append-only off, no readable
 * anchor store). Fixing the configuration is the only way past it; set
 * AUDIT_FAIL_FAST=false for a deliberate, temporary exception.
 */
final class InsecureConfiguration extends RuntimeException implements AuditException
{
    /**
     * @param  list<string>  $problems
     */
    public static function because(array $problems): self
    {
        return new self(
            "Refusing to boot the audit log in production with an insecure configuration:\n - ".implode("\n - ", $problems)
            ."\nRun `php artisan security:doctor` for details. Set AUDIT_FAIL_FAST=false only as a deliberate, temporary exception."
        );
    }
}
