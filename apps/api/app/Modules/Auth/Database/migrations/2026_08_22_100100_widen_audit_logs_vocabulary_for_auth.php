<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-039 §4.1, §4.6. Amplía el vocabulario cerrado de `audit_logs.event`
 * (ADR-034 §3) con `login`, `logout` y `password_reset_requested`, y el de
 * `audit_logs.actor_type` con `anonymous` (OPEN-AUTH-12, §7 del ADR).
 *
 * Puro *expand*: dos `DROP CONSTRAINT` + `ADD CONSTRAINT` sobre la misma
 * restricción, sin tocar filas existentes. Pertenece a REQ-AUTH (quien
 * necesita los valores nuevos), no al núcleo — ADR-039 §4.6. Ejecutada con
 * la conexión `pgsql_owner` (propietaria del esquema).
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_event_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check
                CHECK (event IN (
                    'created', 'updated', 'deleted', 'restored', 'read', 'exported',
                    'login', 'logout', 'password_reset_requested'
                ))
            SQL);

        $owner->statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_actor_type_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check
                CHECK (actor_type IN ('user', 'system', 'console', 'import', 'platform', 'anonymous'))
            SQL);
    }

    /**
     * ADR-039 §4.6: en la práctica de un solo sentido. Restaura la
     * restricción de seis/cinco valores y falla si ya existe alguna fila
     * con los valores nuevos — comportamiento correcto: `audit_logs` no
     * admite `DELETE`, así que no hay forma legítima de deshacer filas ya
     * escritas con el vocabulario ampliado.
     */
    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_actor_type_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check
                CHECK (actor_type IN ('user', 'system', 'console', 'import', 'platform'))
            SQL);

        $owner->statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_event_check');
        $owner->statement(<<<'SQL'
            ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check
                CHECK (event IN ('created', 'updated', 'deleted', 'restored', 'read', 'exported'))
            SQL);
    }
};
