<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Anchoring;

use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;

/**
 * Writes to every store; reads from the first store that has an anchor.
 * Order the external store first so verification prefers it.
 */
final class CompositeAnchorStore implements AnchorStore
{
    /** @var list<AnchorStore> */
    private readonly array $stores;

    public function __construct(AnchorStore ...$stores)
    {
        $this->stores = array_values($stores);
    }

    public function store(Anchor $anchor): void
    {
        foreach ($this->stores as $store) {
            $store->store($anchor);
        }
    }

    public function latest(ChainKey $chainKey, ?int $atOrBelowSequence = null): ?Anchor
    {
        foreach ($this->stores as $store) {
            $anchor = $store->latest($chainKey, $atOrBelowSequence);

            if ($anchor !== null) {
                return $anchor;
            }
        }

        return null;
    }

    public function name(): string
    {
        return implode('+', array_map(static fn (AnchorStore $s): string => $s->name(), $this->stores));
    }

    /**
     * @return list<AnchorStore>
     */
    public function stores(): array
    {
        return $this->stores;
    }
}
