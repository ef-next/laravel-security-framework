<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Contracts\TenantResolver;
use Efnext\Security\Tenant\Resolution\Candidate;
use Efnext\Security\Tenant\Resolution\HeaderResolver;
use Efnext\Security\Tenant\Resolution\ResolverChain;
use Efnext\Security\Tenant\Resolution\RouteParameterResolver;
use Efnext\Security\Tenant\Resolution\SessionResolver;
use Efnext\Security\Tenant\Resolution\Source;
use Efnext\Security\Tenant\Resolution\SubdomainResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

it('reads the first configured header and trims it', function (): void {
    $resolver = new HeaderResolver(['X-Tenant-Id', 'X-Tenant']);

    $request = Request::create('/', 'GET', server: ['HTTP_X_TENANT' => '  ten_abcdefgh12345678 ']);

    $candidate = $resolver->resolve($request);

    expect($candidate?->identifier)->toBe('ten_abcdefgh12345678')
        ->and($candidate?->source)->toBe(Source::Header)
        ->and($candidate?->detail)->toBe('X-Tenant')
        ->and($resolver->resolve(Request::create('/')))->toBeNull()
        ->and($resolver->resolve(Request::create('/', server: ['HTTP_X_TENANT_ID' => '   '])))->toBeNull();
});

it('reads route parameters, including bound models', function (): void {
    $resolver = new RouteParameterResolver(['tenant']);

    $route = new Route('GET', '/t/{tenant}/x', fn () => null);
    $route->bind(Request::create('/t/acme/x'));
    $request = Request::create('/t/acme/x');
    $request->setRouteResolver(fn () => $route);

    expect($resolver->resolve($request)?->identifier)->toBe('acme')
        ->and($resolver->resolve($request)?->source)->toBe(Source::Route)
        ->and($resolver->resolve(Request::create('/')))->toBeNull();
});

it('reads the session key when a session is present', function (): void {
    $resolver = new SessionResolver('efnext.tenant');
    $request = Request::create('/');

    expect($resolver->resolve($request))->toBeNull();

    $session = app('session')->driver('array');
    $session->start();
    $session->put('efnext.tenant', 'tenant-a');
    $request->setLaravelSession($session);

    expect($resolver->resolve($request)?->identifier)->toBe('tenant-a')
        ->and($resolver->resolve($request)?->source)->toBe(Source::Session);
});

it('reads a single label left of a base domain', function (): void {
    $resolver = new SubdomainResolver(['app.example.test']);

    expect($resolver->resolve(Request::create('https://acme.app.example.test/'))?->identifier)->toBe('acme')
        ->and($resolver->resolve(Request::create('https://ACME.app.example.test/'))?->identifier)->toBe('acme')
        ->and($resolver->resolve(Request::create('https://app.example.test/')))->toBeNull()
        ->and($resolver->resolve(Request::create('https://deep.acme.app.example.test/')))->toBeNull()
        ->and($resolver->resolve(Request::create('https://acme.other.test/')))->toBeNull()
        ->and($resolver->resolve(Request::create('https://evil-app.example.test/')))->toBeNull();
});

it('stops at the first resolver that finds something', function (): void {
    $never = new class implements TenantResolver
    {
        public function resolve(Request $request): ?Candidate
        {
            return null;
        }

        public function describe(): string
        {
            return 'never';
        }
    };

    $chain = new ResolverChain($never, new HeaderResolver(['X-Tenant-Id']), new SessionResolver);
    $request = Request::create('/', server: ['HTTP_X_TENANT_ID' => 'from-header']);

    expect($chain->resolve($request)?->identifier)->toBe('from-header')
        ->and($chain->resolve(Request::create('/')))->toBeNull()
        ->and($chain->descriptions())->toBe(['never', 'header X-Tenant-Id', 'session key efnext.tenant']);
});
