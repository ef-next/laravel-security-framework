<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Events\TenantContextActivated;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Resolution\Source;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->acme = Tenants::create('Acme', 'acme');
    $this->frozen = Tenants::create('Frozen', 'frozen', 'suspended');

    Route::middleware('tenant.resolve')->get('/whoami', fn () => [
        'tenant' => Tenancy::requireId()->toString(),
        'source' => Tenancy::require()->source->value,
        'public_id' => Tenancy::require()->publicId?->toString(),
    ]);

    Route::middleware('tenant.resolve:optional')->get('/maybe', fn () => ['has' => Tenancy::has()]);

    Route::middleware('tenant.resolve')->get('/t/{tenant}/x', fn () => ['tenant' => Tenancy::requireId()->toString()]);
});

it('resolves by header using the internal id', function (): void {
    Event::fake([TenantContextActivated::class]);

    $this->getJson('/whoami', ['X-Tenant-Id' => $this->acme->id])
        ->assertOk()
        ->assertJson(['tenant' => $this->acme->id, 'source' => 'header', 'public_id' => $this->acme->public_id]);

    Event::assertDispatched(TenantContextActivated::class, fn (TenantContextActivated $e): bool => $e->context->source === Source::Header);
});

it('resolves by public id, global object id and slug', function (): void {
    config()->set('addressing.service_name', 'svc');

    $this->getJson('/whoami', ['X-Tenant-Id' => $this->acme->public_id])->assertOk()->assertJsonPath('tenant', $this->acme->id);
    $this->getJson('/whoami', ['X-Tenant-Id' => 'svc:tenant:'.$this->acme->public_id])->assertOk()->assertJsonPath('tenant', $this->acme->id);
    $this->getJson('/whoami', ['X-Tenant-Id' => 'acme'])->assertOk()->assertJsonPath('tenant', $this->acme->id);
    $this->getJson('/whoami', ['X-Tenant-Id' => 'other:tenant:'.$this->acme->public_id])->assertNotFound();
});

it('resolves by route parameter', function (): void {
    $this->getJson('/t/acme/x')->assertOk()->assertJsonPath('tenant', $this->acme->id);
});

it('fails with 400 when no identifier is present and the tenant is required', function (): void {
    $this->getJson('/whoami')->assertStatus(400);
});

it('continues without a tenant in optional mode', function (): void {
    $this->getJson('/maybe')->assertOk()->assertJson(['has' => false]);
    $this->getJson('/maybe', ['X-Tenant-Id' => 'acme'])->assertOk()->assertJson(['has' => true]);
});

it('fails with 404 for unknown identifiers without echoing them', function (): void {
    $response = $this->getJson('/whoami', ['X-Tenant-Id' => 'nope']);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('nope');
});

it('fails with 403 for suspended tenants', function (): void {
    $this->getJson('/whoami', ['X-Tenant-Id' => 'frozen'])->assertForbidden();
});

it('ignores malformed identifiers instead of querying with them', function (string $identifier): void {
    $this->getJson('/whoami', ['X-Tenant-Id' => $identifier])->assertNotFound();
})->with([
    "acme' OR 1=1 --",
    'ACME',
    str_repeat('a', 300),
    '../../etc/passwd',
]);

it('clears the context once the request has been handled', function (): void {
    $this->getJson('/whoami', ['X-Tenant-Id' => 'acme'])->assertOk();

    expect(Tenancy::has())->toBeFalse();
});

it('does not carry a context from one request into the next without a header', function (): void {
    $this->getJson('/whoami', ['X-Tenant-Id' => 'acme'])->assertOk();
    $this->getJson('/whoami')->assertStatus(400);
});
