<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Eloquent\TenantScope;
use Efnext\Security\Tenant\Exceptions\TenantColumnImmutable;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Exceptions\TenantMismatch;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Tests\Fixtures\Invoice;
use Efnext\Security\Tenant\Tests\Fixtures\InvoiceLine;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->a = Tenants::create('Acme', 'acme');
    $this->b = Tenants::create('Beta', 'beta');

    Tenancy::run($this->a->id, function (): void {
        $invoice = Invoice::query()->create(['number' => 'A-1']);
        $invoice->lines()->create(['description' => 'A-1 line']);
        Invoice::query()->create(['number' => 'A-2']);
    });

    Tenancy::run($this->b->id, function (): void {
        $invoice = Invoice::query()->create(['number' => 'B-1']);
        $invoice->lines()->create(['description' => 'B-1 line']);
    });
});

it('refuses every query without a tenant context', function (): void {
    expect(fn () => Invoice::all())->toThrow(TenantContextMissing::class)
        ->and(fn () => Invoice::query()->count())->toThrow(TenantContextMissing::class)
        ->and(fn () => Invoice::query()->find(1))->toThrow(TenantContextMissing::class)
        ->and(fn () => Invoice::query()->where('number', 'A-1')->exists())->toThrow(TenantContextMissing::class);
});

it('scopes reads to the active tenant, including relations and eager loads', function (): void {
    Tenancy::run($this->a->id, function (): void {
        expect(Invoice::query()->pluck('number')->all())->toBe(['A-1', 'A-2'])
            ->and(Invoice::query()->with('lines')->first()?->lines->pluck('description')->all())->toBe(['A-1 line'])
            ->and(InvoiceLine::query()->count())->toBe(1);
    });

    Tenancy::run($this->b->id, function (): void {
        expect(Invoice::query()->pluck('number')->all())->toBe(['B-1'])
            ->and(Invoice::query()->find(1))->toBeNull(); // A-1 is invisible, not forbidden
    });
});

it('assigns the active tenant on create and refuses a conflicting one', function (): void {
    Tenancy::run($this->a->id, function (): void {
        $invoice = Invoice::query()->create(['number' => 'A-3']);

        expect($invoice->tenant_id)->toBe($this->a->id)
            ->and($invoice->tenantId()?->toString())->toBe($this->a->id)
            ->and($invoice->belongsToTenant($this->a->id))->toBeTrue()
            ->and($invoice->tenant->name)->toBe('Acme');

        $foreign = new Invoice(['number' => 'B-9']);
        $foreign->tenant_id = $this->b->id;

        expect(fn () => $foreign->save())->toThrow(TenantMismatch::class);
    });
});

it('refuses to create without a context and requires an explicit tenant inside a bypass', function (): void {
    expect(fn () => Invoice::query()->create(['number' => 'X']))->toThrow(TenantContextMissing::class);

    Tenancy::withoutTenant('import', function (): void {
        expect(fn () => Invoice::query()->create(['number' => 'X']))->toThrow(TenantContextMissing::class);

        $explicit = new Invoice(['number' => 'B-2']);
        $explicit->tenant_id = $this->b->id;
        $explicit->save();

        expect($explicit->fresh()?->tenant_id)->toBe($this->b->id);
    });
});

it('keeps the tenant column out of mass assignment', function (): void {
    Tenancy::run($this->a->id, function (): void {
        $invoice = Invoice::query()->create(['number' => 'A-4', 'tenant_id' => $this->b->id]);

        expect($invoice->tenant_id)->toBe($this->a->id);
    });
});

it('never lets a record move to another tenant', function (): void {
    Tenancy::run($this->a->id, function (): void {
        $invoice = Invoice::query()->where('number', 'A-1')->firstOrFail();
        $invoice->tenant_id = $this->b->id;

        expect(fn () => $invoice->save())->toThrow(TenantColumnImmutable::class);
    });

    Tenancy::withoutTenant('admin', function (): void {
        $invoice = Invoice::query()->where('number', 'A-1')->firstOrFail();
        $invoice->tenant_id = $this->b->id;

        expect(fn () => $invoice->save())->toThrow(TenantColumnImmutable::class);
    });
});

it('refuses query-level updates of the tenant column as well', function (): void {
    Tenancy::run($this->a->id, function (): void {
        expect(fn () => Invoice::query()->where('number', 'A-1')->update(['tenant_id' => $this->b->id]))
            ->toThrow(TenantColumnImmutable::class)
            ->and(fn () => Invoice::query()->update(['invoices.tenant_id' => $this->b->id]))
            ->toThrow(TenantColumnImmutable::class)
            ->and(Invoice::query()->where('number', 'A-1')->update(['number' => 'A-1b']))->toBe(1);
    });
});

it('guards increments, decrements and upserts against the tenant column', function (): void {
    Tenancy::run($this->a->id, function (): void {
        expect(fn () => Invoice::query()->where('number', 'A-1')->increment('tenant_id'))->toThrow(TenantColumnImmutable::class)
            ->and(fn () => Invoice::query()->where('number', 'A-1')->decrement('id', 1, ['tenant_id' => $this->b->id]))->toThrow(TenantColumnImmutable::class)
            ->and(fn () => Invoice::query()->upsert([['number' => 'A-1', 'tenant_id' => $this->b->id]], ['number'], ['tenant_id']))->toThrow(TenantColumnImmutable::class)
            ->and(fn () => Invoice::query()->upsert([['number' => 'A-1', 'tenant_id' => $this->b->id]], ['number']))->toThrow(TenantMismatch::class);

        // A legitimate upsert never touches the tenant column and stamps new rows.
        Invoice::query()->upsert([['number' => 'A-1'], ['number' => 'A-5']], ['number']);

        expect(Invoice::query()->pluck('number')->sort()->values()->all())->toBe(['A-1', 'A-2', 'A-5']);
    });

    expect(Tenancy::withoutTenant('check', fn () => Invoice::query()->where('number', 'A-5')->value('tenant_id')))->toBe($this->a->id);
});

it('stamps and verifies the tenant on query-level inserts', function (): void {
    expect(fn () => Invoice::query()->insert(['number' => 'X-1']))->toThrow(TenantContextMissing::class);

    Tenancy::run($this->a->id, function (): void {
        Invoice::query()->insert(['number' => 'A-6']);
        Invoice::query()->insert([['number' => 'A-7'], ['number' => 'A-8', 'tenant_id' => $this->a->id]]);
        $id = Invoice::query()->insertGetId(['number' => 'A-9']);

        expect(Invoice::query()->whereIn('number', ['A-6', 'A-7', 'A-8', 'A-9'])->count())->toBe(4)
            ->and($id)->toBeGreaterThan(0)
            ->and(fn () => Invoice::query()->insert(['number' => 'X-2', 'tenant_id' => $this->b->id]))->toThrow(TenantMismatch::class);
    });

    Tenancy::withoutTenant('import', function (): void {
        expect(fn () => Invoice::query()->insert(['number' => 'X-3']))->toThrow(TenantContextMissing::class);

        Invoice::query()->insertOrIgnore(['number' => 'B-3', 'tenant_id' => $this->b->id]);
        expect(Invoice::query()->where('number', 'B-3')->value('tenant_id'))->toBe($this->b->id);
    });
});

it('refuses to delete a record owned by another tenant', function (): void {
    $foreign = Tenancy::withoutTenant('fetch', fn () => Invoice::query()->where('number', 'B-1')->firstOrFail());

    Tenancy::run($this->a->id, function () use ($foreign): void {
        expect(fn () => $foreign->delete())->toThrow(TenantMismatch::class);
    });

    expect(fn () => $foreign->delete())->toThrow(TenantContextMissing::class);

    Tenancy::run($this->b->id, function () use ($foreign): void {
        expect($foreign->delete())->toBeTrue();
    });
});

it('refuses to update a record that was loaded in another tenant context', function (): void {
    $foreign = Tenancy::withoutTenant('fetch', fn () => Invoice::query()->where('number', 'B-1')->firstOrFail());

    Tenancy::run($this->a->id, function () use ($foreign): void {
        $foreign->number = 'B-1-hijacked';

        expect(fn () => $foreign->save())->toThrow(TenantMismatch::class)
            ->and(fn () => $foreign->update(['total' => 99]))->toThrow(TenantMismatch::class)
            ->and(fn () => $foreign->increment('total'))->toThrow(TenantMismatch::class)
            ->and(fn () => $foreign->decrement('total'))->toThrow(TenantMismatch::class);
    });

    expect(fn () => $foreign->save())->toThrow(TenantContextMissing::class);

    Tenancy::run($this->b->id, function (): void {
        expect(Invoice::query()->where('number', 'B-1')->value('total'))->toBe(0);
    });
});

it('refuses event-less writes of a foreign record as well', function (): void {
    $foreign = Tenancy::withoutTenant('fetch', fn () => Invoice::query()->where('number', 'B-1')->firstOrFail());

    Tenancy::run($this->a->id, function () use ($foreign): void {
        $foreign->number = 'B-1-hijacked';

        expect(fn () => $foreign->saveQuietly())->toThrow(TenantMismatch::class)
            ->and(fn () => $foreign->updateQuietly(['total' => 99]))->toThrow(TenantMismatch::class)
            ->and(fn () => $foreign->deleteQuietly())->toThrow(TenantMismatch::class)
            ->and(fn () => Invoice::withoutEvents(fn () => $foreign->forceDelete()))->toThrow(TenantMismatch::class);
    });

    expect(fn () => $foreign->saveQuietly())->toThrow(TenantContextMissing::class);

    Tenancy::run($this->b->id, function (): void {
        expect(Invoice::query()->where('number', 'B-1')->exists())->toBeTrue();
    });
});

it('refuses to restore a soft-deleted record of another tenant', function (): void {
    $invoice = Tenancy::run($this->b->id, function (): Invoice {
        $invoice = Invoice::query()->where('number', 'B-1')->firstOrFail();
        $invoice->delete();

        return $invoice;
    });

    Tenancy::run($this->a->id, function () use ($invoice): void {
        expect($invoice->trashed())->toBeTrue()
            ->and(fn () => $invoice->restore())->toThrow(TenantMismatch::class);
    });

    Tenancy::run($this->b->id, function () use ($invoice): void {
        expect($invoice->restore())->toBeTrue()
            ->and(Invoice::query()->where('number', 'B-1')->exists())->toBeTrue();
    });
});

it('constrains writes at SQL level when the tenant column was not selected', function (): void {
    // Ownership cannot be checked in PHP when the tenant column is missing from
    // the select; the UPDATE/DELETE is then constrained to the active tenant and
    // simply affects no row.
    $partial = Tenancy::run($this->b->id, fn () => Invoice::query()->select(['id', 'number'])->where('number', 'B-1')->firstOrFail());

    Tenancy::run($this->a->id, function () use ($partial): void {
        $partial->number = 'B-1-hijacked';
        $partial->save();
        $partial->forceDelete();
    });

    Tenancy::run($this->b->id, function (): void {
        expect(Invoice::query()->where('number', 'B-1')->exists())->toBeTrue()
            ->and(Invoice::query()->where('number', 'B-1-hijacked')->exists())->toBeFalse();

        $own = Invoice::query()->select(['id', 'number'])->where('number', 'B-1')->firstOrFail();
        $own->number = 'B-1b';
        $own->save();

        expect(Invoice::query()->where('number', 'B-1b')->exists())->toBeTrue();
    });
});

it('lets a deliberate bypass write records of any tenant', function (): void {
    $foreign = Tenancy::run($this->b->id, fn () => Invoice::query()->where('number', 'B-1')->firstOrFail());

    Tenancy::withoutTenant('support', function () use ($foreign): void {
        $foreign->total = 5;
        $foreign->save();
        $foreign->increment('total');
    });

    expect(Tenancy::run($this->b->id, fn () => Invoice::query()->where('number', 'B-1')->value('total')))->toBe(6);
});

it('rejects cross-tenant relations in the database itself (composite foreign keys)', function (): void {
    $invoiceA = Tenancy::run($this->a->id, fn () => Invoice::query()->where('number', 'A-1')->firstOrFail());

    // Raw SQL, no Eloquent, no scope: the schema still refuses a line of tenant B on an invoice of tenant A ...
    expect(fn () => DB::table('invoice_lines')->insert(['tenant_id' => $this->b->id, 'invoice_id' => $invoiceA->id, 'description' => 'smuggled']))
        ->toThrow(QueryException::class);

    // ... and the same through Eloquent inside a deliberate bypass.
    Tenancy::withoutTenant('import', function () use ($invoiceA): void {
        $line = new InvoiceLine(['invoice_id' => $invoiceA->id, 'description' => 'smuggled']);
        $line->tenant_id = $this->b->id;

        expect(fn () => $line->save())->toThrow(QueryException::class);
    });

    // A line of the same tenant is fine, and cascades with its invoice.
    DB::table('invoice_lines')->insert(['tenant_id' => $this->a->id, 'invoice_id' => $invoiceA->id, 'description' => 'legit']);

    expect(DB::table('invoice_lines')->where('invoice_id', $invoiceA->id)->count())->toBe(2);

    Tenancy::run($this->a->id, fn () => $invoiceA->forceDelete());

    expect(DB::table('invoice_lines')->where('invoice_id', $invoiceA->id)->count())->toBe(0);
});

it('reads across tenants only inside an explicit bypass', function (): void {
    $numbers = Tenancy::withoutTenant('report', fn () => Invoice::query()->orderBy('number')->pluck('number')->all());

    expect($numbers)->toBe(['A-1', 'A-2', 'B-1']);

    $onlyB = Tenancy::withoutTenant('report', fn () => Invoice::query()->forTenant($this->b->id)->pluck('number')->all());

    expect($onlyB)->toBe(['B-1'])
        ->and(fn () => Invoice::all())->toThrow(TenantContextMissing::class);
});

it('still filters when someone removes the scope by hand but a context is active', function (): void {
    // withoutGlobalScope() is Eloquent's own escape hatch and stays possible; this
    // documents that it is the ONLY way around the scope and must be code-reviewed.
    Tenancy::run($this->a->id, function (): void {
        expect(Invoice::query()->withoutGlobalScope(TenantScope::class)->count())->toBe(3)
            ->and(Invoice::query()->count())->toBe(2);
    });
});

it('does not touch the query builder outside Eloquent', function (): void {
    // DB::table() is not covered by the framework – the docs say so; this test pins the fact.
    expect(DB::table('invoices')->count())->toBe(3);
});

it('applies the scope when a job or command runs for a tenant via run()', function (): void {
    $count = Tenancy::run($this->b->id, fn (): int => Invoice::query()->count());

    expect($count)->toBe(1)->and(Tenancy::has())->toBeFalse();
});

it('qualifies the tenant column so joins stay unambiguous', function (): void {
    Tenancy::run($this->a->id, function (): void {
        $rows = Invoice::query()
            ->join('invoice_lines', 'invoice_lines.invoice_id', '=', 'invoices.id')
            ->select('invoices.number')
            ->get();

        expect($rows->pluck('number')->all())->toBe(['A-1']);
    });
});
