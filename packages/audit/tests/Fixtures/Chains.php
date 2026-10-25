<?php

declare(strict_types=1);

namespace Efnext\Security\Audit\Tests\Fixtures;

use Efnext\Security\Audit\Eloquent\AuditEvent;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Eloquent\Tenant;
use Efnext\Security\Tenant\Facades\Tenancy;

final class Chains
{
    public const string TENANT_A = '9c5f3e2a-7d1b-4e8f-a2c6-0b1d2e3f4a5b';

    public const string TENANT_B = '0f8e7d6c-5b4a-4392-8f1e-2d3c4b5a6978';

    public static function tenant(string $id, string $slug = 'acme'): Tenant
    {
        $tenant = new Tenant(['name' => ucfirst($slug), 'slug' => $slug]);
        $tenant->id = $id;
        $tenant->save();

        return $tenant;
    }

    /**
     * @return list<AuditEvent>
     */
    public static function write(string $tenantId, int $count, string $prefix = 'dom'): array
    {
        return Tenancy::run(TenantId::fromString($tenantId), function () use ($count, $prefix): array {
            $events = [];

            for ($i = 1; $i <= $count; $i++) {
                $events[] = Audit::action('domain.updated')
                    ->on('domain', sprintf('%s_%016d', $prefix, $i))
                    ->with(['step' => $i, 'note' => 'event '.$i])
                    ->record();
            }

            return $events;
        });
    }
}
