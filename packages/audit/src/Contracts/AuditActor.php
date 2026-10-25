<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Contracts;

use Efnext\Security\Audit\Records\Actor;

/**
 * Implement on your user (or service account) model to control how it is
 * recorded, e.g. to add a stable label or mark it as a service identity.
 */
interface AuditActor
{
    public function toAuditActor(): Actor;
}
