<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Records;

use Efnext\Security\Audit\Enums\ActorType;

/**
 * Who did it. Part of the hashed record.
 */
final readonly class Actor
{
    public function __construct(
        public ActorType $type,
        public ?string $id = null,
        public ?string $label = null,
    ) {}

    public static function user(string|int $id, ?string $label = null): self
    {
        return new self(ActorType::User, (string) $id, $label);
    }

    public static function service(string $id, ?string $label = null): self
    {
        return new self(ActorType::Service, $id, $label);
    }

    public static function system(string $component = 'system'): self
    {
        return new self(ActorType::System, $component);
    }

    public static function anonymous(): self
    {
        return new self(ActorType::Anonymous);
    }

    /**
     * @return array{type: string, id: ?string, label: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'label' => $this->label,
        ];
    }
}
