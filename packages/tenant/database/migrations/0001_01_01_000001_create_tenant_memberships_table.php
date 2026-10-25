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
        $table = (string) config('tenant.memberships.table', 'tenant_memberships');
        $tenants = (string) config('tenant.table', 'tenants');
        $userKeyType = (string) config('tenant.memberships.user_key_type', 'unsignedBigInteger');

        Schema::create($table, function (Blueprint $table) use ($tenants, $userKeyType): void {
            $table->ulid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained($tenants)->cascadeOnUpdate()->cascadeOnDelete();

            match ($userKeyType) {
                'uuid' => $table->uuid('user_id'),
                'ulid' => $table->ulid('user_id'),
                'string' => $table->string('user_id', 191),
                default => $table->unsignedBigInteger('user_id'),
            };

            $table->string('role', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('tenant.memberships.table', 'tenant_memberships'));
    }
};
