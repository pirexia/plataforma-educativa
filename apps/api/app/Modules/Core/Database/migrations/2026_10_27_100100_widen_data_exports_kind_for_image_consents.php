<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/api.md §4 (1.14-b, `OPEN-FAM-UNIT-11`): el informe de consentimientos de imagen es un `kind` nuevo de
 * la primitiva `data_exports`. Expand puro, como `widen_data_exports_kind_for_users` (1.9b): los valores existentes
 * se conservan con el mismo nombre de restricción y se añade `image_consents`; la versión anterior de la aplicación
 * nunca lo escribe, así que convive con el esquema nuevo. Sin *contract* posterior.
 *
 * Patrón de la skill `migracion-segura` (issues #98/#166): DROP + `ADD CONSTRAINT ... NOT VALID` + `VALIDATE CONSTRAINT`
 * en sentencia aparte, y `$withinTransaction = false` para que el `ACCESS EXCLUSIVE` de la segunda sentencia no se
 * mantenga durante el recorrido de la tercera. La tabla `data_exports` ya puede tener filas en producción.
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
                CHECK (kind IN ('audit_logs', 'users', 'image_consents')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE data_exports VALIDATE CONSTRAINT data_exports_kind_check');
    }

    public function down(): void
    {
        // Falla si ya existe alguna fila con kind = 'image_consents': hay que purgar antes sus filas y objetos, a mano.
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE data_exports DROP CONSTRAINT data_exports_kind_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE data_exports ADD CONSTRAINT data_exports_kind_check
                CHECK (kind IN ('audit_logs', 'users')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE data_exports VALIDATE CONSTRAINT data_exports_kind_check');
    }
};
