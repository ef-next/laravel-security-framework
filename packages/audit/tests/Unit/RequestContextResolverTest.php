<?php

declare(strict_types=1);

use Efnext\Security\Audit\Writing\RequestContextResolver;
use Illuminate\Container\Container;
use Illuminate\Http\Request;

/*
 * The request context ends up in hashed columns, so its bounds are part of the
 * contract: control characters are stripped, values are trimmed, blank values
 * become null and every field is cut at its column width.
 */

function requestResolver(?Request $request, bool $enabled = true): RequestContextResolver
{
    $container = new Container;

    if ($request !== null) {
        $container->instance('request', $request);
    }

    return new RequestContextResolver($container, $enabled, 'X-Request-Id', 'X-Correlation-Id');
}

it('returns nulls without a request, when disabled, and for the console placeholder request', function (): void {
    $placeholder = Request::create('/');
    $console = requestResolver($placeholder);
    $console->rememberConsoleRequest($placeholder);

    $empty = ['request_id' => null, 'correlation_id' => null, 'ip_address' => null, 'user_agent' => null];

    expect(requestResolver(null)->resolve())->toBe($empty)
        ->and(requestResolver(Request::create('/', 'GET', server: ['HTTP_X_REQUEST_ID' => 'r']), enabled: false)->resolve())->toBe($empty)
        ->and($console->resolve())->toBe($empty)
        ->and($console->currentRequest())->toBeNull();
});

it('cleans and bounds every field at its column width', function (): void {
    $request = Request::create('/', 'POST', server: [
        'HTTP_X_REQUEST_ID' => '  '.str_repeat('r', 64)."X\t",
        'HTTP_X_CORRELATION_ID' => "\x00 \x1F\x7F ",
        'HTTP_USER_AGENT' => "agent\r\n".str_repeat('u', 260),
        'REMOTE_ADDR' => '203.0.113.7',
    ]);

    $context = requestResolver($request)->resolve();

    expect($context['request_id'])->toBe(str_repeat('r', 64))
        ->and($context['correlation_id'])->toBeNull()
        ->and($context['user_agent'])->toBe('agent'.str_repeat('u', 250))
        ->and(strlen($context['user_agent']))->toBe(255)
        ->and($context['ip_address'])->toBe('203.0.113.7');

    // Exactly at the limit nothing is cut; multibyte characters count as one.
    $exact = Request::create('/', 'GET', server: ['HTTP_X_REQUEST_ID' => str_repeat('ä', 64), 'HTTP_X_CORRELATION_ID' => str_repeat('c', 65)]);
    $bounded = requestResolver($exact)->resolve();

    expect($bounded['request_id'])->toBe(str_repeat('ä', 64))
        ->and($bounded['correlation_id'])->toBe(str_repeat('c', 64));
});
