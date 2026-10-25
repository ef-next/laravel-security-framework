<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Anchoring;

use DateTimeImmutable;
use DateTimeZone;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;

/**
 * Anchors in the database (append-only table). Convenient, but an attacker
 * with database access can remove them; combine with an external store.
 */
final class DatabaseAnchorStore implements AnchorStore
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
        private readonly string $table = 'audit_anchors',
    ) {}

    public function store(Anchor $anchor): void
    {
        $this->connections->connection($this->connection)->table($this->table)->insert([
            'id' => (string) Str::ulid(),
            'chain_key' => $anchor->chainKey->toString(),
            'sequence' => $anchor->sequence,
            'event_hash' => $anchor->eventHash,
            'key_id' => $anchor->signature?->keyId,
            'signature' => $anchor->signature?->value,
            // Explicit microsecond format: the query grammar would otherwise bind whole seconds.
            'anchored_at' => $anchor->anchoredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function latest(ChainKey $chainKey, ?int $atOrBelowSequence = null): ?Anchor
    {
        $query = $this->connections->connection($this->connection)
            ->table($this->table)
            ->where('chain_key', $chainKey->toString())
            ->orderByDesc('sequence')
            ->orderByDesc('id');

        if ($atOrBelowSequence !== null) {
            $query->where('sequence', '<=', $atOrBelowSequence);
        }

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        $data = (array) $row;

        return Anchor::fromArray([
            'chain_key' => $data['chain_key'],
            'sequence' => $data['sequence'],
            'event_hash' => $data['event_hash'],
            'key_id' => $data['key_id'] ?? null,
            'signature' => $data['signature'] ?? null,
            'anchored_at' => (string) $data['anchored_at'],
        ]);
    }

    public function name(): string
    {
        return 'database';
    }
}
