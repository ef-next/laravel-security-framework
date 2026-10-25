<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Efnext\Security\Tenant\Eloquent\Tenant;

final class Tenants
{
    public static function create(string $name, ?string $slug = null, string $status = 'active'): Tenant
    {
        return Tenant::query()->create([
            'name' => $name,
            'slug' => $slug ?? strtolower($name),
            'status' => $status,
        ]);
    }
}
