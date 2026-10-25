<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Contracts;

use Efnext\Security\Audit\Enums\SignatureStatus;
use Efnext\Security\Audit\Records\Signature;

/**
 * Signs event hashes with a key that lives outside the database.
 */
interface Signer
{
    public function isConfigured(): bool;

    /** Null when signing is not configured. */
    public function sign(string $eventHash): ?Signature;

    public function verify(string $eventHash, ?string $keyId, ?string $signature): SignatureStatus;
}
