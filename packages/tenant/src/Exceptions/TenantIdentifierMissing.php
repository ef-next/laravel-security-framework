<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Exceptions;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The request carried no tenant identifier in any configured location (HTTP 400).
 */
final class TenantIdentifierMissing extends BadRequestHttpException implements TenantException
{
    /**
     * @param  list<string>  $sources
     */
    public static function inRequest(array $sources): self
    {
        return new self('No tenant identifier found in the request (checked: '.implode(', ', $sources).').');
    }
}
