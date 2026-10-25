<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Contracts;

use Efnext\Security\Addressing\Identifiers\PublicId;

interface PublicIdGenerator
{
    /**
     * Generate a random public id. Does not check the database.
     */
    public function generate(string $prefix, ?int $entropyBytes = null): PublicId;

    /**
     * Generate a public id that does not exist in the given table yet.
     *
     * This is check-then-insert and therefore only a convenience: the table
     * MUST carry a UNIQUE index on the column; the index is the real guarantee.
     */
    public function generateUnique(
        string $prefix,
        string $table,
        string $column,
        ?string $connection = null,
        ?int $entropyBytes = null,
        ?int $maxAttempts = null,
    ): PublicId;
}
