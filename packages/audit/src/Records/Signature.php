<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Records;

final readonly class Signature
{
    public function __construct(
        public string $keyId,
        public string $value,
    ) {}
}
