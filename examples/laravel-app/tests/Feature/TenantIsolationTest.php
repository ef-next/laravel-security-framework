<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use Efnext\Security\Tenant\Eloquent\Tenant;
use Efnext\Security\Tenant\Exceptions\TenantContextMissing;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The minimum every application integrating the framework should carry
 * (see AGENTS.md → "How you verify your own work"). These tests do not go
 * through HTTP; they prove the model layer fails closed on its own.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);
    }

    public function test_it_refuses_tenant_owned_queries_without_a_tenant(): void
    {
        $this->expectException(TenantContextMissing::class);

        Invoice::query()->count();
    }

    public function test_it_does_not_leak_rows_across_tenants(): void
    {
        Tenancy::run($this->acme->id, function (): void {
            Customer::create(['name' => 'Ada', 'email' => 'ada@acme.test']);
        });

        Tenancy::run($this->beta->id, function (): void {
            $this->assertSame(0, Customer::query()->count());
            $this->assertNull(Customer::query()->where('email', 'ada@acme.test')->first());
        });

        Tenancy::run($this->acme->id, function (): void {
            $this->assertSame(1, Customer::query()->count());
        });
    }

    public function test_the_same_email_may_exist_in_two_tenants(): void
    {
        Tenancy::run($this->acme->id, fn () => Customer::create(['name' => 'Ada', 'email' => 'ada@example.test']));
        Tenancy::run($this->beta->id, fn () => Customer::create(['name' => 'Ada', 'email' => 'ada@example.test']));

        Tenancy::run($this->beta->id, fn () => $this->assertSame(1, Customer::query()->count()));
    }

    public function test_the_database_refuses_an_invoice_for_another_tenants_customer(): void
    {
        $acmeCustomer = Tenancy::run($this->acme->id, fn () => Customer::create(['name' => 'Ada', 'email' => 'ada@acme.test']));

        $this->expectException(QueryException::class);   // composite foreign key (tenant_id, customer_id)

        Tenancy::run($this->beta->id, function () use ($acmeCustomer): void {
            // own (nested) transaction: PostgreSQL would otherwise refuse every statement of
            // the RefreshDatabase transaction after this error
            DB::transaction(fn () => Invoice::create(['customer_id' => $acmeCustomer->id, 'number' => 'B-1', 'total_cents' => 100]));
        });
    }

    public function test_a_model_loaded_in_one_tenant_cannot_be_updated_in_another(): void
    {
        $customer = Tenancy::run($this->acme->id, fn () => Customer::create(['name' => 'Ada', 'email' => 'ada@acme.test']));

        $this->expectException(\Efnext\Security\Tenant\Exceptions\TenantMismatch::class);

        Tenancy::run($this->beta->id, function () use ($customer): void {
            $customer->update(['name' => 'Eve']);
        });
    }

    public function test_public_ids_are_assigned_on_create_and_carry_the_prefix(): void
    {
        $customer = Tenancy::run($this->acme->id, fn () => Customer::create(['name' => 'Ada', 'email' => 'ada@acme.test']));

        $this->assertStringStartsWith('cus_', $customer->publicId()->toString());
        $this->assertSame('billing:customer:'.$customer->publicId()->toString(), $customer->globalObjectId()->toString());
    }
}
