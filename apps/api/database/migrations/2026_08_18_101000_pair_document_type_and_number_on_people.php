<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hallazgo de `db-reviewer` tras 0.8.3 (severidad Media, issue #20): el
 * índice único parcial `people_tenant_document_unique` cubre
 * `(tenant_id, document_type, document_number) WHERE document_number IS NOT
 * NULL`, pero `document_type` es nullable de forma independiente. PostgreSQL
 * trata cada NULL como distinto dentro de un índice único (sin
 * `NULLS NOT DISTINCT`), así que dos personas del mismo tenant con el mismo
 * `document_number` y `document_type` sin informar no chocaban contra la
 * restricción — el "documento único por tenant" que promete ADR-034 §1
 * quedaba roto justo en el caso límite que la propia migración original
 * decía cubrir ("documento y tipo son ambos nullable").
 *
 * Se corrige con un CHECK que ata la nulabilidad de las dos columnas: o
 * las dos están informadas, o ninguna. Con eso, `document_number IS NOT
 * NULL` implica `document_type IS NOT NULL`, así que el índice único
 * parcial ya no puede toparse con dos NULL de `document_type` mientras
 * `document_number` esté informado. Severidad Media, sin impacto en datos
 * reales (fase 0, `ADR-030`); corregido en la misma sesión por no
 * descarrilar el objetivo (`CLAUDE.md` §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->statement(<<<'SQL'
            ALTER TABLE people
                ADD CONSTRAINT people_document_type_number_paired
                CHECK ((document_type IS NULL) = (document_number IS NULL))
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement(
            'ALTER TABLE people DROP CONSTRAINT IF EXISTS people_document_type_number_paired'
        );
    }
};
