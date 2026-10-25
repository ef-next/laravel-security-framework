<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Eloquent;

use Efnext\Security\Audit\Enums\ActorType;
use Efnext\Security\Audit\Enums\Outcome;
use Efnext\Security\Audit\Exceptions\AuditEventImmutable;
use Efnext\Security\Audit\Hashing\HashableRecord;
use Efnext\Security\Audit\Records\Actor;
use Efnext\Security\Audit\Records\ChainKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One audit record. Read-only after insert: updates and deletes throw here and
 * are additionally blocked by database triggers.
 *
 * @property string $id
 * @property string $chain_key
 * @property string|null $tenant_id
 * @property int $sequence
 * @property string|null $previous_hash
 * @property string $event_hash
 * @property int $hash_version
 * @property string|null $key_id
 * @property string|null $signature
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property int $occurred_at_us
 * @property \Carbon\CarbonImmutable $recorded_at
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property string $resource_type
 * @property string $resource_id
 * @property string $outcome
 * @property array<string, mixed>|null $metadata
 * @property string|null $request_id
 * @property string|null $correlation_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
final class AuditEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    /** The timestamp columns have microsecond precision; keep it when writing (occurred_at then matches occurred_at_us). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'hash_version' => 'integer',
            'occurred_at_us' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'metadata' => CanonicalJsonCast::class,
        ];
    }

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw AuditEventImmutable::create());
        self::deleting(static fn (): never => throw AuditEventImmutable::create());
    }

    public function getTable(): string
    {
        $configured = config('audit.table', 'audit_events');

        return is_string($configured) && $configured !== '' ? $configured : 'audit_events';
    }

    public function getConnectionName(): ?string
    {
        $configured = config('audit.connection');

        return is_string($configured) && $configured !== '' ? $configured : parent::getConnectionName();
    }

    public function chainKey(): ChainKey
    {
        return ChainKey::fromString($this->chain_key);
    }

    public function actor(): Actor
    {
        return new Actor(ActorType::tryFrom($this->actor_type) ?? ActorType::System, $this->actor_id, $this->actor_label);
    }

    public function outcome(): Outcome
    {
        return Outcome::normalize($this->outcome);
    }

    /**
     * The fields exactly as they went into the hash.
     *
     * @return array<string, mixed>
     */
    public function hashableRecord(): array
    {
        return HashableRecord::build(
            id: $this->id,
            chainKey: $this->chain_key,
            tenantId: $this->tenant_id,
            sequence: $this->sequence,
            previousHash: $this->previous_hash,
            occurredAtMicros: $this->occurred_at_us,
            actorType: $this->actor_type,
            actorId: $this->actor_id,
            actorLabel: $this->actor_label,
            action: $this->action,
            resourceType: $this->resource_type,
            resourceId: $this->resource_id,
            outcome: $this->outcome,
            metadata: $this->metadata ?? [],
            requestId: $this->request_id,
            correlationId: $this->correlation_id,
            ipAddress: $this->ip_address,
            userAgent: $this->user_agent,
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForChain(Builder $query, ChainKey $chainKey): Builder
    {
        return $query->where('chain_key', $chainKey->toString());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForResource(Builder $query, string $resourceType, string $resourceId): Builder
    {
        return $query->where('resource_type', $resourceType)->where('resource_id', $resourceId);
    }
}
