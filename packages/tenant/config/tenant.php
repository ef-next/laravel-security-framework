<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Eloquent\Tenant;
use Efnext\Security\Tenant\Eloquent\TenantMembership;

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant source of truth
    |--------------------------------------------------------------------------
    */

    'model' => Tenant::class,
    'table' => 'tenants',
    'connection' => env('TENANT_DB_CONNECTION'),

    // Column on tenant-owned tables. Override per model with a $tenantColumn property.
    'column' => 'tenant_id',

    /*
    |--------------------------------------------------------------------------
    | Resolution: where the tenant identifier is read from
    |--------------------------------------------------------------------------
    |
    | Resolvers run in the order listed; the first one that finds an identifier
    | wins. The identifier is then passed to the locator (see "lookup") which
    | must find an existing tenant, otherwise the request fails with 404.
    |
    | Available: header, route, session, subdomain, or a class implementing
    | Efnext\Security\Tenant\Contracts\TenantResolver.
    |
    */

    'resolution' => [
        'chain' => ['header', 'route', 'session'],
        'header' => ['X-Tenant-Id'],
        'route_parameters' => ['tenant'],
        'session_key' => 'efnext.tenant',
        'subdomain' => [
            // e.g. ['example.test', 'app.example.com']; the label left of the base domain is the identifier.
            'base_domains' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lookup: how an identifier is mapped to a tenant record
    |--------------------------------------------------------------------------
    |
    | Order matters. "id" accepts UUIDs, "public_id" accepts ten_..., "global_object_id"
    | accepts <service>:tenant:ten_... for this service, "slug" accepts the slug column.
    |
    */

    'lookup' => [
        'by' => ['id', 'public_id', 'global_object_id', 'slug'],
        'slug_column' => 'slug',
        'status_column' => 'status',
    ],

    'cache' => [
        'enabled' => env('TENANT_CACHE_ENABLED', true),
        'store' => env('TENANT_CACHE_STORE'),
        'ttl' => (int) env('TENANT_CACHE_TTL', 60),
        // Unknown identifiers are cached briefly to blunt enumeration load.
        'negative_ttl' => (int) env('TENANT_CACHE_NEGATIVE_TTL', 5),
        'prefix' => 'efnext:tenant:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Memberships and roles
    |--------------------------------------------------------------------------
    */

    'memberships' => [
        'model' => TenantMembership::class,
        'table' => 'tenant_memberships',
        // Column type of your users' primary key: "uuid", "ulid", "unsignedBigInteger" or "string".
        'user_key_type' => env('TENANT_USER_KEY_TYPE', 'unsignedBigInteger'),
        // Highest first. With "hierarchical" => true, a required role is satisfied by any higher role.
        'roles' => ['owner', 'admin', 'member', 'viewer'],
        'hierarchical' => true,
        'default_role' => 'member',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | When enabled, the active tenant is embedded in every queued job payload
    | and restored before the job runs. Jobs implementing TenantAware may
    | override the tenant explicitly.
    |
    */

    'queue' => [
        'propagate' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | API tokens (Laravel Sanctum, optional)
    |--------------------------------------------------------------------------
    |
    | EnsureTokenTenant enforces "tenant:<id>" abilities. With require_binding
    | a token without any tenant ability is rejected on tenant routes.
    |
    */

    'tokens' => [
        'require_binding' => env('TENANT_TOKENS_REQUIRE_BINDING', false),
    ],

];
