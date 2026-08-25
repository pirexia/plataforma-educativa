<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * datos.md §A.4, `REQ-AUTH-005` punto 1. Expand puro sobre la tabla de
 * 1.1, anticipado por REQ-CORE/funcional.md §1.4. NOT NULL DEFAULT 30 es
 * seguro en expand: la versión anterior no conoce la columna y el valor
 * por defecto rellena las filas existentes en la misma sentencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->table('tenant_settings', function (Blueprint $table): void {
            $table->integer('session_timeout_minutes')->default(30);
        });

        DB::connection('pgsql_owner')->statement(<<<'SQL'
            ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_session_timeout_minutes_check
                CHECK (session_timeout_minutes BETWEEN 5 AND 480)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement(
            'ALTER TABLE tenant_settings DROP CONSTRAINT IF EXISTS tenant_settings_session_timeout_minutes_check'
        );

        Schema::connection('pgsql_owner')->table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn('session_timeout_minutes');
        });
    }
};
