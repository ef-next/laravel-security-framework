<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Records;

use Efnext\Security\Audit\Enums\ViolationReason;

final readonly class Violation
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public ViolationReason $reason,
        public ?int $sequence = null,
        public ?string $eventId = null,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'reason' => $this->reason->value,
            'sequence' => $this->sequence,
            'event_id' => $this->eventId,
        ], $this->details);
    }

    public function describe(): string
    {
        $where = $this->sequence !== null ? sprintf(' at sequence %d', $this->sequence) : '';

        return $this->reason->value.$where.($this->details !== [] ? ' '.json_encode($this->details, JSON_UNESCAPED_SLASHES) : '');
    }
}
