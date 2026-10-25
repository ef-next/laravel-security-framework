<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Contracts;

use Efnext\Security\Audit\Records\ChainKey;
use Efnext\Security\Audit\Records\VerificationReport;

interface ChainVerifier
{
    public function verify(ChainKey $chainKey, int $fromSequence = 1, ?int $toSequence = null): VerificationReport;

    /**
     * @return list<ChainKey>
     */
    public function chains(): array;
}
