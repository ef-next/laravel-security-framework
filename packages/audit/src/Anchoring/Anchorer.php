<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Anchoring;

use DateTimeImmutable;
use DateTimeZone;
use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Contracts\Signer;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Snapshots chain heads into the anchor store(s).
 */
final class Anchorer
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly AnchorStore $store,
        private readonly Signer $signer,
        private readonly ?string $connection = null,
        private readonly string $headsTable = 'audit_chain_heads',
    ) {}

    /**
     * Anchor one chain. Returns null when the chain is empty or unchanged since the last anchor.
     */
    public function anchor(ChainKey $chainKey, bool $force = false): ?Anchor
    {
        $head = $this->connections->connection($this->connection)
            ->table($this->headsTable)
            ->where('chain_key', $chainKey->toString())
            ->first(['last_sequence', 'last_hash']);

        if ($head === null || (int) $head->last_sequence < 1 || ! is_string($head->last_hash)) {
            return null;
        }

        $sequence = (int) $head->last_sequence;
        $latest = $this->store->latest($chainKey);

        if (! $force && $latest !== null && $latest->sequence === $sequence && hash_equals($latest->eventHash, $head->last_hash)) {
            return null;
        }

        $anchor = new Anchor(
            chainKey: $chainKey,
            sequence: $sequence,
            eventHash: $head->last_hash,
            signature: $this->signer->sign($head->last_hash),
            anchoredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $this->store->store($anchor);

        return $anchor;
    }

    /**
     * @return list<ChainKey>
     */
    public function chains(): array
    {
        $keys = $this->connections->connection($this->connection)->table($this->headsTable)->orderBy('chain_key')->pluck('chain_key');

        return array_values(array_map(static fn (string $key): ChainKey => ChainKey::fromString($key), $keys->all()));
    }

    public function store(): AnchorStore
    {
        return $this->store;
    }
}
