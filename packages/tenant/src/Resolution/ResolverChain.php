<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Resolution;

use Efnext\Security\Tenant\Contracts\TenantResolver;
use Illuminate\Http\Request;

/**
 * Runs resolvers in order; the first candidate wins.
 */
final class ResolverChain implements TenantResolver
{
    /** @var list<TenantResolver> */
    private readonly array $resolvers;

    public function __construct(TenantResolver ...$resolvers)
    {
        $this->resolvers = array_values($resolvers);
    }

    public function resolve(Request $request): ?Candidate
    {
        foreach ($this->resolvers as $resolver) {
            $candidate = $resolver->resolve($request);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public function describe(): string
    {
        return implode(', ', array_map(static fn (TenantResolver $r): string => $r->describe(), $this->resolvers));
    }

    /**
     * @return list<string>
     */
    public function descriptions(): array
    {
        return array_map(static fn (TenantResolver $r): string => $r->describe(), $this->resolvers);
    }
}
