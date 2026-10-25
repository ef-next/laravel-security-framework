<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use Efnext\Security\Audit\Facades\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every method here runs behind the "tenant" middleware group (see routes/api.php):
 * a tenant is active, the user is an active member of it, and {invoice} was bound
 * inside that tenant. Nothing in this class filters by tenant — the scope does.
 */
class InvoiceController extends Controller
{
    public function index(): JsonResponse
    {
        // Scoped: only this tenant's invoices, whatever the user sends.
        $invoices = Invoice::query()->with('customer')->latest('id')->get();

        return response()->json($invoices->map(fn (Invoice $invoice) => $this->present($invoice)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer' => ['required', 'string'],           // the customer's public id: cus_…
            'number' => ['required', 'string', 'max:32'],
            'total_cents' => ['required', 'integer', 'min:0'],
        ]);

        // wherePublicId() never queries for a malformed id and is scoped to the tenant,
        // so a customer of another tenant is simply "not found".
        $customer = Customer::query()->wherePublicId($data['customer'])->firstOrFail();

        $invoice = Audit::transaction(function () use ($customer, $data): Invoice {
            $invoice = $customer->invoices()->create([
                'number' => $data['number'],
                'total_cents' => $data['total_cents'],
            ]);

            Audit::action('invoice.created')
                ->on($invoice)
                ->with(['number' => $invoice->number, 'total_cents' => $invoice->total_cents, 'customer' => $customer->publicId()->toString()])
                ->record();

            return $invoice;
        });

        return response()->json($this->present($invoice), 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json($this->present($invoice->load('customer')));
    }

    /**
     * Business change and audit record commit together, or not at all. The closure may
     * run more than once (retry on a chain conflict), so nothing with side effects
     * outside the database belongs in it — the notification is queued afterwards.
     */
    public function pay(Invoice $invoice): JsonResponse
    {
        if ($invoice->isPaid()) {
            Audit::action('invoice.pay')->on($invoice)->failed()->with(['reason' => 'already_paid'])->record();

            return response()->json(['message' => 'Invoice is already paid.'], 409);
        }

        Audit::transaction(function () use ($invoice): void {
            $invoice->markPaid();

            Audit::action('invoice.paid')
                ->on($invoice)
                ->with(['total_cents' => $invoice->total_cents])
                ->record();
        });

        \App\Jobs\SendPaymentReceipt::dispatch($invoice);   // the tenant travels with the job

        return response()->json($this->present($invoice->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invoice $invoice): array
    {
        return [
            'id' => $invoice->publicId()->toString(),         // never the database id
            'gid' => $invoice->globalObjectId()->toString(),  // billing:invoice:inv_…
            'number' => $invoice->number,
            'total_cents' => $invoice->total_cents,
            'status' => $invoice->status,
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'customer' => $invoice->relationLoaded('customer') ? $invoice->customer->publicId()->toString() : null,
        ];
    }
}
