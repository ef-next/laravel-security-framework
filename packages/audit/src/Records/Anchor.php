<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Records;

use DateTimeImmutable;

/**
 * A snapshot of a chain head, stored outside the chain so that later rewrites
 * of the chain can be detected.
 */
final readonly class Anchor
{
    public function __construct(
        public ChainKey $chainKey,
        public int $sequence,
        public string $eventHash,
        public ?Signature $signature,
        public DateTimeImmutable $anchoredAt,
    ) {}

    /**
     * @return array{chain_key: string, sequence: int, event_hash: string, key_id: ?string, signature: ?string, anchored_at: string}
     */
    public function toArray(): array
    {
        return [
            'chain_key' => $this->chainKey->toString(),
            'sequence' => $this->sequence,
            'event_hash' => $this->eventHash,
            'key_id' => $this->signature?->keyId,
            'signature' => $this->signature?->value,
            'anchored_at' => $this->anchoredAt->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $keyId = $data['key_id'] ?? null;
        $signature = $data['signature'] ?? null;

        return new self(
            chainKey: ChainKey::fromString((string) $data['chain_key']),
            sequence: (int) $data['sequence'],
            eventHash: (string) $data['event_hash'],
            signature: is_string($keyId) && is_string($signature) ? new Signature($keyId, $signature) : null,
            anchoredAt: new DateTimeImmutable((string) $data['anchored_at']),
        );
    }
}
