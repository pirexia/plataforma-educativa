<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §F.5, §F.7 (REQ-AUTH-004, 1.4b). Un valor más en el `CHECK` de
 * `method`: `'sso'`, único para todo el SSO institucional y no el
 * identificador del proveedor concreto (`§F.5`: `user_identities.
 * identity_provider_id` ya responde "por cuál de los dos IdP del centro").
 *
 * `NOT VALID` + `VALIDATE CONSTRAINT`, tercera vez que este módulo deja
 * la misma nota (`§C.10`, `§E.7`): es la tabla más grande del módulo, y
 * este patrón no bloquea inserciones de login durante el recorrido de
 * validación.
 *
 * `outcome` no gana ningún valor: los siete de 1.4 cubren el camino
 * institucional sin excepción (§F.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE login_attempts DROP CONSTRAINT login_attempts_method_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_method_check
                CHECK (method IN ('local', 'google', 'sso')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE login_attempts VALIDATE CONSTRAINT login_attempts_method_check');
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        // append-only: de un solo sentido en la práctica. Falla si ya
        // existe alguna fila con method = 'sso'.
        $owner->statement('ALTER TABLE login_attempts DROP CONSTRAINT login_attempts_method_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_method_check
                CHECK (method IN ('local', 'google')) NOT VALID
            SQL);
        $owner->statement('ALTER TABLE login_attempts VALIDATE CONSTRAINT login_attempts_method_check');
    }
};
