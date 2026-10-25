<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Anchoring;

use Efnext\Security\Audit\Contracts\AnchorStore;
use Efnext\Security\Audit\Records\Anchor;
use Efnext\Security\Audit\Records\ChainKey;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * One JSON document per anchor on a Laravel disk, e.g. S3 with Object Lock.
 * Path: <base>/<chain key>/<sequence padded>.json
 */
final class FilesystemAnchorStore implements AnchorStore
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $basePath = 'audit-anchors',
    ) {}

    public function store(Anchor $anchor): void
    {
        $this->disk->put($this->path($anchor->chainKey, $anchor->sequence), json_encode($anchor->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    public function latest(ChainKey $chainKey, ?int $atOrBelowSequence = null): ?Anchor
    {
        $files = $this->disk->files($this->directory($chainKey));
        $best = null;
        $bestSequence = -1;

        foreach ($files as $file) {
            if (preg_match('/(\d{20})\.json$/', $file, $m) !== 1) {
                continue;
            }

            $sequence = (int) $m[1];

            if (($atOrBelowSequence === null || $sequence <= $atOrBelowSequence) && $sequence > $bestSequence) {
                $bestSequence = $sequence;
                $best = $file;
            }
        }

        if ($best === null) {
            return null;
        }

        $json = $this->disk->get($best);

        if ($json === null) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        return Anchor::fromArray($data);
    }

    public function name(): string
    {
        return 'filesystem';
    }

    private function directory(ChainKey $chainKey): string
    {
        return trim($this->basePath, '/').'/'.str_replace(':', '_', $chainKey->toString());
    }

    private function path(ChainKey $chainKey, int $sequence): string
    {
        return $this->directory($chainKey).'/'.str_pad((string) $sequence, 20, '0', STR_PAD_LEFT).'.json';
    }
}
