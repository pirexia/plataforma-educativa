<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CORE-003, paso 1.9b (S2, datos.md Parte D). Expand puro: el valor
 * existente (`audit_logs`) se conserva con el mismo nombre de restricción
 * y se añade `users`. La versión anterior de la aplicación nunca escribe
 * `users`, así que convive con el esquema nuevo; sin *contract* posterior.
 *
 * Patrón de la skill `migracion-segura` (issues #98/#166): DROP + `ADD
 * CONSTRAINT ... NOT VALID` + `VALIDATE CONSTRAINT` en sentencia aparte, y
 * `$withinTransaction = false` para que el `ACCESS EXCLUSIVE` de la
 * segunda sentencia no se mantenga durante el recorrido de la tercera.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE data_exports DROP CONSTRAINT data_exports_kind_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE data_exports ADD CONSTRAINT data_exports_kind_check
                CHECK (kind IN ('audit_logs', 'users')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE data_exports VALIDATE CONSTRAINT data_exports_kind_check');
    }

    public function down(): void
    {
        // Falla si ya existe alguna fila con kind = 'users' (datos.md Parte D):
        // hay que purgar antes sus filas y objetos, a mano.
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE data_exports DROP CONSTRAINT data_exports_kind_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE data_exports ADD CONSTRAINT data_exports_kind_check
                CHECK (kind IN ('audit_logs')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE data_exports VALIDATE CONSTRAINT data_exports_kind_check');
    }
};
