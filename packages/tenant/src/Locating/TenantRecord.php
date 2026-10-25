<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Locating;

use Efnext\Security\Addressing\Identifiers\PublicId;
use Efnext\Security\Tenant\Context\TenantId;

/**
 * What the locator knows about a tenant. Cacheable and serializable.
 */
final readonly class TenantRecord
{
    public function __construct(
        public TenantId $id,
        public TenantStatus $status,
        public ?PublicId $publicId = null,
        public ?string $slug = null,
        public ?string $name = null,
    ) {}

    /**
     * @return array{id: string, status: string, public_id: ?string, slug: ?string, name: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'status' => $this->status->value,
            'public_id' => $this->publicId?->toString(),
            'slug' => $this->slug,
            'name' => $this->name,
        ];
    }

    /**
     * @param  array{id: string, status: string, public_id?: ?string, slug?: ?string, name?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: TenantId::fromString($data['id']),
            status: TenantStatus::fromStored($data['status']),
            publicId: PublicId::tryFromString($data['public_id'] ?? null),
            slug: $data['slug'] ?? null,
            name: $data['name'] ?? null,
        );
    }
}
