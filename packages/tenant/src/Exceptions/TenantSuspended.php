<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Exceptions;

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Locating\TenantStatus;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The tenant exists but is not active (HTTP 403).
 */
final class TenantSuspended extends AccessDeniedHttpException implements TenantException
{
    public static function because(TenantId $tenantId, TenantStatus $status): self
    {
        return new self(sprintf('Tenant is %s.', $status->value));
    }
}
