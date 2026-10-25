<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Anchoring;

use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;

final class NullAnchorStore implements AnchorStore
{
    public function store(Anchor $anchor): void {}

    public function latest(ChainKey $chainKey, ?int $atOrBelowSequence = null): ?Anchor
    {
        return null;
    }

    public function name(): string
    {
        return 'none';
    }
}
