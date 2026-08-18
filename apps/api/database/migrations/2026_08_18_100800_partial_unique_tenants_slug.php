<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hallazgo propio durante 0.8.10, al escribir el test de esquema que
 * generaliza ADR-034 §6 ("toda restricción única sobre una tabla con
 * borrado lógico es parcial, salvo (tenant_id, id) y public_id") a todas
 * las tablas, no solo a las nuevas de 0.8: `tenants.slug` es la única
 * tabla ya cerrada en 0.7 que la incumplía. `tenants_slug_unique` no
 * parcial bloqueaba el subdominio de un tenant dado de baja para siempre
 * — un slug nunca se libera aunque el centro se elimine. Severidad Media
 * (incumplimiento de invariante sin impacto inmediato, con rodeo
 * disponible: elegir otro slug); corregido en la misma sesión por no
 * descarrilar el objetivo (`CLAUDE.md` §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE tenants DROP CONSTRAINT tenants_slug_unique');

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX tenants_slug_unique
                ON tenants (slug)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP INDEX IF EXISTS tenants_slug_unique');
        $owner->statement('ALTER TABLE tenants ADD CONSTRAINT tenants_slug_unique UNIQUE (slug)');
    }
};
