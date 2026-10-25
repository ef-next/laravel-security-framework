<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Records;

use DateTimeImmutable;
use DateTimeInterface;
use Efnext\Security\Audit\Enums\Outcome;
use Efnext\Security\Audit\Exceptions\InvalidAuditEntry;
use Efnext\Security\Tenant\Context\TenantId;

/**
 * Input for one audit record. Validated on construction; the writer adds
 * sequence, hashes, actor and request context.
 */
final readonly class AuditEntry
{
    public const string ACTION_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/D';

    public const string RESOURCE_TYPE_PATTERN = '/^[a-z][a-z0-9_-]{0,62}$/D';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $action,
        public string $resourceType,
        public string $resourceId,
        public Outcome $outcome = Outcome::Allowed,
        public array $metadata = [],
        public ?TenantId $tenantId = null,
        public ?Actor $actor = null,
        public ?DateTimeImmutable $occurredAt = null,
        public ?string $requestId = null,
        public ?string $correlationId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        /** Record on the global (tenant-less) chain whatever the tenant policy says; for platform-level security events. */
        public bool $global = false,
    ) {
        if ($global && $tenantId !== null) {
            throw InvalidAuditEntry::because('a global entry cannot carry a tenant id.');
        }

        if (preg_match(self::ACTION_PATTERN, $action) !== 1 || strlen($action) > 191) {
            throw InvalidAuditEntry::because(sprintf('action "%s" must be lowercase dot-namespaced (e.g. "domain.updated"), max 191 chars.', $action));
        }

        if (preg_match(self::RESOURCE_TYPE_PATTERN, $resourceType) !== 1) {
            throw InvalidAuditEntry::because(sprintf('resource type "%s" must match %s.', $resourceType, self::RESOURCE_TYPE_PATTERN));
        }

        if (trim($resourceId) === '' || strlen($resourceId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $resourceId) === 1) {
            throw InvalidAuditEntry::because('resource id must be 1-191 printable characters.');
        }

        if ($metadata !== [] && array_is_list($metadata)) {
            throw InvalidAuditEntry::because('metadata must be an associative array (string keys).');
        }

        if ($occurredAt !== null && $occurredAt > (new DateTimeImmutable)->modify('+5 minutes')) {
            throw InvalidAuditEntry::because('occurredAt lies more than five minutes in the future.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function make(
        string $action,
        string $resourceType,
        string $resourceId,
        Outcome|string $outcome = Outcome::Allowed,
        array $metadata = [],
        TenantId|string|null $tenantId = null,
        ?Actor $actor = null,
        ?DateTimeInterface $occurredAt = null,
        bool $global = false,
    ): self {
        return new self(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            outcome: Outcome::normalize($outcome),
            metadata: $metadata,
            tenantId: is_string($tenantId) ? TenantId::fromString($tenantId) : $tenantId,
            actor: $actor,
            occurredAt: $occurredAt === null ? null : DateTimeImmutable::createFromInterface($occurredAt),
            global: $global,
        );
    }

    public function withTenant(?TenantId $tenantId): self
    {
        return new self(
            $this->action, $this->resourceType, $this->resourceId, $this->outcome, $this->metadata,
            $tenantId, $this->actor, $this->occurredAt, $this->requestId, $this->correlationId, $this->ipAddress, $this->userAgent,
            $tenantId === null && $this->global,
        );
    }

    public function withActor(Actor $actor): self
    {
        return new self(
            $this->action, $this->resourceType, $this->resourceId, $this->outcome, $this->metadata,
            $this->tenantId, $actor, $this->occurredAt, $this->requestId, $this->correlationId, $this->ipAddress, $this->userAgent,
            $this->global,
        );
    }

    public function withRequestContext(?string $requestId, ?string $correlationId, ?string $ipAddress, ?string $userAgent): self
    {
        return new self(
            $this->action, $this->resourceType, $this->resourceId, $this->outcome, $this->metadata,
            $this->tenantId, $this->actor, $this->occurredAt,
            $this->requestId ?? $requestId,
            $this->correlationId ?? $correlationId,
            $this->ipAddress ?? $ipAddress,
            $this->userAgent ?? $userAgent,
            $this->global,
        );
    }
}
