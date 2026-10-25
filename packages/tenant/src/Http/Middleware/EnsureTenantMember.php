<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Http\Middleware;

use Closure;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Contracts\MembershipChecker;
use Efnext\Security\Tenant\Events\TenantAccessDenied as TenantAccessDeniedEvent;
use Efnext\Security\Tenant\Exceptions\TenantAccessDenied;
use Efnext\Security\Tenant\Membership\DenialReason;
use Efnext\Security\Tenant\Membership\Roles;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an active, unexpired membership of the authenticated user in the
 * active tenant, optionally with one of the given roles.
 *
 *   'tenant.member'              any active membership
 *   'tenant.role:owner,admin'    membership with owner or admin (or higher, if hierarchical)
 *
 * Must run after authentication and after ResolveTenant. Every denial fires
 * TenantAccessDenied so it can be audited.
 */
final class EnsureTenantMember
{
    public function __construct(
        private readonly TenantContextManager $manager,
        private readonly MembershipChecker $memberships,
        private readonly Roles $roles,
        private readonly Dispatcher $events,
    ) {}

    public function handle(Request $request, Closure $next, string $roles = ''): Response
    {
        $context = $this->manager->require();
        $user = $request->user();

        if ($user === null) {
            $this->deny($context->tenantId, null, DenialReason::Unauthenticated);
        }

        $actorId = trim((string) $user->getAuthIdentifier());

        if ($actorId === '') {
            $this->deny($context->tenantId, null, DenialReason::Unauthenticated);
        }

        $membership = $this->memberships->membershipFor($actorId, $context->tenantId);

        if ($membership === null) {
            $this->deny($context->tenantId, $actorId, DenialReason::NoMembership);
        }

        $reason = $membership->denialReason();

        if ($reason !== null) {
            $this->deny($context->tenantId, $actorId, $reason);
        }

        $required = Roles::parse($roles);

        if ($required !== [] && ! $this->roles->satisfies($membership->role, $required)) {
            $this->deny($context->tenantId, $actorId, DenialReason::RoleInsufficient, $required);
        }

        return $next($request);
    }

    /**
     * @param  list<string>  $requiredRoles
     */
    private function deny(TenantId $tenantId, ?string $actorId, DenialReason $reason, array $requiredRoles = []): never
    {
        $this->events->dispatch(new TenantAccessDeniedEvent($tenantId, $actorId, $reason, $requiredRoles));

        if ($reason === DenialReason::Unauthenticated) {
            throw new AuthenticationException($reason->message());
        }

        throw TenantAccessDenied::because($reason);
    }
}
