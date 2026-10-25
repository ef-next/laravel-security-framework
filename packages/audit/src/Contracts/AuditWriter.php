<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Contracts;

use Efnext\Security\Audit\Eloquent\AuditEvent;
use Efnext\Security\Audit\Records\AuditEntry;
use Efnext\Security\Tenant\Context\TenantId;

interface AuditWriter
{
    /**
     * Append an entry to its chain. Participates in an open transaction of the
     * audit connection; otherwise runs in its own.
     */
    public function record(AuditEntry $entry): AuditEvent;

    /**
     * Create the chain's head row if it does not exist yet (null = global chain).
     * Optional: record() does this on demand. Call it outside a transaction when
     * a tenant is created if your application writes its first audit events
     * inside larger transactions under concurrency (see docs/en/operations.md).
     */
    public function prepareChain(?TenantId $tenantId): void;
}
