<?php

declare(strict_types=1);

use Efnext\Security\Tenant\Context\TenantId;
use Efnext\Security\Tenant\Eloquent\BelongsToTenant;
use Efnext\Security\Tenant\Eloquent\TenantScope;
use Efnext\Security\Tenant\Exceptions\TenantColumnImmutable;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Exceptions\TenantMismatch;
use Efnext\Security\Tenant\Facades\Tenancy;
use Efnext\Security\Tenant\Tests\Fixtures\FillableInvoice;
use Efnext\Security\Tenant\Tests\Fixtures\Invoice;
use Efnext\Security\Tenant\Tests\Fixtures\LockedInvoice;
use Efnext\Security\Tenant\Tests\Fixtures\Project;
use Efnext\Security\Tenant\Tests\Fixtures\Schema;
use Efnext\Security\Tenant\Tests\Fixtures\Tenants;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/*
 * Edge cases of the query-level guards and the trait's configuration that the
 * mutation run showed to be unpinned: default amounts and extra columns of
 * increment()/decrement(), what upsert() writes on conflict, every row of a
 * multi-row insert, mass-assignment opt-in, custom tenant columns and the
 * TenantId overloads.
 */

beforeEach(function (): void {
    Schema::createFixtureTables();
    Tenancy::clear();

    $this->a = Tenants::create('Acme', 'acme');
    $this->b = Tenants::create('Beta', 'beta');

    Tenancy::run($this->a->id, fn () => Invoice::query()->create(['number' => 'A-1']));
    Tenancy::run($this->b->id, fn () => Invoice::query()->create(['number' => 'B-1']));
});

it('applies increments and decrements with their default amount and extra columns', function (): void {
    Tenancy::run($this->a->id, function (): void {
        $query = fn () => Invoice::query()->where('number', 'A-1');

        expect($query()->increment('total'))->toBe(1)
            ->and($query()->value('total'))->toBe(1)
            ->and($query()->increment('total', 5, ['source_tenant_id' => 'legacy']))->toBe(1)
            ->and($query()->first()?->only(['total', 'source_tenant_id']))->toBe(['total' => 6, 'source_tenant_id' => 'legacy'])
            ->and($query()->decrement('total'))->toBe(1)
            ->and($query()->value('total'))->toBe(5)
            ->and($query()->decrement('total', 2))->toBe(1)
            ->and($query()->value('total'))->toBe(3);

        // The tenant column is refused as counter and as extra column, plain or qualified.
        expect(fn () => $query()->decrement('tenant_id'))->toThrow(TenantColumnImmutable::class)
            ->and(fn () => $query()->increment('total', 1, ['tenant_id' => $this->b->id]))->toThrow(TenantColumnImmutable::class)
            ->and(fn () => $query()->increment('total', 1, ['invoices.tenant_id' => $this->b->id]))->toThrow(TenantColumnImmutable::class)
            ->and(fn () => $query()->decrement('total', 1, ['invoices.tenant_id' => $this->b->id]))->toThrow(TenantColumnImmutable::class)
            ->and(fn () => $query()->update(['invoices.tenant_id' => $this->b->id]))->toThrow(TenantColumnImmutable::class);

        // A column that merely ends in "tenant_id" is an ordinary column.
        expect($query()->update(['source_tenant_id' => 'other']))->toBe(1)
            ->and($query()->value('source_tenant_id'))->toBe('other')
            ->and($query()->value('total'))->toBe(3);
    });
});

it('never writes the tenant column on upsert conflicts and honours an explicit update list', function (): void {
    Tenancy::run($this->a->id, function (): void {
        // Nothing to do, nothing touched.
        DB::enableQueryLog();
        expect(Invoice::query()->upsert([], ['number']))->toBe(0)->and(DB::getQueryLog())->toBe([]);
        DB::disableQueryLog();

        // Conflict on a unique key that is not tenant-qualified: the row keeps its owner (documented caveat).
        Invoice::query()->upsert([['number' => 'B-1', 'total' => 9]], ['number']);

        // An explicit update list is used as given: "total" is not in it, so it stays.
        Invoice::query()->upsert([['number' => 'A-1', 'total' => 42]], ['number'], ['number']);
        Invoice::query()->upsert([['number' => 'A-1', 'total' => 7]], ['number'], ['total' => 7]);

        // A conflicting tenant in the update list is refused however it is spelled.
        expect(fn () => Invoice::query()->upsert([['number' => 'A-1']], ['number'], ['invoices.tenant_id' => $this->b->id]))->toThrow(TenantColumnImmutable::class)
            ->and(fn () => Invoice::query()->upsert([['number' => 'A-1']], ['number'], ['total', 'tenant_id']))->toThrow(TenantColumnImmutable::class);
    });

    $rows = Tenancy::withoutTenant('check', fn () => Invoice::query()->orderBy('number')->get(['number', 'tenant_id', 'total'])->map->toArray()->all());

    expect($rows)->toBe([
        ['number' => 'A-1', 'tenant_id' => $this->a->id, 'total' => 7],
        ['number' => 'B-1', 'tenant_id' => $this->b->id, 'total' => 9],
    ]);
});

it('stamps and checks every row of a multi-row insert, not only the first', function (): void {
    Tenancy::run($this->a->id, function (): void {
        Invoice::query()->insert([['number' => 'A-2', 'tenant_id' => $this->a->id], ['number' => 'A-3']]);

        expect(Invoice::query()->where('number', 'A-3')->value('tenant_id'))->toBe($this->a->id)
            ->and(fn () => Invoice::query()->insert([['number' => 'A-4'], ['number' => 'X-1', 'tenant_id' => $this->b->id]]))->toThrow(TenantMismatch::class)
            ->and(Invoice::query()->where('number', 'A-4')->exists())->toBeFalse();
    });

    Tenancy::withoutTenant('import', function (): void {
        expect(fn () => Invoice::query()->insert([['number' => 'B-2', 'tenant_id' => $this->b->id], ['number' => 'X-2']]))->toThrow(TenantContextMissing::class)
            ->and(Invoice::query()->where('number', 'B-2')->exists())->toBeFalse();

        // insertGetId() takes a single row and stamps it like the others.
        $id = Invoice::query()->insertGetId(['number' => 'B-3', 'tenant_id' => $this->b->id]);

        expect(Invoice::query()->whereKey($id)->value('tenant_id'))->toBe($this->b->id);
    });
});

it('lets a model opt the tenant column into mass assignment and leaves fully guarded models alone', function (): void {
    // Default: the trait guards the column, exactly once, without disturbing an explicit guard list.
    expect((new Invoice)->getGuarded())->toBe(['tenant_id'])
        ->and(array_count_values((new class extends Model
        {
            use BelongsToTenant;

            protected $guarded = ['tenant_id', 'id'];
        })->getGuarded()))->toBe(['tenant_id' => 1, 'id' => 1]);

    // guarded = ['*'] means "nothing is fillable" and must stay exactly that.
    expect((new LockedInvoice)->getGuarded())->toBe(['*'])
        ->and(fn () => new LockedInvoice(['number' => 'L-1']))->toThrow(MassAssignmentException::class);

    // Opt-in via $fillable (Laravel's default guard list stays untouched): the value is taken,
    // and then verified like any explicit tenant.
    expect((new FillableInvoice)->getGuarded())->toBe(['*'])->and((new FillableInvoice)->isFillable('tenant_id'))->toBeTrue();

    Tenancy::run($this->a->id, function (): void {
        $own = FillableInvoice::query()->create(['number' => 'F-1', 'tenant_id' => $this->a->id]);

        expect($own->tenant_id)->toBe($this->a->id)
            ->and(fn () => FillableInvoice::query()->create(['number' => 'F-2', 'tenant_id' => $this->b->id]))->toThrow(TenantMismatch::class);
    });

    $imported = Tenancy::withoutTenant('import', fn () => FillableInvoice::query()->create(['number' => 'F-3', 'tenant_id' => $this->b->id]));

    expect($imported->tenant_id)->toBe($this->b->id);
});

it('resolves the tenant column from the model, then the configuration, then the default', function (): void {
    $plain = new class extends Model
    {
        use BelongsToTenant;
    };
    $empty = new class extends Model
    {
        use BelongsToTenant;

        protected string $tenantColumn = '';
    };

    expect((new Project)->tenantColumn())->toBe('org_id')
        ->and(TenantScope::columnOf(new Project))->toBe('org_id')
        ->and($plain->tenantColumn())->toBe('tenant_id');

    config()->set('tenant.column', 'org_id');

    expect($plain->tenantColumn())->toBe('org_id')
        ->and($empty->tenantColumn())->toBe('org_id')
        ->and((new Project)->tenantColumn())->toBe('org_id');

    config()->set('tenant.column', '');

    expect($plain->tenantColumn())->toBe('tenant_id')->and($empty->tenantColumn())->toBe('tenant_id');
});

it('scopes, stamps and guards a custom tenant column end to end', function (): void {
    Tenancy::run($this->a->id, function (): void {
        $project = Project::query()->create(['name' => 'Alpha']);

        expect($project->org_id)->toBe($this->a->id)
            ->and(Project::query()->toSql())->toContain('projects', 'org_id')
            ->and(Project::query()->toSql())->not->toContain('tenant_id')
            ->and((new Project)->getGuarded())->toBe(['org_id'])
            ->and(fn () => Project::query()->update(['org_id' => $this->b->id]))->toThrow(TenantColumnImmutable::class)
            ->and(fn () => Project::query()->insert(['name' => 'Foreign', 'org_id' => $this->b->id]))->toThrow(TenantMismatch::class);
    });

    Tenancy::run($this->b->id, function (): void {
        Project::query()->create(['name' => 'Beta project']);

        expect(Project::query()->pluck('name')->all())->toBe(['Beta project'])
            ->and(Project::query()->forTenant($this->a->id)->count())->toBe(0);   // the scope still applies
    });

    expect(Tenancy::withoutTenant('report', fn () => Project::query()->forTenant(TenantId::fromString($this->a->id))->pluck('name')->all()))->toBe(['Alpha'])
        ->and(Tenancy::withoutTenant('report', fn () => Project::query()->forTenant($this->b->id)->pluck('name')->all()))->toBe(['Beta project']);
});

it('answers ownership questions for TenantId objects, strings and integers', function (): void {
    $invoice = Tenancy::run($this->a->id, fn () => Invoice::query()->where('number', 'A-1')->firstOrFail());

    expect($invoice->belongsToTenant(TenantId::fromString($this->a->id)))->toBeTrue()
        ->and($invoice->belongsToTenant($this->a->id))->toBeTrue()
        ->and($invoice->belongsToTenant(TenantId::fromString($this->b->id)))->toBeFalse()
        ->and($invoice->belongsToTenant($this->b->id))->toBeFalse()
        ->and($invoice->belongsToTenant(''))->toBeFalse()
        ->and($invoice->belongsToTenant('not a tenant id!'))->toBeFalse();

    // Integer tenant ids (auto-increment tenants) are normalised to strings.
    $numeric = new Invoice;
    $numeric->setRawAttributes(['tenant_id' => 42], true);

    expect($numeric->tenantId()?->toString())->toBe('42')
        ->and($numeric->belongsToTenant('42'))->toBeTrue()
        ->and($numeric->belongsToTenant(TenantId::fromString('42')))->toBeTrue()
        ->and($numeric->belongsToTenant('43'))->toBeFalse();

    $none = new Invoice;

    expect($none->tenantId())->toBeNull()->and($none->belongsToTenant($this->a->id))->toBeFalse();
});

it('judges ownership by what was loaded, not by what was written into memory', function (): void {
    $foreign = Tenancy::withoutTenant('fetch', fn () => Invoice::query()->where('number', 'B-1')->firstOrFail());

    Tenancy::run($this->a->id, function () use ($foreign): void {
        // The in-memory tenant claims A; the record was loaded as B. Ownership check first, before the dirty check.
        $foreign->tenant_id = $this->a->id;

        expect(fn () => $foreign->delete())->toThrow(TenantMismatch::class)
            ->and(fn () => $foreign->forceDelete())->toThrow(TenantMismatch::class);
    });

    // Inside a bypass the persisted tenant constrains the statement: a manipulated
    // in-memory tenant is caught as an illegal move, the row stays.
    Tenancy::withoutTenant('admin', function () use ($foreign): void {
        expect(fn () => $foreign->delete())->toThrow(TenantColumnImmutable::class);
    });

    expect(Tenancy::withoutTenant('check', fn () => Invoice::query()->where('number', 'B-1')->value('tenant_id')))->toBe($this->b->id);

    // Even inside a bypass the statement is pinned to the tenant the record was loaded with:
    // if the row moved behind the model's back, the write affects nothing.
    $loadedAsB = Tenancy::withoutTenant('fetch', fn () => Invoice::query()->where('number', 'B-1')->firstOrFail());
    DB::table('invoices')->where('number', 'B-1')->update(['tenant_id' => $this->a->id]);

    Tenancy::withoutTenant('admin', function () use ($loadedAsB): void {
        $loadedAsB->delete();
        $loadedAsB->total = 99;
        $loadedAsB->saveQuietly();
    });

    expect(DB::table('invoices')->where('number', 'B-1')->first())->toMatchObject(['tenant_id' => $this->a->id, 'deleted_at' => null, 'total' => 0]);
});

it('creates with an explicitly matching tenant and refuses a mismatching one at query level too', function (): void {
    Tenancy::run($this->a->id, function (): void {
        $invoice = new Invoice(['number' => 'A-9']);
        $invoice->tenant_id = $this->a->id;
        $invoice->save();

        expect($invoice->exists)->toBeTrue()->and($invoice->fresh()?->tenant_id)->toBe($this->a->id);

        $line = $invoice->lines()->make(['description' => 'x']);
        $line->tenant_id = $this->b->id;

        expect(fn () => $line->save())->toThrow(TenantMismatch::class);
    });
});
