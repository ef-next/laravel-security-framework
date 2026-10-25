<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Events;

use Efnext\Security\Audit\Records\VerificationReport;

/**
 * Fired by the verifier for every chain with violations. Alert on it.
 */
final readonly class ChainViolationDetected
{
    public function __construct(
        public VerificationReport $report,
    ) {}
}
