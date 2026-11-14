<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige un bug real de la migración de `1.6`
 * (`2026_09_08_100700_create_dual_authorizations_table.php`, issue #196):
 * `dual_authorizations_approved_coherence_check` exigía
 * `approved_by`/`approved_at` rellenos **solo si** `status = 'aprobada'`,
 * lo que hace imposible pasar a `ejecutada` o `fallida` conservando quién
 * aprobó — exactamente lo que hace `DualAuthorizationService::execute()`
 * al continuar escribiendo sobre la misma fila tras aprobar. `1.6` nunca
 * lo detectó porque no tenía ninguna acción real que ejecutar; `1.6b`
 * (`tenant.eliminar`) es la primera en intentarlo. `DROP CONSTRAINT` +
 * `ADD CONSTRAINT` del mismo `CHECK`, mismo precedente que la migración
 * del issue #173.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE dual_authorizations DROP CONSTRAINT dual_authorizations_approved_coherence_check');

        $owner->statement(<<<'SQL'
            ALTER TABLE dual_authorizations ADD CONSTRAINT dual_authorizations_approved_coherence_check
                CHECK (
                    (status IN ('aprobada', 'ejecutada', 'fallida'))
                    = (approved_by IS NOT NULL AND approved_at IS NOT NULL)
                )
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE dual_authorizations DROP CONSTRAINT dual_authorizations_approved_coherence_check');

        $owner->statement(<<<'SQL'
            ALTER TABLE dual_authorizations ADD CONSTRAINT dual_authorizations_approved_coherence_check
                CHECK ((status = 'aprobada') = (approved_by IS NOT NULL AND approved_at IS NOT NULL))
            SQL);
    }
};
