<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #46 (Baja, informada al usuario en la especificación de 1.1, no
 * resuelta en silencio — CLAUDE.md §5). `people.locale` se creó en 0.8.3
 * con valor por defecto 'es', mientras ADR-021 y REQ-CORE-006 nombran el
 * idioma por defecto como 'es-ES', y es lo que exige el CHECK de
 * tenant_settings.default_locale. Se unifica antes de que exista ningún
 * dato real (ADR-030, fase 0/1 sin piloto todavía): expand puro, sin
 * ventana de mantenimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement("ALTER TABLE people ALTER COLUMN locale SET DEFAULT 'es-ES'");
        $owner->statement("UPDATE people SET locale = 'es-ES' WHERE locale = 'es'");
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement("UPDATE people SET locale = 'es' WHERE locale = 'es-ES'");
        $owner->statement("ALTER TABLE people ALTER COLUMN locale SET DEFAULT 'es'");
    }
};
