<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Console;

use Efnext\Security\Audit\Doctor\Check;
use Efnext\Security\Audit\Doctor\CheckStatus;
use Efnext\Security\Audit\Doctor\SecurityDoctor;
use Illuminate\Console\Command;

/**
 *   php artisan security:doctor              human-readable report, exit 1 on FAIL
 *   php artisan security:doctor --strict     the high-security profile: exit 1 on WARN as well, and
 *                                            AUDIT_REQUIRE_EXTERNAL_ANCHOR must be on (the deployment gate)
 *   php artisan security:doctor --json       machine-readable (monitoring, CI)
 *   php artisan security:doctor --no-verify  skip the live tail verification
 *
 * Run it after every deployment and from monitoring. It answers "would this
 * installation still give the guarantees the README promises?".
 */
final class SecurityDoctorCommand extends Command
{
    protected $signature = 'security:doctor
        {--strict : High-security profile: warnings are failures and the external-anchor requirement must be enforced}
        {--json : Print the report as JSON}
        {--no-verify : Skip the live verification of chain tails}';

    protected $description = 'Check the security framework installation for production readiness';

    public function handle(SecurityDoctor $doctor): int
    {
        $report = $doctor->run(verifyTails: ! $this->option('no-verify'), strict: (bool) $this->option('strict'));

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(strict: (bool) $this->option('strict')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->render($report->checks);
            $this->newLine();
            $this->components->twoColumnDetail('OK / WARN / FAIL / SKIP', sprintf(
                '%d / %d / %d / %d',
                $report->count(CheckStatus::Ok),
                $report->count(CheckStatus::Warn),
                $report->count(CheckStatus::Fail),
                $report->count(CheckStatus::Skip),
            ));
        }

        if ($report->hasFailures() || ($this->option('strict') && $report->hasWarnings())) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<Check>  $checks
     */
    private function render(array $checks): void
    {
        foreach ($checks as $check) {
            $this->components->twoColumnDetail(sprintf('[%s] %s', $check->status->label(), $check->title), $check->detail);

            if ($check->hint !== null && $check->status !== CheckStatus::Ok) {
                $this->line('         '.$check->hint);
            }
        }
    }
}
