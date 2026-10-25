<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Context;

use DateTimeImmutable;
use Efnext\Security\Addressing\Identifiers\PublicId;
use Efnext\Security\Tenant\Resolution\Source;

/**
 * The tenant an operation runs for, plus where that decision came from.
 */
final readonly class TenantContext
{
    public function __construct(
        public TenantId $tenantId,
        public Source $source,
        public ?PublicId $publicId = null,
        public ?DateTimeImmutable $resolvedAt = null,
    ) {}

    public static function for(TenantId|string $tenantId, Source $source = Source::Manual, ?PublicId $publicId = null): self
    {
        return new self(
            tenantId: $tenantId instanceof TenantId ? $tenantId : TenantId::fromString($tenantId),
            source: $source,
            publicId: $publicId,
            resolvedAt: new DateTimeImmutable,
        );
    }

    public function is(TenantId|string $tenantId): bool
    {
        $other = $tenantId instanceof TenantId ? $tenantId : TenantId::tryFromString($tenantId);

        return $this->tenantId->equals($other);
    }

    /**
     * @return array{tenant_id: string, source: string, public_id: string|null, resolved_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId->toString(),
            'source' => $this->source->value,
            'public_id' => $this->publicId?->toString(),
            'resolved_at' => $this->resolvedAt?->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
