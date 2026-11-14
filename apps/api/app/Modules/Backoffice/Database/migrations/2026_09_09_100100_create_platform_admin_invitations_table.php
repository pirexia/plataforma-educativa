<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Issue #173, `docs/modulos/REQ-BO/operacion.md §5` pasos 4 y 6. Sigue el
 * precedente de forma de `user_invitations` (`REQ-CORE`, 1.1) — token de
 * un solo uso, sólo su hash se persiste (mismo criterio que RN-CORE-19,
 * sin equivalente numerado propio de `REQ-BO`) — pero es tabla de
 * PLATAFORMA, no de tenant: `platform_admins` no tiene tenant (RN-BO-01),
 * así que esta tabla tampoco.
 *
 * A diferencia de `user_invitations`, no hay estado `pendiente` que
 * comprobar en `platform_admins` (`status` sólo admite `activo` y
 * `suspendido`, datos.md §2.1): la vigencia de la invitación la decide
 * únicamente esta tabla (`accepted_at`, `revoked_at`, `expires_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('platform_admin_invitations', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->text('token_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        $owner = DB::connection('pgsql_owner');

        // Búsqueda del canje: por hash, global (sin tenant que la acote).
        // Un token no se reutiliza jamás.
        $owner->statement(
            'CREATE UNIQUE INDEX platform_admin_invitations_token_unique ON platform_admin_invitations (token_hash)'
        );

        // Una sola invitación viva por administrador, mismo criterio que
        // RN-CORE-09 en user_invitations.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX platform_admin_invitations_admin_live_unique
                ON platform_admin_invitations (platform_admin_id)
                WHERE accepted_at IS NULL AND revoked_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_admin_invitations');
    }
};
