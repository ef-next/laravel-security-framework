<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant;

use Closure;
use Efnext\Security\Tenant\Context\TenantContext;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Events\TenantContextActivated;
use Efnext\Security\Tenant\Events\TenantScopeBypassed;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Resolution\Source;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;

/**
 * Holds the active tenant context for the current unit of work
 * (HTTP request, queued job, console command, or a Tenancy::run() block).
 *
 * Fail-closed by design: consumers call require() or check isBypassed();
 * nothing in the framework falls back to "no tenant means all tenants".
 */
final class TenantContextManager
{
    private ?TenantContext $context = null;

    private int $bypassDepth = 0;

    /** @var list<array{key: string, context: ?TenantContext, bypassDepth: int}> */
    private array $suspended = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function current(): ?TenantContext
    {
        return $this->context;
    }

    public function currentId(): ?TenantId
    {
        return $this->context?->tenantId;
    }

    public function has(): bool
    {
        return $this->context !== null;
    }

    public function require(): TenantContext
    {
        return $this->context ?? throw TenantContextMissing::required();
    }

    public function requireId(): TenantId
    {
        return $this->require()->tenantId;
    }

    public function is(TenantId|string $tenantId): bool
    {
        return $this->context?->is($tenantId) ?? false;
    }

    /**
     * Activate a context for the rest of the current unit of work.
     */
    public function activate(TenantContext $context): void
    {
        $this->context = $context;

        $this->events()->dispatch(new TenantContextActivated($context));
    }

    public function clear(): void
    {
        $this->context = null;
        $this->bypassDepth = 0;
    }

    /**
     * Run a callback for a specific tenant, restoring the previous state afterwards.
     * Any active bypass is lifted inside the block.
     *
     * @template TReturn
     *
     * @param  Closure(TenantContext): TReturn  $callback
     * @return TReturn
     */
    public function run(TenantContext|TenantId|string $tenant, Closure $callback, Source $source = Source::Manual): mixed
    {
        $context = $tenant instanceof TenantContext ? $tenant : TenantContext::for($tenant, $source);

        $previousContext = $this->context;
        $previousBypass = $this->bypassDepth;

        $this->context = $context;
        $this->bypassDepth = 0;
        $this->events()->dispatch(new TenantContextActivated($context));

        try {
            return $callback($context);
        } finally {
            $this->context = $previousContext;
            $this->bypassDepth = $previousBypass;
        }
    }

    /**
     * Run a deliberate cross-tenant operation. Inside the block, tenant scopes
     * do not filter and creates require an explicit tenant column.
     *
     * The reason is mandatory and ends up in the TenantScopeBypassed event so it
     * can be audited. Keep bypass blocks small.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutTenant(string $reason, Closure $callback): mixed
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Tenancy::withoutTenant() requires a non-empty reason.');
        }

        $this->events()->dispatch(new TenantScopeBypassed($reason, $this->context, self::caller()));
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /**
     * Suspend the current state while a queued job runs (also on the sync driver,
     * where the surrounding request must get its context back afterwards).
     */
    public function suspendFor(string $key, ?TenantContext $replacement): void
    {
        $this->suspended[] = ['key' => $key, 'context' => $this->context, 'bypassDepth' => $this->bypassDepth];

        $this->context = $replacement;
        $this->bypassDepth = 0;

        if ($replacement !== null) {
            $this->events()->dispatch(new TenantContextActivated($replacement));
        }
    }

    /**
     * Restore the state suspended under the given key. Idempotent: a second call
     * for the same key (e.g. JobExceptionOccurred followed by JobFailed) is a no-op.
     */
    public function resumeAfter(string $key): void
    {
        $top = end($this->suspended);

        if ($top === false || $top['key'] !== $key) {
            return;
        }

        array_pop($this->suspended);

        $this->context = $top['context'];
        $this->bypassDepth = $top['bypassDepth'];
    }

    /**
     * Resolved on every dispatch so that swapped dispatchers (Event::fake()) are honoured.
     */
    private function events(): Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }

    /**
     * The first call site outside this class and the facade layer, e.g. "ReportJob.php:42".
     */
    private static function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10) as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || $file === __FILE__ || str_contains($file, DIRECTORY_SEPARATOR.'Facades'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            return basename($file).':'.($frame['line'] ?? 0);
        }

        return 'unknown';
    }
}
