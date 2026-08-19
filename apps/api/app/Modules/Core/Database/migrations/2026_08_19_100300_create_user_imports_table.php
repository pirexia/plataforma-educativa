<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CORE-003 (paso 1.1). datos.md §A.3: sin idempotency_key propia (va
 * en la tabla transversal idempotency_keys, §A.5), sin tabla de filas
 * (error_summary + informe CSV cubren el caso de uso real).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('user_imports', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('original_filename');
            $table->text('source_object_key')->nullable();
            $table->text('report_object_key')->nullable();
            $table->text('status')->default('subido');
            $table->integer('row_count')->nullable();
            $table->integer('error_count')->nullable();
            $table->integer('created_count')->nullable();
            $table->jsonb('error_summary')->nullable();
            $table->boolean('send_invitations')->default(true);
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE user_imports ADD CONSTRAINT user_imports_status_check
                CHECK (status IN ('subido', 'validando', 'validado', 'fallido', 'ejecutando', 'completado'))
            SQL);

        $owner->statement(
            'CREATE INDEX user_imports_tenant_created_idx ON user_imports (tenant_id, created_at DESC)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS user_imports');
    }
};
