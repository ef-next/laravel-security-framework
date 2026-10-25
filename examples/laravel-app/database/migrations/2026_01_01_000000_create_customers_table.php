<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->tenantId();          // uuid tenant_id + FK to tenants (restrict on delete) + index + UNIQUE(tenant_id, id)
            $table->publicId();          // public_id, string(80), unique
            $table->string('name');
            $table->string('email');
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);   // unique *per tenant*, never globally
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
