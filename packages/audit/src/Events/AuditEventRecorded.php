<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Events;

use Efnext\Security\Audit\Eloquent\AuditEvent;

final readonly class AuditEventRecorded
{
    public function __construct(
        public AuditEvent $event,
    ) {}
}
