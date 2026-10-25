<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Exceptions;

use Efnext\Security\Tenant\Membership\DenialReason;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The authenticated actor may not act for the active tenant (HTTP 403).
 */
final class TenantAccessDenied extends AccessDeniedHttpException implements TenantException
{
    private DenialReason $reason = DenialReason::NoMembership;

    public static function because(DenialReason $reason): self
    {
        $exception = new self($reason->message());
        $exception->reason = $reason;

        return $exception;
    }

    public function reason(): DenialReason
    {
        return $this->reason;
    }
}
