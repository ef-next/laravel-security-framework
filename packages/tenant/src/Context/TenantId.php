<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Context;

use Efnext\Security\Tenant\Exceptions\InvalidTenantId;
use JsonSerializable;
use Stringable;

/**
 * The internal identifier of a tenant (the value stored in tenant_id columns).
 *
 * Deliberately opaque: UUIDs, ULIDs and legacy keys are all accepted as long as
 * they are printable, have no surrounding whitespace and fit the column.
 */
final readonly class TenantId implements JsonSerializable, Stringable
{
    public const string PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidTenantId::malformed($value);
        }

        return new self($value);
    }

    public static function tryFromString(mixed $value): ?self
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        return new self($value);
    }

    public function equals(?self $other): bool
    {
        return $other !== null && hash_equals($this->value, $other->value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
