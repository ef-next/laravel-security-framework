<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Anchoring;

use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;
use Psr\Log\LoggerInterface;

/**
 * Write-only: emits each anchor as a structured log line so an external log
 * sink (SIEM, immutable log storage) keeps a copy the database cannot alter.
 */
final class LogAnchorStore implements AnchorStore
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function store(Anchor $anchor): void
    {
        $this->logger->info('audit.anchor', $anchor->toArray());
    }

    public function latest(ChainKey $chainKey, ?int $atOrBelowSequence = null): ?Anchor
    {
        return null;
    }

    public function name(): string
    {
        return 'log';
    }
}
