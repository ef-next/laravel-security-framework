<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Writing;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Efnext\Security\Audit\Contracts\ActorResolver;
use Efnext\Security\Audit\Contracts\AuditWriter;
use Efnext\Security\Audit\Contracts\MetadataSanitizer;
use Efnext\Security\Audit\Contracts\Signer;
use Efnext\Security\Audit\Eloquent\AuditEvent;
use Efnext\Security\Audit\Events\AuditEventRecorded;
use Efnext\Security\Audit\Exceptions\InvalidAuditEntry;
use Efnext\Security\Audit\Hashing\CanonicalJson;
use Efnext\Security\Audit\Hashing\EventHasher;
use Efnext\Security\Audit\Hashing\HashableRecord;
use Efnext\Security\Audit\Records\AuditEntry;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Appends events to per-chain hash chains.
 *
 * Serialisation: each chain has a row in the heads table that is locked
 * (SELECT ... FOR UPDATE) for the duration of the insert, so concurrent
 * writers for the same chain queue up instead of forking the chain. The
 * sequence number comes from that row; a UNIQUE(chain_key, sequence) index is
 * the backstop.
 *
 * Deadlock avoidance (InnoDB): the head row is created in its own auto-commit
 * statement *before* the write transaction starts. Creating it inside the
 * transaction (INSERT ... IGNORE followed by SELECT ... FOR UPDATE) makes
 * every concurrent first writer hold a shared lock on the same row and then
 * ask for an exclusive one, which InnoDB resolves by killing all but one.
 * With the row in place, FOR UPDATE takes a plain record lock and writers
 * simply queue. Remaining deadlocks (e.g. an outer transaction opened by the
 * caller) are retried with jitter when the writer owns the transaction.
 *
 * Ordering: the chain is ordered by sequence, never by timestamps, so clock
 * skew between application servers cannot produce false alarms.
 */
final class DatabaseAuditWriter implements AuditWriter
{
    use DetectsConcurrencyErrors;

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly TenantContextManager $tenancy,
        private readonly ActorResolver $actors,
        private readonly RequestContextResolver $requests,
        private readonly MetadataSanitizer $sanitizer,
        private readonly EventHasher $hasher,
        private readonly Signer $signer,
        private readonly Container $container,
        private readonly ?string $connection = null,
        private readonly string $headsTable = 'audit_chain_heads',
        private readonly string $tenantPolicy = 'required',
        private readonly int $writeAttempts = 5,
    ) {}

    public function record(AuditEntry $entry): AuditEvent
    {
        $tenantId = $this->resolveTenant($entry);
        $chainKey = ChainKey::for($tenantId);
        $actor = $entry->actor ?? $this->actors->resolve();
        $request = $this->requests->resolve();
        $metadata = CanonicalJson::roundTrip($this->sanitizer->sanitize($entry->metadata));
        $occurredAt = ($entry->occurredAt ?? new DateTimeImmutable)->setTimezone(new DateTimeZone('UTC'));
        $occurredAtMicros = (int) $occurredAt->format('Uu');
        $id = (string) Str::ulid();

        $connection = $this->connections->connection($this->connection);

        $this->ensureHead($connection, $chainKey);

        return $this->transactionWithRetries($connection, function () use ($connection, $entry, $tenantId, $chainKey, $actor, $request, $metadata, $occurredAt, $occurredAtMicros, $id): AuditEvent {
            [$sequence, $previousHash] = $this->reserveSequence($connection, $chainKey);

            $record = HashableRecord::build(
                id: $id,
                chainKey: $chainKey->toString(),
                tenantId: $tenantId?->toString(),
                sequence: $sequence,
                previousHash: $previousHash,
                occurredAtMicros: $occurredAtMicros,
                actorType: $actor->type->value,
                actorId: $actor->id,
                actorLabel: $actor->label,
                action: $entry->action,
                resourceType: $entry->resourceType,
                resourceId: $entry->resourceId,
                outcome: $entry->outcome->value,
                metadata: $metadata,
                requestId: $entry->requestId ?? $request['request_id'],
                correlationId: $entry->correlationId ?? $request['correlation_id'],
                ipAddress: $entry->ipAddress ?? $request['ip_address'],
                userAgent: $entry->userAgent ?? $request['user_agent'],
            );

            $eventHash = $this->hasher->hash($record);
            $signature = $this->signer->sign($eventHash);
            $recordedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $event = (new AuditEvent)->forceFill([
                'id' => $id,
                'chain_key' => $chainKey->toString(),
                'tenant_id' => $tenantId?->toString(),
                'sequence' => $sequence,
                'previous_hash' => $previousHash,
                'event_hash' => $eventHash,
                'hash_version' => HashableRecord::VERSION,
                'key_id' => $signature?->keyId,
                'signature' => $signature?->value,
                'occurred_at' => $occurredAt,
                'occurred_at_us' => $occurredAtMicros,
                'recorded_at' => $recordedAt,
                'actor_type' => $record['actor']['type'],
                'actor_id' => $record['actor']['id'],
                'actor_label' => $record['actor']['label'],
                'action' => $entry->action,
                'resource_type' => $entry->resourceType,
                'resource_id' => $entry->resourceId,
                'outcome' => $entry->outcome->value,
                'metadata' => $metadata === [] ? null : $metadata,
                'request_id' => $record['request']['id'],
                'correlation_id' => $record['request']['correlation_id'],
                'ip_address' => $record['request']['ip'],
                'user_agent' => $record['request']['user_agent'],
            ]);

            $event->setConnection($this->connection);
            $event->save();

            $connection->table($this->headsTable)
                ->where('chain_key', $chainKey->toString())
                ->update(['last_sequence' => $sequence, 'last_hash' => $eventHash, 'updated_at' => $recordedAt->format('Y-m-d H:i:s.u')]);

            $this->container->make(Dispatcher::class)->dispatch(new AuditEventRecorded($event));

            return $event;
        });
    }

    public function prepareChain(?TenantId $tenantId): void
    {
        $this->ensureHead($this->connections->connection($this->connection), ChainKey::for($tenantId));
    }

    /**
     * Make sure the chain's head row exists before the write transaction
     * starts. Outside a transaction this is an auto-commit statement whose
     * duplicate-key shared lock is released immediately, so it can never
     * deadlock with the FOR UPDATE that follows. Inside a caller's transaction
     * it necessarily joins that transaction; the very first event of a chain
     * can then still deadlock under contention, and Laravel hands that
     * deadlock to the outer transaction to retry. Call prepareChain() when
     * you create a tenant to avoid even that.
     */
    private function ensureHead(ConnectionInterface $connection, ChainKey $chainKey): void
    {
        $key = $chainKey->toString();

        if ($connection->table($this->headsTable)->where('chain_key', $key)->exists()) {
            return;
        }

        $connection->table($this->headsTable)->insertOrIgnore([
            'chain_key' => $key,
            'last_sequence' => 0,
            'last_hash' => null,
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * Run the write in a transaction. When the writer owns the transaction,
     * deadlocks and serialization failures are retried with growing jitter;
     * inside a caller's transaction they propagate (the caller must retry).
     *
     * @param  Closure(): AuditEvent  $callback
     */
    private function transactionWithRetries(ConnectionInterface $connection, Closure $callback): AuditEvent
    {
        $attempts = $connection->transactionLevel() === 0 ? max(1, $this->writeAttempts) : 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                /** @var AuditEvent $event */
                $event = $connection->transaction($callback);

                return $event;
            } catch (Throwable $e) {
                if ($attempt >= $attempts || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                usleep(random_int(1_000, 25_000) * $attempt);
            }
        }
    }

    private function resolveTenant(AuditEntry $entry): ?TenantId
    {
        if ($entry->global) {
            return null;
        }

        $tenantId = $entry->tenantId ?? $this->tenancy->currentId();

        if ($tenantId === null && $this->tenantPolicy !== 'nullable') {
            throw InvalidAuditEntry::tenantRequired();
        }

        return $tenantId;
    }

    /**
     * Lock the (existing) chain head and hand out the next sequence number.
     *
     * @return array{0: int, 1: ?string}
     */
    private function reserveSequence(ConnectionInterface $connection, ChainKey $chainKey): array
    {
        $head = $connection->table($this->headsTable)
            ->where('chain_key', $chainKey->toString())
            ->lockForUpdate()
            ->first(['last_sequence', 'last_hash']);

        if ($head === null) {
            // Only reachable if the head was deleted between ensureHead() and here.
            throw new RuntimeException(sprintf('Audit chain head "%s" is missing; refusing to write without a sequence source.', $chainKey->toString()));
        }

        $data = (array) $head;
        // char(64) comes back space-padded from PostgreSQL; a blank hash means "no predecessor".
        $lastHash = is_string($data['last_hash'] ?? null) ? trim($data['last_hash']) : '';

        return [((int) $data['last_sequence']) + 1, $lastHash !== '' ? $lastHash : null];
    }
}
