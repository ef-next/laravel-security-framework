<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Enums;

enum ActorType: string
{
    /** An authenticated end user. */
    case User = 'user';

    /** A machine identity: another service, a CI token, a webhook sender. */
    case Service = 'service';

    /** The platform itself: scheduler, queue worker, console command. */
    case System = 'system';

    /** An unauthenticated request (e.g. a failed login). */
    case Anonymous = 'anonymous';
}
