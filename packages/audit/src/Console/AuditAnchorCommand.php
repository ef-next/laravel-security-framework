<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Console;

use Efnext\Security\Audit\Anchoring\Anchorer;
use Efnext\Security\Audit\Contracts\Signer;
use Efnext\Security\Audit\Records\ChainKey;
use Illuminate\Console\Command;

/**
 *   php artisan audit:anchor            anchor every chain that changed
 *   php artisan audit:anchor --force    anchor even if unchanged
 *
 * Schedule it (e.g. every five minutes). Anchors written to a store outside
 * the database are what make wholesale chain rewrites detectable.
 */
final class AuditAnchorCommand extends Command
{
    protected $signature = 'audit:anchor
        {--chain=* : Chain to anchor (tenant id, tenant:<id> or "global"); repeatable}
        {--force : Write an anchor even when the head has not moved}';

    protected $description = 'Store the current audit chain heads in the configured anchor store(s)';

    public function handle(Anchorer $anchorer, Signer $signer): int
    {
        if (! $signer->isConfigured()) {
            $this->components->warn('No signing key configured: anchors are unsigned. Set AUDIT_SIGNING_KEYS.');
        }

        /** @var list<string> $requested */
        $requested = (array) $this->option('chain');
        $chains = $requested === []
            ? $anchorer->chains()
            : array_map(static fn (string $key): ChainKey => ChainKey::fromString($key), $requested);

        $written = 0;

        foreach ($chains as $chain) {
            $anchor = $anchorer->anchor($chain, (bool) $this->option('force'));

            if ($anchor === null) {
                $this->components->twoColumnDetail($chain->toString(), 'unchanged');

                continue;
            }

            $written++;
            $this->components->twoColumnDetail($chain->toString(), sprintf('anchored #%d (%s)', $anchor->sequence, $anchor->signature !== null ? 'signed '.$anchor->signature->keyId : 'unsigned'));
        }

        $this->newLine();
        $this->components->twoColumnDetail('Store', $anchorer->store()->name());
        $this->components->twoColumnDetail('Anchors written', (string) $written);

        return self::SUCCESS;
    }
}
