<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-BO-005 punto 2, `docs/modulos/REQ-BO/datos.md §6`, §6.1, §12 fila 7.
 * Migración aditiva pura: una columna anulable, sin valor por defecto,
 * `ADD COLUMN` instantáneo en PostgreSQL 17 sobre una tabla con datos.
 * Verificado el 2026-09-21: no existe todavía (datos.md §9.7).
 *
 * Marca de tiempo y no booleano: codifica a la vez "es early adopter" y
 * "desde cuándo" (datos.md §6.1). En `tenants` y no en `tenant_settings`:
 * es una designación del proveedor sobre el centro, no una preferencia
 * que el propio centro escriba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->table('tenants', function (Blueprint $table): void {
            $table->timestampTz('early_adopter_since')->nullable();
        });
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('ALTER TABLE tenants DROP COLUMN early_adopter_since');
    }
};
