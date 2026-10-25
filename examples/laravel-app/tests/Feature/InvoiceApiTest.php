<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Efnext\Security\Tenant\Eloquent\Tenant;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One request test per guarantee the routes rely on: no tenant → 400, unknown
 * tenant → 404, non-member → 403, foreign record → 404, role → 403, token bound
 * to another tenant → 403, member → 200.
 */
class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $beta;

    private User $alice;   // owner of acme, member of beta

    private User $bob;     // no memberships

    private Customer $acmeCustomer;

    private Invoice $acmeInvoice;

    private Invoice $betaInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);

        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();

        $this->alice->grantTenantMembership($this->acme->tenantId(), 'owner');
        $this->alice->grantTenantMembership($this->beta->tenantId(), 'member');

        [$this->acmeCustomer, $this->acmeInvoice] = Tenancy::run($this->acme->id, function (): array {
            $customer = Customer::create(['name' => 'Ada', 'email' => 'ada@acme.test']);
            $invoice = $customer->invoices()->create(['number' => 'A-1', 'total_cents' => 12_500]);

            return [$customer, $invoice];
        });

        $this->betaInvoice = Tenancy::run($this->beta->id, function (): Invoice {
            $customer = Customer::create(['name' => 'Bea', 'email' => 'bea@beta.test']);

            return $customer->invoices()->create(['number' => 'B-1', 'total_cents' => 900]);
        });
    }

    public function test_a_member_sees_only_the_active_tenants_invoices(): void
    {
        $response = $this->as($this->alice, $this->acme)->getJson('/api/invoices');

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame($this->acmeInvoice->publicId()->toString(), $response->json('0.id'));
        $this->assertSame('billing:invoice:'.$this->acmeInvoice->publicId()->toString(), $response->json('0.gid'));
    }

    public function test_a_request_without_a_tenant_header_is_a_400(): void
    {
        $this->withToken($this->alice->createToken('t')->plainTextToken)
            ->getJson('/api/invoices')
            ->assertStatus(400);
    }

    public function test_an_unknown_tenant_is_a_404(): void
    {
        $this->withToken($this->alice->createToken('t')->plainTextToken)
            ->withHeader('X-Tenant-Id', 'nope')
            ->getJson('/api/invoices')
            ->assertNotFound();
    }

    public function test_a_non_member_is_a_403_and_the_denial_is_audited(): void
    {
        $this->as($this->bob, $this->acme)->getJson('/api/invoices')->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'tenant.access_denied',
            'tenant_id' => $this->acme->id,
            'actor_id' => (string) $this->bob->id,
        ]);
    }

    public function test_a_suspended_tenant_is_a_403(): void
    {
        $this->acme->update(['status' => 'suspended']);

        $this->as($this->alice, $this->acme)->getJson('/api/invoices')->assertForbidden();
    }

    public function test_another_tenants_invoice_is_a_404_not_a_403(): void
    {
        // alice is a member of beta, but the invoice belongs to acme: bound inside beta → not found.
        $this->as($this->alice, $this->beta)
            ->getJson('/api/invoices/'.$this->acmeInvoice->publicId()->toString())
            ->assertNotFound();
    }

    public function test_a_malformed_public_id_is_a_404(): void
    {
        $this->as($this->alice, $this->acme)->getJson('/api/invoices/42')->assertNotFound();
        $this->as($this->alice, $this->acme)->getJson('/api/invoices/cus_notaninvoice')->assertNotFound();
    }

    public function test_paying_requires_the_admin_role(): void
    {
        // member in beta → 403; owner in acme → 200 (owner satisfies admin).
        $this->as($this->alice, $this->beta)
            ->postJson('/api/invoices/'.$this->betaInvoice->publicId()->toString().'/pay')
            ->assertForbidden();

        $this->as($this->alice, $this->acme)
            ->postJson('/api/invoices/'.$this->acmeInvoice->publicId()->toString().'/pay')
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->assertTrue($this->acmeInvoice->refresh()->isPaid());
    }

    public function test_paying_twice_is_a_409(): void
    {
        $url = '/api/invoices/'.$this->acmeInvoice->publicId()->toString().'/pay';

        $this->as($this->alice, $this->acme)->postJson($url)->assertOk();
        $this->as($this->alice, $this->acme)->postJson($url)->assertStatus(409);
    }

    public function test_a_token_bound_to_another_tenant_is_refused(): void
    {
        $betaToken = $this->alice->createToken('cli', ['tenant:'.$this->beta->id])->plainTextToken;

        $this->withToken($betaToken)
            ->withHeader('X-Tenant-Id', $this->acme->publicId()->toString())
            ->getJson('/api/invoices')
            ->assertForbidden();

        $this->withToken($betaToken)
            ->withHeader('X-Tenant-Id', $this->beta->publicId()->toString())
            ->getJson('/api/invoices')
            ->assertOk();
    }

    public function test_an_invoice_can_only_be_created_for_a_customer_of_the_active_tenant(): void
    {
        $payload = ['customer' => $this->acmeCustomer->publicId()->toString(), 'number' => 'X-1', 'total_cents' => 100];

        // acme customer, beta context → the customer is "not found" (scoped lookup).
        $this->as($this->alice, $this->beta)->postJson('/api/invoices', $payload)->assertNotFound();

        $this->as($this->alice, $this->acme)->postJson('/api/invoices', $payload)
            ->assertCreated()
            ->assertJsonPath('number', 'X-1');
    }

    public function test_the_tenant_list_is_available_outside_a_tenant(): void
    {
        $this->withToken($this->alice->createToken('t')->plainTextToken)
            ->getJson('/api/me/tenants')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['slug' => 'acme', 'role' => 'owner']);
    }

    /**
     * Authenticated request for a user acting in a tenant: Sanctum token + X-Tenant-Id (public id).
     */
    private function as(User $user, Tenant $tenant): static
    {
        return $this->withToken($user->createToken('test')->plainTextToken)
            ->withHeader('X-Tenant-Id', $tenant->publicId()->toString());
    }
}
