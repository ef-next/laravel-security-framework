<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Writing;

use Efnext\Security\Audit\Contracts\ActorResolver;
use Efnext\Security\Audit\Contracts\AuditActor;
use Efnext\Security\Audit\Records\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Throwable;

/**
 * HTTP request with a user     -> user:<id>
 * HTTP request without a user  -> anonymous
 * console / queue              -> system:console
 *
 * Models implementing AuditActor decide their own representation.
 */
final class RequestActorResolver implements ActorResolver
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly RequestContextResolver $requests,
    ) {}

    public function resolve(): Actor
    {
        $user = $this->authenticatedUser();

        if ($user instanceof AuditActor) {
            return $user->toAuditActor();
        }

        if ($user !== null) {
            $id = $user->getAuthIdentifier();

            return Actor::user(is_scalar($id) ? (string) $id : 'unknown');
        }

        if ($this->requests->currentRequest() !== null) {
            return Actor::anonymous();
        }

        return Actor::system('console');
    }

    private function authenticatedUser(): ?Authenticatable
    {
        try {
            $user = $this->auth->guard()->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof Authenticatable ? $user : null;
    }
}
