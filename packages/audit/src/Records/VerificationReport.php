<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Records;

use Efnext\Security\Audit\Enums\SignatureStatus;

final readonly class VerificationReport
{
    /**
     * @param  list<Violation>  $violations
     */
    public function __construct(
        public ChainKey $chainKey,
        public int $fromSequence,
        public ?int $toSequence,
        public int $eventsChecked,
        public ?string $lastHash,
        public array $violations,
        public SignatureStatus $signatures,
        public ?Anchor $anchor,
    ) {}

    public function isIntact(): bool
    {
        return $this->violations === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chain_key' => $this->chainKey->toString(),
            'from_sequence' => $this->fromSequence,
            'to_sequence' => $this->toSequence,
            'events_checked' => $this->eventsChecked,
            'last_hash' => $this->lastHash,
            'intact' => $this->isIntact(),
            'signatures' => $this->signatures->value,
            'anchor' => $this->anchor?->toArray(),
            'violations' => array_map(static fn (Violation $v): array => $v->toArray(), $this->violations),
        ];
    }
}
