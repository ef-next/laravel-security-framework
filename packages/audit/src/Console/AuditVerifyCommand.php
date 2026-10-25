<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Console;

use DateTimeImmutable;
use DateTimeZone;
use Efnext\Security\Audit\Contracts\ChainVerifier;
use Efnext\Security\Audit\Contracts\Signer;
use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Records\VerificationReport;
use Efnext\Security\Audit\Verification\VerificationRuns;
use Illuminate\Console\Command;

/**
 *   php artisan audit:verify                    all chains
 *   php artisan audit:verify --chain=<tenant>   one chain (tenant id or "global")
 *   php artisan audit:verify --from=1000        start at a sequence
 *   php artisan audit:verify --json             machine-readable report
 *
 * Exit code 1 when any chain has violations. Schedule it.
 */
final class AuditVerifyCommand extends Command
{
    protected $signature = 'audit:verify
        {--chain=* : Chain to verify (tenant id, tenant:<id> or "global"); repeatable}
        {--from=1 : First sequence to check}
        {--to= : Last sequence to check}
        {--json : Print the reports as JSON}
        {--fail-fast : Stop at the first chain with violations}';

    protected $description = 'Verify the integrity of the audit hash chains';

    public function handle(ChainVerifier $verifier, Signer $signer, VerificationRuns $runs): int
    {
        /** @var list<string> $requested */
        $requested = (array) $this->option('chain');
        $chains = $requested === []
            ? $verifier->chains()
            : array_map(static fn (string $key): ChainKey => ChainKey::fromString($key), $requested);

        if (! $signer->isConfigured()) {
            $this->components->warn('No signing key configured: hashes are verified, signatures are not. Set AUDIT_SIGNING_KEYS.');
        }

        $from = max(1, (int) $this->option('from'));
        $to = $this->option('to') !== null ? (int) $this->option('to') : null;
        $reports = [];
        $failed = 0;

        foreach ($chains as $chain) {
            $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $report = $verifier->verify($chain, $from, $to);
            $runs->record($report, VerificationRuns::SOURCE_VERIFY, $startedAt);
            $reports[] = $report;

            if (! $report->isIntact()) {
                $failed++;
            }

            if (! $this->option('json')) {
                $this->render($report);
            }

            if ($failed > 0 && $this->option('fail-fast')) {
                break;
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(array_map(static fn (VerificationReport $r): array => $r->toArray(), $reports), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->newLine();
            $this->components->twoColumnDetail('Chains checked', (string) count($reports));
            $this->components->twoColumnDetail('Chains with violations', (string) $failed);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function render(VerificationReport $report): void
    {
        $summary = sprintf('%d events, signatures: %s, anchor: %s', $report->eventsChecked, $report->signatures->value, $report->anchor !== null ? '#'.$report->anchor->sequence : 'none');

        if ($report->isIntact()) {
            $this->components->twoColumnDetail($report->chainKey->toString().' intact', $summary);

            return;
        }

        $this->components->twoColumnDetail($report->chainKey->toString().' VIOLATIONS', $summary);

        foreach ($report->violations as $violation) {
            $this->components->bulletList([$violation->describe()]);
        }
    }
}
