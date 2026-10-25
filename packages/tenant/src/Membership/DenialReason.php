<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Membership;

enum DenialReason: string
{
    case Unauthenticated = 'unauthenticated';
    case NoMembership = 'no_membership';
    case MembershipInactive = 'membership_inactive';
    case MembershipExpired = 'membership_expired';
    case RoleInsufficient = 'role_insufficient';
    case TokenNotBound = 'token_not_bound';
    case TokenTenantMismatch = 'token_tenant_mismatch';

    public function message(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Authentication is required for tenant access.',
            self::NoMembership => 'You are not a member of this tenant.',
            self::MembershipInactive => 'Your membership in this tenant is not active.',
            self::MembershipExpired => 'Your membership in this tenant has expired.',
            self::RoleInsufficient => 'Your role in this tenant does not permit this action.',
            self::TokenNotBound => 'This API token is not bound to a tenant.',
            self::TokenTenantMismatch => 'This API token is not valid for this tenant.',
        };
    }
}
