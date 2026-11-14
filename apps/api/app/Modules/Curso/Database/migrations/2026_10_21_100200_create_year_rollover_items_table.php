<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CURSO/datos.md §1.7 (1.12b, `REQ-CURSO-002`, `RN-CURSO-73`, `-75`). Qué filas creó
 * cada copia, para poder revertirla. *Append-only*. Polimórfica **sin FK** hacia las tablas
 * copiadas (patrón de `audit_logs`: `entity_type` + `target_id`): `curso` guarda lo que le
 * devuelve cada participante y se lo devuelve al revertir; nunca lee ni escribe esas tablas
 * (`INV-007`).
 *
 * `tenantTableAppendOnly()` da RLS `ENABLE`+`FORCE`, `UNIQUE (tenant_id, id)` y
 * `REVOKE UPDATE, DELETE`, y engancha el disparador `academic_year_write_guard` por llevar
 * `academic_year_id` (el curso destino). No crea marcas de tiempo ni autoría: `created_at` y
 * `created_by` se declaran aquí (revisión de documentación M-4); `created_by` es NOT NULL
 * (el solicitante de la copia, `RN-CURSO-83`), con la misma forma que
 * `academic_year_reopenings`. `public_id` también a mano. La FK de tres columnas a
 * `year_rollovers` va con nombre explícito. Ninguna acción referencial (`AR-13`).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTableAppendOnly('year_rollover_items', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            // `withIndex: false`: el índice de tres columnas de abajo ya cubre el prefijo `(tenant_id, academic_year_id)`.
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years', withIndex: false);
            $table->foreignId('year_rollover_id');
            $table->text('module_code');
            $table->text('entity_type');
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');
            $table->timestampTz('created_at');
            TenantMigration::tenantForeignId($table, 'created_by', 'users');

            $table->foreign(['tenant_id', 'academic_year_id', 'year_rollover_id'], 'year_rollover_items_tenant_year_rollover_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('year_rollovers');

            // Existe por la FK de tres columnas: `AR-16` (cobertura de toda FK compuesta, #407/#412) exige un índice
            // no parcial con exactamente sus columnas. No es por la lectura al revertir (`WHERE year_rollover_id`),
            // que también lo aprovecha pero no lo motiva. No parcial.
            $table->index(['tenant_id', 'academic_year_id', 'year_rollover_id'], 'year_rollover_items_tenant_year_rollover_idx');

            // Una fila creada no se anota dos veces en la misma copia.
            $table->unique(['tenant_id', 'year_rollover_id', 'entity_type', 'target_id'], 'year_rollover_items_target_unique');
        });
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS year_rollover_items');
    }
};
