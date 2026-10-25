<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('tenant.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnection();
    }

    public function up(): void
    {
        $table = (string) config('tenant.table', 'tenants');

        Schema::create($table, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->publicId();
            $table->string('slug', 64)->nullable()->unique();
            $table->string('name', 191);
            $table->string('status', 16)->default('active')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('tenant.table', 'tenants'));
    }
};
