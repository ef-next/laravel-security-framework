<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Resolution;

use Efnext\Security\Tenant\Contracts\TenantResolver;
use Illuminate\Http\Request;

final class HeaderResolver implements TenantResolver
{
    /**
     * @param  list<string>  $headers
     */
    public function __construct(
        private readonly array $headers = ['X-Tenant-Id'],
    ) {}

    public function resolve(Request $request): ?Candidate
    {
        foreach ($this->headers as $header) {
            $value = $request->headers->get($header);

            if (is_string($value) && trim($value) !== '') {
                return new Candidate(trim($value), Source::Header, $header);
            }
        }

        return null;
    }

    public function describe(): string
    {
        return 'header '.implode('/', $this->headers);
    }
}
