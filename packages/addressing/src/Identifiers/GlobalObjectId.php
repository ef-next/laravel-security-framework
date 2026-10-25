<?php

declare(strict_types=1);

namespace Efnext\Security\Addressing\Identifiers;

use Efnext\Security\Addressing\Exceptions\InvalidGlobalObjectId;
use JsonSerializable;
use Stringable;

/**
 * A globally unique address for a resource across services:
 * "<service>:<resource-type>:<public-id>", e.g. "example-service:domain:dom_7f3k9zq2m4x8c1bt".
 *
 * All segments are lowercase. The value is canonical or invalid.
 */
final readonly class GlobalObjectId implements JsonSerializable, Stringable
{
    public const string SEGMENT_PATTERN = '/^[a-z][a-z0-9-]{0,62}$/D';

    private function __construct(
        public string $service,
        public string $resourceType,
        public PublicId $publicId,
    ) {}

    public static function of(string $service, string $resourceType, PublicId $publicId): self
    {
        if (preg_match(self::SEGMENT_PATTERN, $service) !== 1) {
            throw InvalidGlobalObjectId::invalidSegment('service', $service);
        }

        if (preg_match(self::SEGMENT_PATTERN, $resourceType) !== 1) {
            throw InvalidGlobalObjectId::invalidSegment('resource type', $resourceType);
        }

        return new self($service, $resourceType, $publicId);
    }

    public static function fromString(string $value): self
    {
        $parsed = self::tryFromString($value);

        if ($parsed === null) {
            throw InvalidGlobalObjectId::malformed($value);
        }

        return $parsed;
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $parts = explode(':', $value);

        if (count($parts) !== 3) {
            return null;
        }

        [$service, $resourceType, $publicId] = $parts;

        if (preg_match(self::SEGMENT_PATTERN, $service) !== 1 || preg_match(self::SEGMENT_PATTERN, $resourceType) !== 1) {
            return null;
        }

        $parsedPublicId = PublicId::tryFromString($publicId);

        if ($parsedPublicId === null) {
            return null;
        }

        return new self($service, $resourceType, $parsedPublicId);
    }

    public static function isValid(?string $value): bool
    {
        return self::tryFromString($value) !== null;
    }

    public function is(string $service, ?string $resourceType = null): bool
    {
        return $this->service === $service && ($resourceType === null || $this->resourceType === $resourceType);
    }

    public function equals(?self $other): bool
    {
        return $other !== null
            && $other->service === $this->service
            && $other->resourceType === $this->resourceType
            && $other->publicId->equals($this->publicId);
    }

    public function toString(): string
    {
        return $this->service.':'.$this->resourceType.':'.$this->publicId->toString();
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
