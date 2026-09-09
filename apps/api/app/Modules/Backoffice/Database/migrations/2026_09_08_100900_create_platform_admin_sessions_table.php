<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * ADR-047 §5.1, datos.md §2.7. El equivalente de `user_sessions` para el
 * backoffice: dato de negocio (qué sesiones hay abiertas, desde dónde,
 * por qué terminó cada una), distinto de `platform_sessions` (el
 * almacén del driver, cuya forma no es nuestra).
 *
 * `session_id` **no** lleva clave foránea a `platform_sessions.id`
 * (ADR-047 §5.1, §2.7.1): la fila de esta tabla se escribe dentro de una
 * transacción durante la petición, con el identificador que
 * `session()->getId()` ya conoce tras `regenerate()` — pero la fila de
 * `platform_sessions` la escribe el driver al FINAL de la petición. Una
 * FK fallaría en cada inicio de sesión. Lo que sustituye a la clave
 * foránea es el barrido de sesiones huérfanas de `operacion.md §6.3`
 * (`CloseOrphanedPlatformSessions`, precedente exacto de
 * `CloseOrphanedUserSessions`).
 *
 * No lleva `public_id`: en 1.6 no se direcciona por URL (datos.md
 * §2.7). No lleva `deleted_at`: una sesión no se borra lógicamente,
 * termina — lo dicen `ended_at` y `end_reason`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('platform_admin_sessions', function (Blueprint $table): void {
            $table->foreignId('platform_admin_id')->constrained('platform_admins');
            $table->text('session_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('reauthenticated_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->text('end_reason')->nullable();
            $table->timestampsTz();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE platform_admin_sessions ADD CONSTRAINT platform_admin_sessions_end_reason_check
                CHECK (end_reason IS NULL OR end_reason IN (
                    'cierre_usuario', 'caducidad', 'revocada_admin', 'admin_suspendido', 'mfa_restablecido'
                ))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE platform_admin_sessions ADD CONSTRAINT platform_admin_sessions_ended_matches_reason_check
                CHECK ((ended_at IS NULL) = (end_reason IS NULL))
            SQL);

        // "Qué sesiones tiene abiertas esta persona": la consulta de la
        // revocación.
        $owner->statement(<<<'SQL'
            CREATE INDEX platform_admin_sessions_admin_started_live_idx
                ON platform_admin_sessions (platform_admin_id, started_at DESC)
                WHERE ended_at IS NULL
            SQL);
        $owner->statement(<<<'SQL'
            CREATE INDEX platform_admin_sessions_session_id_idx
                ON platform_admin_sessions (session_id)
                WHERE session_id IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_admin_sessions');
    }
};
