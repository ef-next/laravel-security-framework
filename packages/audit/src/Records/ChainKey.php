<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Records;

use Efnext\Security\Tenant\Context\TenantId;
use JsonSerializable;
use Stringable;

/**
 * Identifies one hash chain: a tenant's chain or the global (tenant-less) chain.
 */
final readonly class ChainKey implements JsonSerializable, Stringable
{
    public const string GLOBAL = 'global';

    private function __construct(public string $value) {}

    public static function forTenant(TenantId $tenantId): self
    {
        return new self('tenant:'.$tenantId->toString());
    }

    public static function global(): self
    {
        return new self(self::GLOBAL);
    }

    public static function for(?TenantId $tenantId): self
    {
        return $tenantId === null ? self::global() : self::forTenant($tenantId);
    }

    public static function fromString(string $value): self
    {
        if ($value === self::GLOBAL) {
            return self::global();
        }

        if (str_starts_with($value, 'tenant:')) {
            return self::forTenant(TenantId::fromString(substr($value, 7)));
        }

        // Accept a bare tenant id for convenience (console arguments).
        return self::forTenant(TenantId::fromString($value));
    }

    public function isGlobal(): bool
    {
        return $this->value === self::GLOBAL;
    }

    public function tenantId(): ?TenantId
    {
        return $this->isGlobal() ? null : TenantId::fromString(substr($this->value, 7));
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $other->value === $this->value;
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
