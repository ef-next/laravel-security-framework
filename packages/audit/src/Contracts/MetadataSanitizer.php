<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Contracts;

interface MetadataSanitizer
{
    /**
     * Redact secrets, pseudonymise identifiers and bound the size of metadata
     * before it is hashed and stored.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitize(array $metadata): array;
}
