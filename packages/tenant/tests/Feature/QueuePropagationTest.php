<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Queue\TenantAware;
use Efnext\Security\Tenant\Queue\TenantContextMiddleware;
use Efnext\Security\Tenant\Tests\Fixtures\Invoice;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class CountInvoicesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?int $seen = null;

    public static ?string $tenant = null;

    public function handle(): void
    {
        self::$tenant = Tenancy::currentId()?->toString();
        self::$seen = Invoice::query()->count();
    }
}

final class ExplicitTenantJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public static ?string $tenant = null;

    public function __construct(private readonly string $tenantId) {}

    public function tenantId(): TenantId
    {
        return TenantId::fromString($this->tenantId);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantContextMiddleware];
    }

    public function handle(): void
    {
        self::$tenant = Tenancy::currentId()?->toString();
    }
}

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();
    CountInvoicesJob::$seen = null;
    CountInvoicesJob::$tenant = null;
    ExplicitTenantJob::$tenant = null;

    $this->acme = Tenants::create('Acme', 'acme');
    $this->beta = Tenants::create('Beta', 'beta');

    Tenancy::run($this->acme->id, fn () => Invoice::query()->create(['number' => 'A-1']));
    Tenancy::run($this->beta->id, function (): void {
        Invoice::query()->create(['number' => 'B-1']);
        Invoice::query()->create(['number' => 'B-2']);
    });
});

it('propagates the dispatching tenant into the job and restores the caller afterwards', function (): void {
    Tenancy::run($this->beta->id, function (): void {
        dispatch_sync(new CountInvoicesJob);

        expect(CountInvoicesJob::$tenant)->toBe($this->beta->id)
            ->and(CountInvoicesJob::$seen)->toBe(2)
            ->and(Tenancy::requireId()->toString())->toBe($this->beta->id);
    });

    expect(Tenancy::has())->toBeFalse();
});

it('lets a job without tenant fail closed', function (): void {
    expect(fn () => dispatch_sync(new CountInvoicesJob))->toThrow(TenantContextMissing::class)
        ->and(CountInvoicesJob::$seen)->toBeNull()
        ->and(Tenancy::has())->toBeFalse();
});

it('does not propagate a bypass into jobs', function (): void {
    Tenancy::withoutTenant('bulk', function (): void {
        expect(fn () => dispatch_sync(new CountInvoicesJob))->toThrow(TenantContextMissing::class);
    });
});

it('lets TenantAware jobs choose their tenant explicitly', function (): void {
    Tenancy::run($this->acme->id, function (): void {
        dispatch_sync(new ExplicitTenantJob($this->beta->id));

        expect(ExplicitTenantJob::$tenant)->toBe($this->beta->id)
            ->and(Tenancy::requireId()->toString())->toBe($this->acme->id);
    });

    dispatch_sync(new ExplicitTenantJob($this->acme->id));
    expect(ExplicitTenantJob::$tenant)->toBe($this->acme->id)->and(Tenancy::has())->toBeFalse();
});
