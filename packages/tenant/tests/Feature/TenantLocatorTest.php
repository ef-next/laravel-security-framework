<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Contracts\TenantLocator;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Locating\CachedTenantLocator;
use Efnext\Security\Tenant\Locating\TenantStatus;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();
    $this->acme = Tenants::create('Acme', 'acme');
});

it('locates by id, public id, global object id and slug with a cache in front', function (): void {
    config()->set('addressing.service_name', 'svc');
    $locator = app(TenantLocator::class);

    expect($locator)->toBeInstanceOf(CachedTenantLocator::class);

    foreach ([$this->acme->id, $this->acme->public_id, 'svc:tenant:'.$this->acme->public_id, 'acme'] as $identifier) {
        $record = $locator->locate($identifier);

        expect($record?->id->toString())->toBe($this->acme->id)
            ->and($record?->status)->toBe(TenantStatus::Active)
            ->and($record?->slug)->toBe('acme')
            ->and($record?->publicId?->toString())->toBe($this->acme->public_id);
    }

    expect($locator->locate('missing'))->toBeNull();
});

it('serves cached records and forgets them when the tenant changes', function (): void {
    $locator = app(TenantLocator::class);
    $locator->locate('acme');

    DB::enableQueryLog();
    $locator->locate('acme');
    expect(DB::getQueryLog())->toBe([]);

    $this->acme->update(['status' => 'suspended']);

    expect($locator->locate('acme')?->status)->toBe(TenantStatus::Suspended);
});

it('forgets the previous slug and public id when a tenant is renamed or deleted', function (): void {
    $locator = app(TenantLocator::class);
    $oldPublicId = $this->acme->public_id;

    expect($locator->locate('acme')?->slug)->toBe('acme');

    $this->acme->update(['slug' => 'acme-corp']);

    expect($locator->locate('acme'))->toBeNull()
        ->and($locator->locate('acme-corp')?->slug)->toBe('acme-corp')
        ->and($locator->locate($oldPublicId)?->slug)->toBe('acme-corp');

    $this->acme->delete();

    expect($locator->locate('acme-corp'))->toBeNull()
        ->and($locator->locate($oldPublicId))->toBeNull()
        ->and($locator->locate($this->acme->id))->toBeNull();
});

it('caches misses briefly', function (): void {
    $locator = app(TenantLocator::class);

    expect($locator->locate('ghost'))->toBeNull();

    DB::enableQueryLog();
    expect($locator->locate('ghost'))->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
});

it('never treats an unknown status as active', function (): void {
    DB::table('tenants')->where('id', $this->acme->id)->update(['status' => 'weird']);
    config()->set('tenant.cache.enabled', false);

    $record = app(TenantLocator::class)->locate('acme');

    expect($record?->status->isActive())->toBeFalse();
});
