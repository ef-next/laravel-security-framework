<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Tenant\Eloquent\Tenant;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private User $alice;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->alice = User::factory()->create();
        $this->alice->grantTenantMembership($this->acme->tenantId(), 'owner');

        $this->invoice = Tenancy::run($this->acme->id, function (): Invoice {
            $customer = Customer::create(['name' => 'Ada', 'email' => 'ada@acme.test']);

            return $customer->invoices()->create(['number' => 'A-1', 'total_cents' => 12_500]);
        });
    }

    public function test_paying_an_invoice_leaves_a_signed_event_on_the_tenants_chain(): void
    {
        $this->withToken($this->alice->createToken('t')->plainTextToken)
            ->withHeader('X-Tenant-Id', $this->acme->publicId()->toString())
            ->postJson('/api/invoices/'.$this->invoice->publicId()->toString().'/pay')
            ->assertOk();

        $event = DB::table('audit_events')
            ->where('action', 'invoice.paid')
            ->where('tenant_id', $this->acme->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('invoice', $event->resource_type);
        $this->assertSame($this->invoice->publicId()->toString(), $event->resource_id);
        $this->assertSame('user', $event->actor_type);
        $this->assertSame((string) $this->alice->id, $event->actor_id);
        $this->assertSame('k1', $event->key_id);                  // signed with the active key
        $this->assertNotNull($event->signature);
        $this->assertSame(['total_cents' => 12500], json_decode((string) $event->metadata, true));

        $report = Audit::verifyTenant($this->acme->id);
        $this->assertTrue($report->isIntact(), json_encode($report->toArray()));
    }

    public function test_business_change_and_audit_record_commit_together(): void
    {
        $this->withToken($this->alice->createToken('t')->plainTextToken)
            ->withHeader('X-Tenant-Id', $this->acme->publicId()->toString())
            ->postJson('/api/invoices/'.$this->invoice->publicId()->toString().'/pay')
            ->assertOk();

        // One business change, exactly one "paid" event, in sequence order on the chain.
        $sequences = DB::table('audit_events')->where('tenant_id', $this->acme->id)->orderBy('sequence')->pluck('sequence')->all();

        $this->assertSame(range(1, count($sequences)), $sequences);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'invoice.paid')->count());
    }

    public function test_audit_rows_cannot_be_updated_or_deleted_at_the_database_level(): void
    {
        Tenancy::run($this->acme->id, fn () => Audit::action('invoice.viewed')->on($this->invoice)->record());

        // Each attempt runs in its own (nested) transaction: RefreshDatabase wraps the test
        // in one, and PostgreSQL refuses further statements in a transaction after an
        // error — the savepoint Laravel creates here is rolled back, the test goes on.
        try {
            DB::transaction(fn () => DB::table('audit_events')->where('action', 'invoice.viewed')->update(['action' => 'invoice.deleted']));
            $this->fail('UPDATE on audit_events should have been refused by the trigger.');
        } catch (QueryException) {
            // expected: append-only trigger
        }

        try {
            DB::transaction(fn () => DB::table('audit_events')->where('action', 'invoice.viewed')->delete());
            $this->fail('DELETE on audit_events should have been refused by the trigger.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(1, DB::table('audit_events')->where('action', 'invoice.viewed')->count());
        $this->assertTrue(Audit::verifyTenant($this->acme->id)->isIntact());
    }

    public function test_a_denied_request_is_audited_as_denied(): void
    {
        $stranger = User::factory()->create();

        $this->withToken($stranger->createToken('t')->plainTextToken)
            ->withHeader('X-Tenant-Id', $this->acme->publicId()->toString())
            ->getJson('/api/invoices')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'tenant.access_denied',
            'outcome' => 'denied',
            'tenant_id' => $this->acme->id,
        ]);
    }
}
