<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The tenant identifier did not match an existing tenant (HTTP 404).
 *
 * The response body stays generic; the identifier is available to loggers via identifier().
 */
final class TenantNotFound extends NotFoundHttpException implements TenantException
{
    private string $identifier = '';

    public static function forIdentifier(string $identifier): self
    {
        $exception = new self('Tenant not found.');
        $exception->identifier = $identifier;

        return $exception;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return ['tenant_identifier' => $this->identifier];
    }
}
