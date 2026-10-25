<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Hashing;

/**
 * The exact set of fields that go into an event hash (hash version 2).
 *
 * Everything that describes the event is included: identity, chain position,
 * time, actor, action, resource, outcome, metadata and request context. Only
 * event_hash, signature and recorded_at (a server timestamp added after
 * hashing) are excluded.
 */
final class HashableRecord
{
    public const int VERSION = 2;

    public const string DOMAIN = "efnext-audit-v2\n";

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function build(
        string $id,
        string $chainKey,
        ?string $tenantId,
        int $sequence,
        ?string $previousHash,
        int $occurredAtMicros,
        string $actorType,
        ?string $actorId,
        ?string $actorLabel,
        string $action,
        string $resourceType,
        string $resourceId,
        string $outcome,
        array $metadata,
        ?string $requestId,
        ?string $correlationId,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        return [
            'v' => self::VERSION,
            'id' => $id,
            'chain' => $chainKey,
            'tenant_id' => $tenantId,
            'sequence' => $sequence,
            'previous_hash' => $previousHash,
            'occurred_at_us' => $occurredAtMicros,
            'actor' => ['type' => $actorType, 'id' => $actorId, 'label' => $actorLabel],
            'action' => $action,
            'resource' => ['type' => $resourceType, 'id' => $resourceId],
            'outcome' => $outcome,
            'metadata' => $metadata,
            'request' => [
                'id' => $requestId,
                'correlation_id' => $correlationId,
                'ip' => $ipAddress,
                'user_agent' => $userAgent,
            ],
        ];
    }
}
