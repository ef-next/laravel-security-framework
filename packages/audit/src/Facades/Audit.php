<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Facades;

use Efnext\Security\Audit\AuditManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Efnext\Security\Audit\AuditBuilder action(string $action)
 * @method static \Efnext\Security\Audit\Eloquent\AuditEvent record(\Efnext\Security\Audit\Records\AuditEntry $entry)
 * @method static void prepareChain(\Efnext\Security\Tenant\Context\TenantId|string|null $tenantId)
 * @method static mixed transaction(\Closure $callback, int $attempts = 10)
 * @method static \Efnext\Security\Audit\Records\VerificationReport verifyTenant(\Efnext\Security\Tenant\Context\TenantId|string $tenantId, int $fromSequence = 1, ?int $toSequence = null)
 * @method static \Efnext\Security\Audit\Records\VerificationReport verifyGlobal(int $fromSequence = 1, ?int $toSequence = null)
 * @method static \Efnext\Security\Audit\Records\VerificationReport verify(\Efnext\Security\Audit\Records\ChainKey $chainKey, int $fromSequence = 1, ?int $toSequence = null)
 *
 * @see AuditManager
 */
final class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditManager::class;
    }
}
