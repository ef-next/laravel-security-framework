<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Contracts;

use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;

interface AnchorStore
{
    public function store(Anchor $anchor): void;

    /**
     * The most recent anchor for the chain, optionally at or below a sequence.
     * Write-only stores return null.
     */
    public function latest(ChainKey $chainKey, ?int $atOrBelowSequence = null): ?Anchor;

    public function name(): string;
}
