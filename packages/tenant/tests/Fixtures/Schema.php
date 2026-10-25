<?php

declare(strict_types=1);

namespace Efnext\Security\Tenant\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema as SchemaFacade;

final class Schema
{
    public static function createFixtureTables(): void
    {
        SchemaFacade::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
        });

        SchemaFacade::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->tenantId();
            $table->string('number')->unique();
            $table->unsignedInteger('total')->default(0);
            $table->string('source_tenant_id')->nullable();   // a business column that merely ends in "tenant_id"
            $table->timestamp('deleted_at')->nullable();
        });

        SchemaFacade::create('invoice_notes', function (Blueprint $table): void {
            $table->id();
            $table->tenantId();
            $table->tenantForeignId('invoice_id', 'invoices', nullable: true)->cascadeOnDelete();   // optional, still tenant-bound (SET NULL is refused, see TenantForeignKeyDefinition)
            $table->string('body');
        });

        SchemaFacade::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->tenantId('org_id');
            $table->string('name');
        });

        SchemaFacade::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->tenantId();
            $table->tenantForeignId('invoice_id', 'invoices')->cascadeOnDelete();   // FK (tenant_id, invoice_id) -> invoices(tenant_id, id)
            $table->string('description');
        });
    }
}
