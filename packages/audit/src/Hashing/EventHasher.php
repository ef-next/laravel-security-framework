<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Hashing;

/**
 * SHA-256 over the domain-separated canonical JSON of a HashableRecord.
 * Fixed algorithm: a configurable hash function is a foot-gun for a chain.
 */
final class EventHasher
{
    public const string ALGORITHM = 'sha256';

    /**
     * @param  array<string, mixed>  $record  as produced by HashableRecord::build()
     */
    public function hash(array $record): string
    {
        return hash(self::ALGORITHM, HashableRecord::DOMAIN.CanonicalJson::encode($record));
    }

    public function supports(int $version): bool
    {
        return $version === HashableRecord::VERSION;
    }
}
