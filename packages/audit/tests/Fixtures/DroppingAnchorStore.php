<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Tests\Fixtures;

use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;

/**
 * A custom store that looks external and healthy but keeps nothing: what a
 * misconfigured bucket or a stubbed integration behaves like. Structurally
 * valid, so the boot-time guard accepts it; the doctor must catch it.
 */
final class DroppingAnchorStore implements AnchorStore
{
    public function store(Anchor $anchor): void {}

    public function latest(ChainKey $chainKey, ?int $atOrBelowSequence = null): ?Anchor
    {
        return null;
    }

    public function name(): string
    {
        return 'dropping';
    }
}
