<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Http;

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Contracts\MembershipChecker;
use Efnext\Security\Tenant\Events\TenantAccessDenied as TenantAccessDeniedEvent;
use Efnext\Security\Tenant\Events\TenantSwitched;
use Efnext\Security\Tenant\Exceptions\TenantAccessDenied;
use Efnext\Security\Tenant\Membership\DenialReason;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;

/**
 * Remembers the chosen tenant in the session for web flows ("tenant switcher").
 * The switch is only stored after a membership check and is evented.
 */
final class SessionTenant
{
    public function __construct(
        private readonly MembershipChecker $memberships,
        private readonly Container $container,
        private readonly string $sessionKey = 'efnext.tenant',
    ) {}

    public function switchTo(Request $request, TenantId $tenantId): void
    {
        $user = $request->user();
        $actorId = $user !== null ? trim((string) $user->getAuthIdentifier()) : '';

        if ($actorId === '') {
            $this->deny($tenantId, null, DenialReason::Unauthenticated);
        }

        $membership = $this->memberships->membershipFor($actorId, $tenantId);

        if ($membership === null) {
            $this->deny($tenantId, $actorId, DenialReason::NoMembership);
        }

        $reason = $membership->denialReason();

        if ($reason !== null) {
            $this->deny($tenantId, $actorId, $reason);
        }

        $previous = TenantId::tryFromString($request->session()->get($this->sessionKey));

        $request->session()->put($this->sessionKey, $tenantId->toString());

        $this->container->make(Dispatcher::class)->dispatch(new TenantSwitched($actorId, $previous, $tenantId));
    }

    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget($this->sessionKey);
        }
    }

    public function current(Request $request): ?TenantId
    {
        return $request->hasSession() ? TenantId::tryFromString($request->session()->get($this->sessionKey)) : null;
    }

    private function deny(TenantId $tenantId, ?string $actorId, DenialReason $reason): never
    {
        $this->container->make(Dispatcher::class)->dispatch(new TenantAccessDeniedEvent($tenantId, $actorId, $reason));

        throw TenantAccessDenied::because($reason);
    }
}
