<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Verification;

use DateTimeImmutable;
use DateTimeZone;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Records\VerificationReport;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Bookkeeping of verification runs (audit:verify, security:doctor): when was
 * a chain last verified, from where, and was it intact. This is operational
 * data for monitoring, not evidence; the chain itself is the evidence.
 */
final class VerificationRuns
{
    public const string SOURCE_VERIFY = 'audit:verify';

    public const string SOURCE_DOCTOR = 'security:doctor';

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
        private readonly string $table = 'audit_verification_runs',
    ) {}

    public function record(VerificationReport $report, string $source, DateTimeImmutable $startedAt): void
    {
        $this->connections->connection($this->connection)->table($this->table)->insert([
            'chain_key' => $report->chainKey->toString(),
            'source' => $source,
            'from_sequence' => $report->fromSequence,
            'to_sequence' => $report->toSequence,
            'events_checked' => $report->eventsChecked,
            'violations' => count($report->violations),
            'intact' => $report->isIntact(),
            'started_at' => $startedAt,
            'finished_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ]);
    }

    /**
     * The most recent full run (from sequence 1, unbounded) for a chain.
     *
     * @return array{intact: bool, finished_at: DateTimeImmutable, source: string, events_checked: int}|null
     */
    public function latestFull(ChainKey $chainKey): ?array
    {
        $row = $this->connections->connection($this->connection)->table($this->table)
            ->where('chain_key', $chainKey->toString())
            ->where('from_sequence', 1)
            ->whereNull('to_sequence')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first(['intact', 'finished_at', 'source', 'events_checked']);

        if ($row === null) {
            return null;
        }

        $data = (array) $row;

        return [
            'intact' => (bool) $data['intact'],
            'finished_at' => new DateTimeImmutable((string) $data['finished_at']),
            'source' => (string) $data['source'],
            'events_checked' => (int) $data['events_checked'],
        ];
    }

    public function table(): string
    {
        return $this->table;
    }
}
