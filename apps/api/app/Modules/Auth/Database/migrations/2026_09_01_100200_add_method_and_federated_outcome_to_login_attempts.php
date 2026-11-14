<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * datos.md §E.3 (REQ-AUTH-002, 1.4). Dos cambios *expand* puros sobre la
 * tabla más grande del módulo:
 *
 * 1. `method` con `DEFAULT 'local'` — no reescribe la tabla en
 *    PostgreSQL 11+, y clasifica correctamente las filas existentes sin
 *    tocarlas: todas ellas SON locales.
 * 2. Un séptimo valor de `outcome` (`federado_sin_vinculo`), con el mismo
 *    patrón `NOT VALID` + `VALIDATE CONSTRAINT` que `§C.10`
 *    (2026_08_26_100700) y por el mismo motivo: sin bloquear inserciones
 *    de login durante el recorrido de validación (issue #98).
 *
 * La versión anterior de la aplicación sigue funcionando contra el
 * esquema nuevo: no conoce `method` (queda con su `DEFAULT`) y no produce
 * `federado_sin_vinculo` porque su código no lo genera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->table('login_attempts', function (Blueprint $table): void {
            $table->text('method')->default('local')->after('outcome');
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_method_check
                CHECK (method IN ('local', 'google')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE login_attempts VALIDATE CONSTRAINT login_attempts_method_check');

        $owner->statement('ALTER TABLE login_attempts DROP CONSTRAINT login_attempts_outcome_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_outcome_check
                CHECK (outcome IN (
                    'exito', 'credenciales_invalidas', 'cuenta_bloqueada', 'estado_no_activo',
                    'pendiente_segundo_factor', 'segundo_factor_invalido', 'federado_sin_vinculo'
                )) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE login_attempts VALIDATE CONSTRAINT login_attempts_outcome_check');
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        // datos.md §E.7: de un solo sentido en la práctica —
        // login_attempts es append-only. Falla si ya existe alguna fila
        // con method = 'google' o outcome = 'federado_sin_vinculo'.
        $owner->statement('ALTER TABLE login_attempts DROP CONSTRAINT login_attempts_outcome_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_outcome_check
                CHECK (outcome IN (
                    'exito', 'credenciales_invalidas', 'cuenta_bloqueada', 'estado_no_activo',
                    'pendiente_segundo_factor', 'segundo_factor_invalido'
                )) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE login_attempts VALIDATE CONSTRAINT login_attempts_outcome_check');

        $owner->statement('ALTER TABLE login_attempts DROP CONSTRAINT login_attempts_method_check');

        Schema::connection('pgsql_owner')->table('login_attempts', function (Blueprint $table): void {
            $table->dropColumn('method');
        });
    }
};
