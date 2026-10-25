<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->tenantId();
            // Composite foreign key (tenant_id, customer_id) -> customers (tenant_id, id):
            // the database itself refuses an invoice that points at another tenant's customer.
            $table->tenantForeignId('customer_id', 'customers')->restrictOnDelete();
            $table->publicId();
            $table->string('number', 32);
            $table->unsignedBigInteger('total_cents');
            $table->string('status', 16)->default('open');
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
