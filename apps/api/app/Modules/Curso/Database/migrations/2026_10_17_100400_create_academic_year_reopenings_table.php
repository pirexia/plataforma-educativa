<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CURSO/datos.md §1.5 (1.11c, `ADR-059 §5.4`, `RN-CURSO-43`). Registro
 * *append-only* de cada reapertura de un curso cerrado: quién, cuándo y por
 * qué, sin leer `audit_logs`. Precedente: `mfa_resets`.
 *
 * `tenantTableAppendOnly()` da RLS `ENABLE`+`FORCE`, `UNIQUE (tenant_id, id)`
 * y `REVOKE UPDATE, DELETE`; por llevar `academic_year_id` engancha también el
 * disparador `academic_year_write_guard`, sin excepción en `AR-13` (es dato
 * del curso). Ambas FK compuestas son `NO ACTION` (`AR-13` prohíbe las
 * acciones referenciales en una tabla con `academic_year_id`).
 *
 * `created_by` es `NOT NULL`: el único camino de alta es el *endpoint*
 * autenticado (`datos.md §1.5`, nota), igual que `mfa_resets.performed_by`.
 * No se propone `CHECK` de motivo no vacío: un único escritor y la validación
 * de servidor basta (`INV-010`).
 *
 * Tabla nueva y vacía: no hay nada que validar bajo bloqueo. No altera
 * `academic_years` ni `app.assert_academic_year_writable()` (`RN-CURSO-46`).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTableAppendOnly('academic_year_reopenings', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->text('reason');
            TenantMigration::tenantForeignId($table, 'created_by', 'users');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS academic_year_reopenings');
    }
};
