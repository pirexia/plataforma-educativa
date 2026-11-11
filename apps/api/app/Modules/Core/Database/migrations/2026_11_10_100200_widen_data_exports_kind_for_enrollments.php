<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM/api.md (1.15-b, `CA-ALUM-061`): la lista de clase y la exportación de matrículas es un `kind` nuevo de
 * la primitiva `data_exports`. Expand puro, como `widen_data_exports_kind_for_image_consents` (1.14-b): los valores existentes
 * se conservan con el mismo nombre de restricción y se añade `alum_enrollments`; la versión anterior de la aplicación
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
                CHECK (kind IN ('audit_logs', 'users', 'image_consents', 'alum_enrollments')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE data_exports VALIDATE CONSTRAINT data_exports_kind_check');
    }

    public function down(): void
    {
        // Falla si ya existe alguna fila con kind = 'alum_enrollments': hay que purgar antes sus filas y objetos, a mano.
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE data_exports DROP CONSTRAINT data_exports_kind_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE data_exports ADD CONSTRAINT data_exports_kind_check
                CHECK (kind IN ('audit_logs', 'users', 'image_consents')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE data_exports VALIDATE CONSTRAINT data_exports_kind_check');
    }
};
