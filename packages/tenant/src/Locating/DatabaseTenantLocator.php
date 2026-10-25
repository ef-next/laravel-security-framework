<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Locating;

use Closure;
use Efnext\Security\Addressing\Identifiers\GlobalObjectId;
use Efnext\Security\Addressing\Identifiers\PublicId;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Contracts\TenantLocator;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Looks a tenant up by internal id, public id, global object id or slug,
 * in the configured order. Every lookup is an exact, parameterised match.
 */
final class DatabaseTenantLocator implements TenantLocator
{
    /**
     * @param  list<string>  $lookupBy  subset of id, public_id, global_object_id, slug
     * @param  string|Closure(): string  $serviceName  this service's name for global object ids (resolved lazily)
     */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly string $table = 'tenants',
        private readonly ?string $connection = null,
        private readonly array $lookupBy = ['id', 'public_id', 'global_object_id', 'slug'],
        private readonly string $idColumn = 'id',
        private readonly string $publicIdColumn = 'public_id',
        private readonly string $slugColumn = 'slug',
        private readonly string $statusColumn = 'status',
        private readonly string|Closure $serviceName = 'app',
        private readonly string $resourceType = 'tenant',
    ) {}

    public function locate(string $identifier): ?TenantRecord
    {
        $identifier = trim($identifier);

        if ($identifier === '' || strlen($identifier) > 255) {
            return null;
        }

        foreach ($this->lookupBy as $strategy) {
            $row = match ($strategy) {
                'id' => $this->byId($identifier),
                'public_id' => $this->byPublicId(PublicId::tryFromString($identifier)),
                'global_object_id' => $this->byGlobalObjectId(GlobalObjectId::tryFromString($identifier)),
                'slug' => $this->bySlug($identifier),
                default => null,
            };

            if ($row !== null) {
                return $this->hydrate($row);
            }
        }

        return null;
    }

    private function byId(string $identifier): ?object
    {
        // Internal ids are UUIDs/ULIDs in the shipped schema; anything else is not an id.
        if (! Str::isUuid($identifier) && ! Str::isUlid($identifier)) {
            return null;
        }

        return $this->query()->where($this->idColumn, $identifier)->first();
    }

    private function byPublicId(?PublicId $publicId): ?object
    {
        if ($publicId === null) {
            return null;
        }

        return $this->query()->where($this->publicIdColumn, $publicId->toString())->first();
    }

    private function byGlobalObjectId(?GlobalObjectId $id): ?object
    {
        if ($id === null || ! $id->is($this->serviceName(), $this->resourceType)) {
            return null;
        }

        return $this->byPublicId($id->publicId);
    }

    private function bySlug(string $identifier): ?object
    {
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/D', $identifier) !== 1) {
            return null;
        }

        return $this->query()->where($this->slugColumn, $identifier)->first();
    }

    private function serviceName(): string
    {
        return $this->serviceName instanceof Closure ? ($this->serviceName)() : $this->serviceName;
    }

    private function query(): Builder
    {
        return $this->connections->connection($this->connection)->table($this->table);
    }

    private function hydrate(object $row): TenantRecord
    {
        $data = (array) $row;

        return new TenantRecord(
            id: TenantId::fromString((string) $data[$this->idColumn]),
            status: TenantStatus::fromStored($data[$this->statusColumn] ?? null),
            publicId: PublicId::tryFromString(isset($data[$this->publicIdColumn]) ? (string) $data[$this->publicIdColumn] : null),
            slug: isset($data[$this->slugColumn]) ? (string) $data[$this->slugColumn] : null,
            name: isset($data['name']) ? (string) $data['name'] : null,
        );
    }
}
