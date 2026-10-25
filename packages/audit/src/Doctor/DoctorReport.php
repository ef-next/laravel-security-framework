<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Doctor;

final readonly class DoctorReport
{
    /**
     * @param  list<Check>  $checks
     */
    public function __construct(public array $checks) {}

    public function hasFailures(): bool
    {
        return $this->count(CheckStatus::Fail) > 0;
    }

    public function hasWarnings(): bool
    {
        return $this->count(CheckStatus::Warn) > 0;
    }

    public function count(CheckStatus $status): int
    {
        return count(array_filter($this->checks, static fn (Check $check): bool => $check->status === $status));
    }

    public function find(string $id): ?Check
    {
        foreach ($this->checks as $check) {
            if ($check->id === $id) {
                return $check;
            }
        }

        return null;
    }

    /**
     * `healthy` is the verdict a pipeline gates on: no failures, and under --strict no warnings either.
     *
     * @return array{healthy: bool, strict: bool, counts: array<string, int>, checks: list<array{id: string, status: string, title: string, detail: string, hint: ?string}>}
     */
    public function toArray(bool $strict = false): array
    {
        return [
            'healthy' => ! $this->hasFailures() && ! ($strict && $this->hasWarnings()),
            'strict' => $strict,
            'counts' => [
                'ok' => $this->count(CheckStatus::Ok),
                'warn' => $this->count(CheckStatus::Warn),
                'fail' => $this->count(CheckStatus::Fail),
                'skip' => $this->count(CheckStatus::Skip),
            ],
            'checks' => array_map(static fn (Check $check): array => $check->toArray(), $this->checks),
        ];
    }
}
