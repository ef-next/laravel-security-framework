<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Tests\Fixtures\Invoice;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\ClosureCommand;

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->acme = Tenants::create('Acme', 'acme');
    $this->frozen = Tenants::create('Frozen', 'frozen', 'suspended');

    Tenancy::run($this->acme->id, fn () => Invoice::query()->create(['number' => 'A-1']));

    // Artisan::command() only registers a "starting" callback, which has already
    // run by the time setUp() migrated; add the command to the live instance.
    app(Kernel::class)->registerCommand(new ClosureCommand('test:count-invoices', function (): int {
        $this->line('count='.Invoice::query()->count().' tenant='.Tenancy::requireId()->toString());

        return 0;
    }));
});

it('runs a command inside a tenant context', function (): void {
    $this->artisan('tenant:run', ['tenant' => 'acme', 'artisan' => 'test:count-invoices'])
        ->expectsOutputToContain('count=1 tenant='.$this->acme->id)
        ->assertSuccessful();

    expect(Tenancy::has())->toBeFalse();
});

it('fails for unknown or inactive tenants unless allowed', function (): void {
    $this->artisan('tenant:run', ['tenant' => 'nope', 'artisan' => 'test:count-invoices'])->assertFailed();
    $this->artisan('tenant:run', ['tenant' => 'frozen', 'artisan' => 'test:count-invoices'])->assertFailed();
    $this->artisan('tenant:run', ['tenant' => 'frozen', 'artisan' => 'test:count-invoices', '--allow-inactive' => true])->assertSuccessful();
});

it('lets the wrapped command fail closed when it is run without tenant:run', function (): void {
    expect(fn () => $this->artisan('test:count-invoices')->run())->toThrow(Efnext\Security\Tenant\Exceptions\TenantContextMissing::class);
});
