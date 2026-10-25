<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Locating;

use Efnext\Security\Tenant\Contracts\TenantLocator;
use Illuminate\Contracts\Cache\Repository;

/**
 * Caches locator results per identifier. Status changes propagate within ttl
 * seconds, or immediately when forget() is called (the Tenant model does this
 * on save).
 */
final class CachedTenantLocator implements TenantLocator
{
    public function __construct(
        private readonly TenantLocator $inner,
        private readonly Repository $cache,
        private readonly int $ttlSeconds = 60,
        private readonly int $negativeTtlSeconds = 5,
        private readonly string $prefix = 'efnext:tenant:',
    ) {}

    public function locate(string $identifier): ?TenantRecord
    {
        $key = $this->key($identifier);
        $cached = $this->cache->get($key);

        if ($cached === false) {
            return null;
        }

        if (is_array($cached) && isset($cached['id'], $cached['status'])) {
            /** @var array{id: string, status: string, public_id?: ?string, slug?: ?string, name?: ?string} $cached */
            return TenantRecord::fromArray($cached);
        }

        $record = $this->inner->locate($identifier);

        if ($record === null) {
            if ($this->negativeTtlSeconds > 0) {
                $this->cache->put($key, false, $this->negativeTtlSeconds);
            }

            return null;
        }

        $this->cache->put($key, $record->toArray(), $this->ttlSeconds);

        return $record;
    }

    /**
     * Drop every cache entry that may point at this tenant.
     */
    public function forget(TenantRecord $record): void
    {
        foreach (array_filter([$record->id->toString(), $record->publicId?->toString(), $record->slug]) as $identifier) {
            $this->cache->forget($this->key($identifier));
        }
    }

    public function forgetIdentifier(string $identifier): void
    {
        $this->cache->forget($this->key($identifier));
    }

    private function key(string $identifier): string
    {
        return $this->prefix.hash('sha256', trim($identifier));
    }
}
