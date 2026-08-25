<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §B.1 (REQ-AUTH-005 punto 4, 1.2b). Tabla de tenant ordinaria
 * (no append-only: `last_seen_at`/`login_count`/`alerted_at` se actualizan
 * en cada login desde el dispositivo). Se crea antes que `user_sessions`,
 * que la referencia por FK compuesta opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('user_known_devices', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            // RN-AUTH-45, RN-AUTH-09: solo el hash. El valor en claro de la
            // cookie pge_device no está en base de datos. El nombre de la
            // columna (con "token" dentro) dispara la redacción automática
            // por el patrón global de config('audit.secret_attribute_patterns').
            $table->text('device_token_hash');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->integer('login_count')->default(1);
            $table->text('label')->nullable();
            $table->ipAddress('last_ip_address')->nullable();
            $table->timestampTz('alerted_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // §B.1: parcial, no total. A diferencia de los hashes de token de
        // un solo uso (§A.2), un dispositivo sí puede volver: si se
        // "olvida" (borrado lógico) y la misma cookie reaparece, tiene que
        // poder registrarse otra vez y disparar su aviso, no chocar contra
        // el índice.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_known_devices_tenant_user_hash_live_unique
                ON user_known_devices (tenant_id, user_id, device_token_hash)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(
            'ALTER TABLE user_known_devices ADD CONSTRAINT user_known_devices_login_count_check '.
            'CHECK (login_count >= 1)'
        );

        // Consulta caliente: en cada login con cookie presente se busca por
        // (tenant_id, user_id, device_token_hash) — servida entera por el
        // índice único parcial de arriba, sin índice adicional para ella.
        $owner->statement(
            'CREATE INDEX user_known_devices_tenant_user_last_seen_idx ON user_known_devices (tenant_id, user_id, last_seen_at DESC)'
        );
        // Purga por antigüedad (§B.7, PurgeUserKnownDevices).
        $owner->statement(
            'CREATE INDEX user_known_devices_tenant_last_seen_idx ON user_known_devices (tenant_id, last_seen_at)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS user_known_devices');
    }
};
