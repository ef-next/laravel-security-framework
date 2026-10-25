<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Resolution;

use Efnext\Security\Tenant\Contracts\TenantResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final class RouteParameterResolver implements TenantResolver
{
    /**
     * @param  list<string>  $parameters
     */
    public function __construct(
        private readonly array $parameters = ['tenant'],
    ) {}

    public function resolve(Request $request): ?Candidate
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        foreach ($this->parameters as $parameter) {
            $value = $route->parameter($parameter);

            // Implicit binding may already have resolved the parameter to a model.
            if ($value instanceof Model) {
                $value = $value->getKey();
            }

            if (is_int($value)) {
                $value = (string) $value;
            }

            if (is_string($value) && trim($value) !== '') {
                return new Candidate(trim($value), Source::Route, $parameter);
            }
        }

        return null;
    }

    public function describe(): string
    {
        return 'route parameter {'.implode('}/{', $this->parameters).'}';
    }
}
