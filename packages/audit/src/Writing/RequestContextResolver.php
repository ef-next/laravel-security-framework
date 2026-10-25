<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Writing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use WeakReference;

/**
 * Pulls request id, correlation id, client ip and user agent from the current
 * HTTP request, if there is one. Values are length-bounded; all of them end up
 * in hashed columns.
 */
final class RequestContextResolver
{
    /** @var WeakReference<Request>|null */
    private ?WeakReference $consoleRequest = null;

    public function __construct(
        private readonly Container $container,
        private readonly bool $enabled = true,
        private readonly string $requestIdHeader = 'X-Request-Id',
        private readonly string $correlationIdHeader = 'X-Correlation-Id',
    ) {}

    /**
     * @return array{request_id: ?string, correlation_id: ?string, ip_address: ?string, user_agent: ?string}
     */
    public function resolve(): array
    {
        $request = $this->currentRequest();

        if ($request === null) {
            return ['request_id' => null, 'correlation_id' => null, 'ip_address' => null, 'user_agent' => null];
        }

        return [
            'request_id' => self::bounded($request->headers->get($this->requestIdHeader), 64),
            'correlation_id' => self::bounded($request->headers->get($this->correlationIdHeader), 64),
            'ip_address' => self::bounded($request->ip(), 45),
            'user_agent' => self::bounded($request->userAgent(), 255),
        ];
    }

    /**
     * Remember the placeholder request Laravel binds while booting in the
     * console, so it is never mistaken for a real HTTP request (queue workers,
     * scheduler, Octane between requests). Called by the service provider.
     */
    public function rememberConsoleRequest(Request $request): void
    {
        $this->consoleRequest = WeakReference::create($request);
    }

    /**
     * The current HTTP request, or null in console and queue context.
     */
    public function currentRequest(): ?Request
    {
        if (! $this->enabled || ! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        if (! $request instanceof Request || $request === $this->consoleRequest?->get()) {
            return null;
        }

        return $request;
    }

    private static function bounded(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
