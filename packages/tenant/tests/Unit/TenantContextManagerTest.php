<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Context\TenantContext;
use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Events\TenantContextActivated;
use Efnext\Security\Tenant\Events\TenantScopeBypassed;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Resolution\Source;
use Efnext\Security\Tenant\TenantContextManager;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Tenancy::clear();
});

it('starts without a context and refuses to require one', function (): void {
    expect(Tenancy::has())->toBeFalse()
        ->and(Tenancy::current())->toBeNull()
        ->and(Tenancy::currentId())->toBeNull()
        ->and(Tenancy::isBypassed())->toBeFalse()
        ->and(fn () => Tenancy::require())->toThrow(TenantContextMissing::class)
        ->and(fn () => Tenancy::requireId())->toThrow(TenantContextMissing::class);
});

it('activates a context and fires an event', function (): void {
    Event::fake([TenantContextActivated::class]);

    Tenancy::activate(TenantContext::for('tenant-a', Source::Header));

    expect(Tenancy::has())->toBeTrue()
        ->and(Tenancy::requireId()->toString())->toBe('tenant-a')
        ->and(Tenancy::is('tenant-a'))->toBeTrue()
        ->and(Tenancy::is('tenant-b'))->toBeFalse()
        ->and(Tenancy::current()?->source)->toBe(Source::Header);

    Event::assertDispatched(TenantContextActivated::class, fn (TenantContextActivated $e): bool => $e->context->is('tenant-a'));
});

it('runs a callback for another tenant and restores the previous state', function (): void {
    Tenancy::activate(TenantContext::for('tenant-a'));

    $inside = Tenancy::run('tenant-b', fn (TenantContext $context): string => $context->tenantId->toString().'/'.Tenancy::requireId()->toString());

    expect($inside)->toBe('tenant-b/tenant-b')
        ->and(Tenancy::requireId()->toString())->toBe('tenant-a');
});

it('restores the previous state even when the callback throws', function (): void {
    Tenancy::activate(TenantContext::for('tenant-a'));

    try {
        Tenancy::run('tenant-b', function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(Tenancy::requireId()->toString())->toBe('tenant-a');
});

it('lifts an active bypass inside run()', function (): void {
    // A bypass without a context is written to the global audit chain; this unit test has no database.
    Event::fake([TenantScopeBypassed::class]);

    Tenancy::withoutTenant('test', function (): void {
        expect(Tenancy::isBypassed())->toBeTrue();

        Tenancy::run('tenant-b', function (): void {
            expect(Tenancy::isBypassed())->toBeFalse();
        });

        expect(Tenancy::isBypassed())->toBeTrue();
    });

    expect(Tenancy::isBypassed())->toBeFalse();
});

it('requires a reason for bypassing and fires an auditable event', function (): void {
    Event::fake([TenantScopeBypassed::class]);
    Tenancy::activate(TenantContext::for('tenant-a'));

    $result = Tenancy::withoutTenant('nightly cross-tenant report', fn (): bool => Tenancy::isBypassed());

    expect($result)->toBeTrue()
        ->and(Tenancy::isBypassed())->toBeFalse()
        ->and(Tenancy::requireId()->toString())->toBe('tenant-a')
        ->and(fn () => Tenancy::withoutTenant('  ', fn () => null))->toThrow(InvalidArgumentException::class);

    Event::assertDispatched(TenantScopeBypassed::class, function (TenantScopeBypassed $e): bool {
        return $e->reason === 'nightly cross-tenant report'
            && $e->context?->is('tenant-a') === true
            && str_contains($e->caller, 'TenantContextManagerTest.php');
    });
});

it('suspends and resumes state around a job, idempotently', function (): void {
    /** @var TenantContextManager $manager */
    $manager = app(TenantContextManager::class);
    $manager->activate(TenantContext::for('request-tenant'));

    $manager->suspendFor('job-1', TenantContext::for('job-tenant', Source::Queue));
    expect($manager->requireId()->toString())->toBe('job-tenant');

    $manager->suspendFor('job-2', null);
    expect($manager->has())->toBeFalse();

    $manager->resumeAfter('job-1'); // wrong key: ignored
    expect($manager->has())->toBeFalse();

    $manager->resumeAfter('job-2');
    expect($manager->requireId()->toString())->toBe('job-tenant');

    $manager->resumeAfter('job-1');
    $manager->resumeAfter('job-1'); // second time: no-op
    expect($manager->requireId()->toString())->toBe('request-tenant');
});

it('clears context and bypass together', function (): void {
    Tenancy::activate(TenantContext::for('tenant-a'));

    Tenancy::clear();

    expect(Tenancy::has())->toBeFalse()->and(Tenancy::isBypassed())->toBeFalse();
});

it('builds contexts from ids with a manual source by default', function (): void {
    $context = TenantContext::for(TenantId::fromString('tenant-a'));

    expect($context->source)->toBe(Source::Manual)
        ->and($context->resolvedAt)->not->toBeNull()
        ->and($context->toArray()['tenant_id'])->toBe('tenant-a');
});
