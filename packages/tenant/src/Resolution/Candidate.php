<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Resolution;

/**
 * A raw tenant identifier found in a request, before it is looked up.
 */
final readonly class Candidate
{
    public function __construct(
        public string $identifier,
        public Source $source,
        public string $detail = '',
    ) {}
}
