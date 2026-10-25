<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Http\Middleware;

use Closure;
use Efnext\Security\Tenant\Events\TenantAccessDenied as TenantAccessDeniedEvent;
use Efnext\Security\Tenant\Exceptions\TenantAccessDenied;
use Efnext\Security\Tenant\Membership\DenialReason;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds Sanctum personal access tokens to tenants via "tenant:<tenant id>" abilities.
 *
 * - token with tenant abilities: must contain the active tenant, otherwise 403
 * - token without tenant abilities: allowed, unless tenant.tokens.require_binding is true
 * - session-based (transient) tokens: not affected
 *
 * Issue bound tokens with $user->createToken('ci', ['tenant:'.$tenantId]).
 */
final class EnsureTokenTenant
{
    public function __construct(
        private readonly TenantContextManager $manager,
        private readonly Dispatcher $events,
        private readonly bool $requireBinding = false,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'currentAccessToken')) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        // Transient tokens (cookie sessions) carry no ability list.
        if (! $token instanceof Model) {
            return $next($request);
        }

        $context = $this->manager->require();
        $actorId = (string) $user->getAuthIdentifier();
        $abilities = $token->getAttribute('abilities');
        $abilities = is_array($abilities) ? array_values(array_filter($abilities, 'is_string')) : [];

        $bound = array_values(array_filter($abilities, static fn (string $ability): bool => str_starts_with($ability, 'tenant:')));

        if ($bound === []) {
            if ($this->requireBinding) {
                $this->deny($actorId, DenialReason::TokenNotBound);
            }

            return $next($request);
        }

        $expected = 'tenant:'.$context->tenantId->toString();

        foreach ($bound as $ability) {
            if (hash_equals($expected, $ability)) {
                return $next($request);
            }
        }

        $this->deny($actorId, DenialReason::TokenTenantMismatch);
    }

    private function deny(string $actorId, DenialReason $reason): never
    {
        $this->events->dispatch(new TenantAccessDeniedEvent($this->manager->currentId(), $actorId, $reason));

        throw TenantAccessDenied::because($reason);
    }
}
