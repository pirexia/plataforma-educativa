<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §C.6.1. Traza *append-only* del restablecimiento de MFA por el
 * administrador (RN-AUTH-66). `reason` vive íntegro en su propia columna
 * (nunca redactado por `ADR-035`, a diferencia de `audit_logs.changes`),
 * porque es exactamente la información que `REQ-AUTH-003` exige
 * conservar. Sin purga: su única salida es el flujo de supresión de la
 * persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTableAppendOnly('mfa_resets', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->text('reason');
            $table->smallInteger('factors_removed');
            TenantMigration::tenantForeignId($table, 'performed_by', 'users');
            $table->timestampTz('performed_at');
            $table->text('request_id')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(
            'CREATE INDEX mfa_resets_tenant_user_performed_idx ON mfa_resets (tenant_id, user_id, performed_at DESC)'
        );
        $owner->statement(
            'CREATE INDEX mfa_resets_tenant_performed_idx ON mfa_resets (tenant_id, performed_at DESC)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS mfa_resets');
    }
};
