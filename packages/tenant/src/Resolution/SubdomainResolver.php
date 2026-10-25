<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Resolution;

use Efnext\Security\Tenant\Contracts\TenantResolver;
use Illuminate\Http\Request;

/**
 * Reads the label left of a configured base domain: "acme.app.example.com" -> "acme".
 * Only single labels are accepted; nested subdomains are ignored.
 */
final class SubdomainResolver implements TenantResolver
{
    /**
     * @param  list<string>  $baseDomains
     */
    public function __construct(
        private readonly array $baseDomains,
    ) {}

    public function resolve(Request $request): ?Candidate
    {
        $host = strtolower($request->getHost());

        foreach ($this->baseDomains as $base) {
            $base = strtolower(trim($base, '. '));

            if ($base === '' || ! str_ends_with($host, '.'.$base)) {
                continue;
            }

            $label = substr($host, 0, -strlen($base) - 1);

            if ($label !== '' && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/D', $label) === 1) {
                return new Candidate($label, Source::Subdomain, $base);
            }
        }

        return null;
    }

    public function describe(): string
    {
        return 'subdomain of '.implode('/', $this->baseDomains);
    }
}
