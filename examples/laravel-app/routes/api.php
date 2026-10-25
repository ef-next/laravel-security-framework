<?php

declare(strict_types=1);

use App\Http\Controllers\InvoiceController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Outside any tenant
|--------------------------------------------------------------------------
| Authenticated, but no tenant is resolved: the user asks which tenants they
| belong to. Nothing here may touch a tenant-owned model — it would throw
| TenantContextMissing, which is the point.
*/
Route::middleware('auth:sanctum')->get('/me/tenants', function (Request $request) {
    /** @var User $user */
    $user = $request->user();

    return $user->tenants()->get()->map(fn ($tenant) => [
        'id' => $tenant->publicId()->toString(),   // ten_… — what the client sends as X-Tenant-Id
        'slug' => $tenant->slug,
        'name' => $tenant->name,
        'role' => $tenant->pivot->role,
    ]);
});

/*
|--------------------------------------------------------------------------
| Inside a tenant
|--------------------------------------------------------------------------
| "tenant" = tenant.resolve (X-Tenant-Id header → 400 missing / 404 unknown /
| 403 suspended) + tenant.member (403 unless the user is an active member).
| The framework orders itself right before SubstituteBindings, so {invoice}
| is bound with the tenant scope already active: a foreign invoice is a 404.
|
| tenant.token additionally requires that the Sanctum token, if it carries a
| "tenant:<uuid>" ability, is bound to *this* tenant.
*/
Route::middleware(['auth:sanctum', 'tenant', 'tenant.token'])->group(function (): void {
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);

    // Roles are hierarchical by default: owner satisfies admin.
    Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->middleware('tenant.role:admin');
});
