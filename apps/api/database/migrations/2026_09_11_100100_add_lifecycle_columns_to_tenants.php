<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-BO-001, `docs/modulos/REQ-BO/datos.md §6`, §12 fila 4. Migración
 * aditiva pura: cuatro columnas anulables, sin valor por defecto,
 * `ADD COLUMN` instantáneo en PostgreSQL 17 sobre una tabla con datos.
 * Verificado el 2026-09-11: ninguna de las cuatro existe todavía.
 *
 * `suspended_at` y `grace_period_ends_at` son redundantes con
 * `tenant_lifecycle_events`, a propósito: `ResolveTenant` las lee en cada
 * petición y no puede permitirse un JOIN con una tabla de historial en el
 * camino más caliente del sistema (datos.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->table('tenants', function (Blueprint $table): void {
            $table->text('suspension_message')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('grace_period_ends_at')->nullable();
            $table->timestampTz('grace_period_expired_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement(
            'ALTER TABLE tenants DROP COLUMN suspension_message, '.
            'DROP COLUMN suspended_at, '.
            'DROP COLUMN grace_period_ends_at, '.
            'DROP COLUMN grace_period_expired_at'
        );
    }
};
