<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Identifiers;

use Efnext\Security\Addressing\Exceptions\InvalidPublicId;
use JsonSerializable;
use Stringable;

/**
 * A public identifier: "<prefix>_<body>", e.g. "dom_7f3k9zq2m4x8c1bt".
 *
 * Public ids are unguessable and stable, which makes them safe to expose in
 * URLs, logs and support conversations. They are NOT an authorization
 * mechanism: knowing an id never grants access.
 *
 * The value is canonical or invalid. No trimming, no case folding.
 */
final readonly class PublicId implements JsonSerializable, Stringable
{
    public const string PREFIX_PATTERN = '/^[a-z][a-z0-9]{1,11}$/D';

    public const string BODY_PATTERN = '/^[a-z0-9]{8,64}$/D';

    private function __construct(
        public string $prefix,
        public string $body,
    ) {}

    public static function of(string $prefix, string $body): self
    {
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw InvalidPublicId::invalidPrefix($prefix);
        }

        if (preg_match(self::BODY_PATTERN, $body) !== 1) {
            throw InvalidPublicId::malformed($prefix.'_'.$body);
        }

        return new self($prefix, $body);
    }

    public static function fromString(string $value): self
    {
        $parsed = self::tryFromString($value);

        if ($parsed === null) {
            throw InvalidPublicId::malformed($value);
        }

        return $parsed;
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $separator = strpos($value, '_');

        if ($separator === false) {
            return null;
        }

        $prefix = substr($value, 0, $separator);
        $body = substr($value, $separator + 1);

        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1 || preg_match(self::BODY_PATTERN, $body) !== 1) {
            return null;
        }

        return new self($prefix, $body);
    }

    public static function isValid(?string $value): bool
    {
        return self::tryFromString($value) !== null;
    }

    public static function isValidPrefix(string $prefix): bool
    {
        return preg_match(self::PREFIX_PATTERN, $prefix) === 1;
    }

    public function hasPrefix(string $prefix): bool
    {
        return $this->prefix === $prefix;
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->prefix === $this->prefix && $other->body === $this->body;
    }

    public function toString(): string
    {
        return $this->prefix.'_'.$this->body;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
