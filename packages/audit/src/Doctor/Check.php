<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Doctor;

/**
 * One finding of security:doctor.
 */
final readonly class Check
{
    public function __construct(
        public string $id,
        public CheckStatus $status,
        public string $title,
        public string $detail,
        public ?string $hint = null,
    ) {}

    public static function ok(string $id, string $title, string $detail): self
    {
        return new self($id, CheckStatus::Ok, $title, $detail);
    }

    public static function warn(string $id, string $title, string $detail, ?string $hint = null): self
    {
        return new self($id, CheckStatus::Warn, $title, $detail, $hint);
    }

    public static function fail(string $id, string $title, string $detail, ?string $hint = null): self
    {
        return new self($id, CheckStatus::Fail, $title, $detail, $hint);
    }

    public static function skip(string $id, string $title, string $detail): self
    {
        return new self($id, CheckStatus::Skip, $title, $detail);
    }

    /**
     * @return array{id: string, status: string, title: string, detail: string, hint: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'title' => $this->title,
            'detail' => $this->detail,
            'hint' => $this->hint,
        ];
    }
}
