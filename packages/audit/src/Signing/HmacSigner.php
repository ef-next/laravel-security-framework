<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Signing;

use Efnext\Security\Audit\Contracts\Signer;
use Efnext\Security\Audit\Enums\SignatureStatus;
use Efnext\Security\Audit\Records\Signature;

/**
 * HMAC-SHA256 over the event hash with a key from the keyring.
 *
 * A database-only attacker can recompute hashes but not signatures; a
 * verifier holding the key detects the forgery. Rotate by adding a new key,
 * making it active and keeping the old one for verification.
 */
final class HmacSigner implements Signer
{
    public function __construct(
        private readonly Keyring $keyring,
    ) {}

    public function isConfigured(): bool
    {
        return ! $this->keyring->isEmpty() && $this->keyring->activeKeyId() !== null;
    }

    public function sign(string $eventHash): ?Signature
    {
        $keyId = $this->keyring->activeKeyId();

        if ($keyId === null) {
            return null;
        }

        return new Signature($keyId, hash_hmac('sha256', $eventHash, $this->keyring->key($keyId)));
    }

    public function verify(string $eventHash, ?string $keyId, ?string $signature): SignatureStatus
    {
        if (! $this->isConfigured()) {
            return SignatureStatus::NotConfigured;
        }

        if ($keyId === null || $signature === null) {
            return SignatureStatus::Invalid;
        }

        if (! $this->keyring->has($keyId)) {
            return SignatureStatus::UnknownKey;
        }

        $expected = hash_hmac('sha256', $eventHash, $this->keyring->key($keyId));

        return hash_equals($expected, $signature) ? SignatureStatus::Valid : SignatureStatus::Invalid;
    }
}
