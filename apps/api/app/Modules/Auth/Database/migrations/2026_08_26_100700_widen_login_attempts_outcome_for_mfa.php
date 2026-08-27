<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §C.7.1. Expand puro: los cuatro valores existentes se
 * conservan literalmente, con el mismo nombre de restricción. La versión
 * anterior de la aplicación nunca escribirá los dos nuevos, porque su
 * código no los produce (mismo argumento que ADR-039 §4.6, pero sobre una
 * tabla de un solo módulo, así que no hace falta ADR propio).
 *
 * `ADD CONSTRAINT ... NOT VALID` + `VALIDATE CONSTRAINT` (en vez de un
 * `ADD CONSTRAINT` completo): la primera sentencia solo toma
 * `ACCESS EXCLUSIVE` el tiempo de actualizar el catálogo, sin recorrer la
 * tabla; la segunda recorre la tabla pero con `SHARE UPDATE EXCLUSIVE`, que
 * no bloquea lecturas ni escrituras concurrentes. Con `login_attempts` con
 * volumen, un `ADD CONSTRAINT` sin `NOT VALID` bloquearía inserciones de
 * login durante todo el recorrido de validación (issue #98).
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE login_attempts DROP CONSTRAINT login_attempts_outcome_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_outcome_check
                CHECK (outcome IN (
                    'exito', 'credenciales_invalidas', 'cuenta_bloqueada', 'estado_no_activo',
                    'pendiente_segundo_factor', 'segundo_factor_invalido'
                )) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE login_attempts VALIDATE CONSTRAINT login_attempts_outcome_check');
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        // datos.md §C.10: de un solo sentido en la práctica —
        // login_attempts es append-only y no admite DELETE desde la
        // aplicación. Falla si ya existe alguna fila con los dos valores
        // nuevos, igual que ADR-039 §4.6.
        $owner->statement('ALTER TABLE login_attempts DROP CONSTRAINT login_attempts_outcome_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_outcome_check
                CHECK (outcome IN ('exito', 'credenciales_invalidas', 'cuenta_bloqueada', 'estado_no_activo'))
                NOT VALID
            SQL);
        $owner->statement('ALTER TABLE login_attempts VALIDATE CONSTRAINT login_attempts_outcome_check');
    }
};
