<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * datos.md §C.7.2, RN-AUTH-69. Expand puro: `NOT NULL DEFAULT` es seguro
 * porque la versión anterior no conoce las columnas y el valor por
 * defecto rellena las filas existentes en la misma sentencia (mismo
 * argumento que §A.4). El `CHECK` implementa RN-AUTH-69 en el motor: array
 * no vacío, `totp` siempre presente, `sms` nunca (restricción temporal
 * por diseño, funcional.md §C.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->table('tenant_settings', function (Blueprint $table): void {
            $table->jsonb('mfa_allowed_methods')->default('["totp"]');
            $table->integer('mfa_grace_period_days')->default(7);
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_mfa_allowed_methods_check
                CHECK (
                    jsonb_typeof(mfa_allowed_methods) = 'array'
                    AND jsonb_array_length(mfa_allowed_methods) > 0
                    AND mfa_allowed_methods @> '["totp"]'::jsonb
                    AND NOT (mfa_allowed_methods @> '["sms"]'::jsonb)
                )
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_mfa_grace_period_days_check
                CHECK (mfa_grace_period_days BETWEEN 1 AND 90)
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE tenant_settings DROP CONSTRAINT IF EXISTS tenant_settings_mfa_allowed_methods_check');
        $owner->statement('ALTER TABLE tenant_settings DROP CONSTRAINT IF EXISTS tenant_settings_mfa_grace_period_days_check');

        Schema::connection('pgsql_owner')->table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn(['mfa_allowed_methods', 'mfa_grace_period_days']);
        });
    }
};
