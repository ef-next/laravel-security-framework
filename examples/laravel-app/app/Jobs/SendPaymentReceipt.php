<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use Efnext\Security\Tenant\Facades\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched from InvoiceController::pay() inside a tenant context. The framework puts
 * the tenant into the job payload and re-activates it in the worker before this job
 * runs, so SerializesModels can re-fetch the (tenant-scoped) invoice and any query in
 * handle() is scoped. A job dispatched *without* a tenant context would run without
 * one and fail closed on the first tenant-owned query.
 *
 * A job that must pick its own tenant (a scheduled per-tenant task, for example)
 * implements Efnext\Security\Tenant\Queue\TenantAware and returns
 * [new TenantContextMiddleware] from middleware().
 */
class SendPaymentReceipt implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function handle(): void
    {
        // Stand-in for the e-mail: prove that the tenant context arrived with the job.
        Log::info('payment receipt', [
            'tenant' => Tenancy::requireId()->toString(),
            'invoice' => $this->invoice->publicId()->toString(),
            'customer' => $this->invoice->customer->email,
        ]);
    }
}
