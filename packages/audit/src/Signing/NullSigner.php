<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Signing;

use Efnext\Security\Audit\Contracts\Signer;
use Efnext\Security\Audit\Enums\SignatureStatus;
use Efnext\Security\Audit\Records\Signature;

/**
 * Used when no signing keys are configured. Chains are still hashed, but a
 * database-level attacker could rewrite them consistently. Configure keys.
 */
final class NullSigner implements Signer
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function sign(string $eventHash): ?Signature
    {
        return null;
    }

    public function verify(string $eventHash, ?string $keyId, ?string $signature): SignatureStatus
    {
        return SignatureStatus::NotConfigured;
    }
}
