<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use Efnext\Security\Audit\Facades\Audit;
use Efnext\Security\Tenant\Eloquent\Tenant;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Two tenants, one user who is owner of the first and member of the second, a
 * customer and an invoice in each — and the curl lines to try the API by hand.
 *
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $acme = Tenant::firstOrCreate(['slug' => 'acme'], ['name' => 'Acme AB']);
        $beta = Tenant::firstOrCreate(['slug' => 'beta'], ['name' => 'Beta GmbH']);

        $alice = User::firstOrCreate(['email' => 'alice@example.test'], ['name' => 'Alice', 'password' => 'password']);
        $alice->grantTenantMembership($acme->tenantId(), 'owner');
        $alice->grantTenantMembership($beta->tenantId(), 'member');

        // The first write on a brand-new tenant's chain is cheapest when the head row exists already.
        Audit::prepareChain($acme->tenantId());
        Audit::prepareChain($beta->tenantId());

        $invoices = [];
        foreach ([[$acme, 'Ada', 'ada@acme.test', 'A-1001', 12_500], [$beta, 'Bea', 'bea@beta.test', 'B-2001', 900]] as [$tenant, $name, $email, $number, $total]) {
            $invoices[$tenant->slug] = Tenancy::run($tenant->id, function () use ($name, $email, $number, $total) {
                $customer = Customer::firstOrCreate(['email' => $email], ['name' => $name]);

                return Audit::transaction(function () use ($customer, $number, $total) {
                    $invoice = $customer->invoices()->firstOrCreate(['number' => $number], ['total_cents' => $total]);
                    Audit::action('invoice.created')->on($invoice)->with(['number' => $number, 'total_cents' => $total])->bySystem('demo-seeder')->record();

                    return $invoice;
                });
            });
        }

        $token = $alice->createToken('demo')->plainTextToken;

        $this->command?->newLine();
        $this->command?->info('Demo data ready. Try it (php artisan serve in another terminal):');
        $this->command?->newLine();
        $this->command?->line(sprintf('  export TOKEN="%s"', $token));
        $this->command?->line(sprintf('  export ACME="%s"   # X-Tenant-Id: public id (a slug or the UUID work too)', $acme->publicId()->toString()));
        $this->command?->line(sprintf('  export BETA="%s"', $beta->publicId()->toString()));
        $this->command?->newLine();
        $this->command?->line('  curl -s -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/api/me/tenants');
        $this->command?->line('  curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: $ACME" http://127.0.0.1:8000/api/invoices');
        $this->command?->line(sprintf('  curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: $ACME" http://127.0.0.1:8000/api/invoices/%s/pay   # owner: 200', $invoices['acme']->publicId()->toString()));
        $this->command?->line(sprintf('  curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: $BETA" http://127.0.0.1:8000/api/invoices/%s/pay   # member: 403', $invoices['beta']->publicId()->toString()));
        $this->command?->line(sprintf('  curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Id: $BETA" http://127.0.0.1:8000/api/invoices/%s        # acme invoice in beta: 404', $invoices['acme']->publicId()->toString()));
        $this->command?->line('  curl -s -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/api/invoices                             # no tenant: 400');
        $this->command?->newLine();
        $this->command?->line('  php artisan audit:verify && php artisan security:doctor');
    }
}
