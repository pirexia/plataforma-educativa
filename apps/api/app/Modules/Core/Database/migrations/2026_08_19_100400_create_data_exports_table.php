<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CORE-005 (paso 1.1). datos.md §A.4: primitiva compartida entre
 * módulos vía la interfaz pública ExportRequestService (INV-007). `kind`
 * solo admite 'audit_logs' en 1.1; cada módulo amplía el CHECK por expand.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('data_exports', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('kind');
            $table->text('format');
            $table->jsonb('filters')->nullable();
            $table->text('status')->default('pendiente');
            $table->text('object_key')->nullable();
            $table->integer('row_count')->nullable();
            $table->text('error_code')->nullable();
            TenantMigration::tenantForeignId($table, 'requested_by', 'users');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE data_exports ADD CONSTRAINT data_exports_kind_check
                CHECK (kind IN ('audit_logs'))
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE data_exports ADD CONSTRAINT data_exports_format_check
                CHECK (format IN ('csv'))
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE data_exports ADD CONSTRAINT data_exports_status_check
                CHECK (status IN ('pendiente', 'generando', 'completada', 'fallida'))
            SQL);

        $owner->statement(
            'CREATE INDEX data_exports_tenant_requested_by_idx ON data_exports (tenant_id, requested_by, created_at DESC)'
        );
        $owner->statement(
            'CREATE INDEX data_exports_tenant_expires_idx ON data_exports (tenant_id, expires_at)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS data_exports');
    }
};
