<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Resolution;

use Efnext\Security\Tenant\Contracts\TenantResolver;
use Illuminate\Http\Request;

final class SessionResolver implements TenantResolver
{
    public function __construct(
        private readonly string $key = 'efnext.tenant',
    ) {}

    public function resolve(Request $request): ?Candidate
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get($this->key);

        if (is_string($value) && trim($value) !== '') {
            return new Candidate(trim($value), Source::Session, $this->key);
        }

        return null;
    }

    public function describe(): string
    {
        return 'session key '.$this->key;
    }
}
