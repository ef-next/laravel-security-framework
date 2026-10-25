<?php

declare(strict_types=1);

namespace Efnext\Security\Audit;

use DateTimeInterface;
use Efnext\Security\Addressing\Contracts\ExternallyAddressable;
use Efnext\Security\Audit\Contracts\AuditWriter;
use Efnext\Security\Audit\Eloquent\AuditEvent;
use Efnext\Security\Audit\Enums\Outcome;
use Efnext\Security\Audit\Records\Actor;
use Efnext\Security\Audit\Records\AuditEntry;
use Efnext\Security\Tenant\Context\TenantId;

/**
 * Fluent entry point:
 *
 *   Audit::action('domain.verified')
 *       ->on($domain)                       // ExternallyAddressable, or ->on('domain', 'dom_...')
 *       ->with(['method' => 'dns'])
 *       ->record();
 *
 *   Audit::action('auth.login_failed')->on('user', $email)->denied()->record();
 */
final class AuditBuilder
{
    private ?string $resourceType = null;

    private ?string $resourceId = null;

    private Outcome $outcome = Outcome::Allowed;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private ?TenantId $tenantId = null;

    private bool $global = false;

    private ?Actor $actor = null;

    private ?DateTimeInterface $occurredAt = null;

    public function __construct(
        private readonly AuditWriter $writer,
        private readonly string $action,
    ) {}

    public function on(ExternallyAddressable|string $resource, ?string $resourceId = null): self
    {
        if ($resource instanceof ExternallyAddressable) {
            $this->resourceType = $resource::resourceType();
            $this->resourceId = $resource->publicId()->toString();

            return $this;
        }

        $this->resourceType = $resource;
        $this->resourceId = $resourceId;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function with(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    public function allowed(): self
    {
        $this->outcome = Outcome::Allowed;

        return $this;
    }

    public function denied(): self
    {
        $this->outcome = Outcome::Denied;

        return $this;
    }

    public function failed(): self
    {
        $this->outcome = Outcome::Failed;

        return $this;
    }

    public function outcome(Outcome|string $outcome): self
    {
        $this->outcome = Outcome::normalize($outcome);

        return $this;
    }

    public function forTenant(TenantId|string|null $tenantId): self
    {
        $this->tenantId = is_string($tenantId) ? TenantId::fromString($tenantId) : $tenantId;
        $this->global = false;

        return $this;
    }

    /**
     * Record on the global chain (no tenant), regardless of audit.tenant_policy
     * and of any active tenant context. For platform-level events only.
     */
    public function global(): self
    {
        $this->tenantId = null;
        $this->global = true;

        return $this;
    }

    public function by(Actor $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function bySystem(string $component = 'system'): self
    {
        return $this->by(Actor::system($component));
    }

    public function at(DateTimeInterface $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function entry(): AuditEntry
    {
        return AuditEntry::make(
            action: $this->action,
            resourceType: $this->resourceType ?? throw Exceptions\InvalidAuditEntry::because('call ->on(...) to name the resource.'),
            resourceId: $this->resourceId ?? throw Exceptions\InvalidAuditEntry::because('call ->on(...) with a resource id.'),
            outcome: $this->outcome,
            metadata: $this->metadata,
            tenantId: $this->tenantId,
            actor: $this->actor,
            occurredAt: $this->occurredAt,
            global: $this->global,
        );
    }

    public function record(): AuditEvent
    {
        return $this->writer->record($this->entry());
    }
}
