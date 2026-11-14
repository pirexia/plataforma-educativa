<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §B.2 (REQ-AUTH-005 puntos 2-3, 1.2b). Tabla de tenant ordinaria,
 * complementaria de la `sessions` del framework y deliberadamente separada
 * de ella (funcional.md §B.2.2): el identificador de sesión es una
 * credencial portadora, el `public_id` es su nombre público, y las dos
 * cosas no van en la misma fila.
 *
 * `session_id` no lleva clave foránea a `sessions` — no puede llevarla
 * (datos.md §B.4): esa tabla no tiene (tenant_id, id), el orden de
 * escritura lo impide (esta fila se crea dentro de la transacción del
 * login, la de `sessions` la escribe StartSession al terminar la
 * petición) y su recolector la borra por su cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('user_sessions', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->text('session_id');
            $table->timestampTz('started_at');
            $table->ipAddress('ip_address')->nullable();
            // Truncada a AUTH_USER_AGENT_MAX_LENGTH (1024) antes de
            // persistir — datos.md §B.2, para que una cabecera hostil no
            // entre tal cual en una tabla de tenant.
            $table->text('user_agent')->nullable();
            $table->text('client_browser')->nullable();
            $table->text('client_platform')->nullable();
            $table->text('client_device_type')->nullable();
            // Siempre NULL en 1.2b (RN-AUTH-47, OPEN-AUTH-13): el hueco del
            // requisito, escrito en el esquema para que se vea que está a
            // medias.
            $table->text('location_label')->nullable();
            // FK compuesta opcional, declarada a mano (tenantForeignId() es
            // NOT NULL siempre, ADR-034 §4). NULL cuando el navegador no
            // admitió la cookie pge_device.
            $table->unsignedBigInteger('known_device_id')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->text('end_reason')->nullable();
            // FK compuesta opcional → users. NULL en los cierres automáticos.
            $table->unsignedBigInteger('ended_by')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(
            'ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_known_device_id_fkey '.
            'FOREIGN KEY (tenant_id, known_device_id) REFERENCES user_known_devices (tenant_id, id)'
        );
        $owner->statement(
            'ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_ended_by_fkey '.
            'FOREIGN KEY (tenant_id, ended_by) REFERENCES users (tenant_id, id)'
        );

        // RN-AUTH-39: una sola fila viva por sesión, garantizado por
        // índice, no por comprobación de aplicación.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_sessions_tenant_session_live_unique
                ON user_sessions (tenant_id, session_id)
                WHERE ended_at IS NULL AND deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_ended_at_reason_check
                CHECK ((ended_at IS NULL) = (end_reason IS NULL))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_ended_by_requires_ended_at_check
                CHECK (ended_by IS NULL OR ended_at IS NOT NULL)
            SQL);
        // Las siete razones de funcional.md §B.4.6, y ni una de más
        // (issue #61).
        $owner->statement(<<<'SQL'
            ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_end_reason_check
                CHECK (end_reason IN (
                    'logout', 'revocada_usuario', 'inactividad', 'caducidad',
                    'cambio_credencial', 'baja_usuario', 'tenant_incoherente'
                ))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_sessions ADD CONSTRAINT user_sessions_client_device_type_check
                CHECK (client_device_type IN ('escritorio', 'movil', 'tableta', 'bot', 'desconocido'))
            SQL);

        // La consulta del panel (GET /auth/sessions, api.md §B.2).
        $owner->statement(<<<'SQL'
            CREATE INDEX user_sessions_tenant_user_started_idx
                ON user_sessions (tenant_id, user_id, started_at DESC)
                WHERE ended_at IS NULL AND deleted_at IS NULL
            SQL);
        // Purga por retención (§B.7) y CloseOrphanedUserSessions.
        $owner->statement(
            'CREATE INDEX user_sessions_tenant_ended_idx ON user_sessions (tenant_id, ended_at)'
        );
        $owner->statement(
            'CREATE INDEX user_sessions_tenant_known_device_idx ON user_sessions (tenant_id, known_device_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS user_sessions');
    }
};
