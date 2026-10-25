<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Queue;

use Efnext\Security\Tenant\Context\TenantContext;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Resolution\Source;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Carries the active tenant through the queue.
 *
 * At dispatch time the tenant id is embedded in the job payload; when the
 * worker picks the job up the same tenant is activated before the job runs and
 * the previous state is restored afterwards (this matters for the sync driver,
 * where the surrounding request continues after the job).
 *
 * Jobs dispatched without a tenant run without one and hit the fail-closed
 * scopes as soon as they touch tenant-owned data, which is the intended
 * outcome: make the tenant explicit (TenantAware) or dispatch from a context.
 */
final class PropagatesTenantContext
{
    public const string PAYLOAD_KEY = 'efnext_tenant';

    public function __construct(
        private readonly TenantContextManager $manager,
    ) {}

    /**
     * Queue::createPayloadUsing() callback.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $context = $this->manager->current();

        if ($context === null || $this->manager->isBypassed()) {
            return [];
        }

        return [self::PAYLOAD_KEY => ['id' => $context->tenantId->toString()]];
    }

    public function onJobProcessing(JobProcessing $event): void
    {
        $this->manager->suspendFor(self::key($event->job), self::contextFromPayload($event->job));
    }

    public function onJobFinished(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        $this->manager->resumeAfter(self::key($event->job));
    }

    private static function contextFromPayload(Job $job): ?TenantContext
    {
        $payload = $job->payload();
        $id = TenantId::tryFromString($payload[self::PAYLOAD_KEY]['id'] ?? null);

        return $id === null ? null : TenantContext::for($id, Source::Queue);
    }

    private static function key(Job $job): string
    {
        $uuid = $job->uuid();

        return is_string($uuid) && $uuid !== '' ? $uuid : spl_object_hash($job);
    }
}
