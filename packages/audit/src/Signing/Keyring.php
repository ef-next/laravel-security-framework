<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Signing;

use Efnext\Security\Audit\Exceptions\InvalidSigningKey;

/**
 * Named HMAC keys. Parsed from "k1:base64:<key>,k2:base64:<key>".
 */
final class Keyring
{
    public const int MINIMUM_KEY_BYTES = 32;

    /** @var array<string, string> key id => raw key bytes */
    private array $keys = [];

    /**
     * @param  array<string, string>  $keys  key id => raw key bytes
     */
    public function __construct(array $keys, private readonly ?string $activeKeyId)
    {
        foreach ($keys as $id => $key) {
            $id = (string) $id;

            if (preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $id) !== 1) {
                throw InvalidSigningKey::because(sprintf('key id "%s" must match /^[A-Za-z0-9_.-]{1,32}$/.', $id));
            }

            if (strlen($key) < self::MINIMUM_KEY_BYTES) {
                throw InvalidSigningKey::because(sprintf('key "%s" is shorter than %d bytes.', $id, self::MINIMUM_KEY_BYTES));
            }

            $this->keys[$id] = $key;
        }

        if ($activeKeyId !== null && ! isset($this->keys[$activeKeyId])) {
            throw InvalidSigningKey::because(sprintf('active key "%s" is not in the keyring.', $activeKeyId));
        }

        if ($activeKeyId === null && $this->keys !== []) {
            throw InvalidSigningKey::because('keys are configured but no active key is named.');
        }
    }

    public static function fromString(string $keys, string $activeKeyId): self
    {
        $parsed = [];

        foreach (array_filter(array_map('trim', explode(',', $keys))) as $entry) {
            $parts = explode(':', $entry, 3);

            if (count($parts) !== 3 || $parts[1] !== 'base64') {
                throw InvalidSigningKey::because(sprintf('entry "%s" is not in the form <id>:base64:<key>.', $entry));
            }

            $raw = base64_decode($parts[2], true);

            if ($raw === false) {
                throw InvalidSigningKey::because(sprintf('key "%s" is not valid base64.', $parts[0]));
            }

            $parsed[$parts[0]] = $raw;
        }

        return new self($parsed, trim($activeKeyId) !== '' ? trim($activeKeyId) : null);
    }

    public static function empty(): self
    {
        return new self([], null);
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    public function activeKeyId(): ?string
    {
        return $this->activeKeyId;
    }

    public function has(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    public function key(string $keyId): string
    {
        return $this->keys[$keyId] ?? throw InvalidSigningKey::because(sprintf('unknown key "%s".', $keyId));
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->keys);
    }
}
