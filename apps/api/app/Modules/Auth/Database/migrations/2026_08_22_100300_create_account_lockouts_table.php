<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §A.2. Tabla de tenant ordinaria (`public_id` porque se expone
 * en `GET /account-lockouts` y `DELETE /account-lockouts/{public_id}`).
 * La fila se conserva tras el desbloqueo (RN-AUTH-18): es traza, igual que
 * una invitación revocada.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('account_lockouts', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('email');
            // FK compuesta opcional: NULL en el bloqueo fantasma (RN-AUTH-15).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->smallInteger('failed_count');
            $table->timestampTz('locked_at');
            $table->text('unlock_token_hash')->nullable();
            $table->timestampTz('unlock_token_expires_at')->nullable();
            $table->timestampTz('unlocked_at')->nullable();
            // FK compuesta opcional → users. NULL si el desbloqueo fue por
            // correo del propio titular; informado si fue un administrador.
            $table->unsignedBigInteger('unlocked_by')->nullable();
            // Hallazgo propio (severidad Alta, ver entrega final de la
            // sesión): datos.md §A.2 no listaba esta columna pese a que
            // RN-AUTH-14/18 y CA-AUTH-023/024/029/030 exigen distinguir
            // los tres motivos de desbloqueo, y `unlocked_by` por sí solo
            // no permite distinguir 'caducidad' de 'correo' (ambos con
            // unlocked_by NULL). Añadida para que esos criterios sean
            // verificables — expand puro, no cambia ninguna decisión ya
            // tomada.
            $table->text('unlock_reason')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(
            'ALTER TABLE account_lockouts ADD CONSTRAINT account_lockouts_user_id_fkey '.
            'FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id)'
        );
        $owner->statement(
            'ALTER TABLE account_lockouts ADD CONSTRAINT account_lockouts_unlocked_by_fkey '.
            'FOREIGN KEY (tenant_id, unlocked_by) REFERENCES users (tenant_id, id)'
        );

        // RN-AUTH-17: un solo bloqueo vivo por correo y tenant, garantizado
        // por índice (no por comprobación de aplicación) — parcial también
        // por deleted_at, mismo patrón que user_invitations en 1.1.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX account_lockouts_tenant_email_live_unique
                ON account_lockouts (tenant_id, email)
                WHERE unlocked_at IS NULL AND deleted_at IS NULL
            SQL);

        // Total, no parcial: un token de desbloqueo no se reutiliza jamás.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX account_lockouts_tenant_unlock_token_hash_unique
                ON account_lockouts (tenant_id, unlock_token_hash)
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE account_lockouts ADD CONSTRAINT account_lockouts_unlocked_by_requires_unlocked_at_check
                CHECK (unlocked_by IS NULL OR unlocked_at IS NOT NULL)
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE account_lockouts ADD CONSTRAINT account_lockouts_unlock_reason_check
                CHECK (unlock_reason IN ('caducidad', 'correo', 'administrador'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE account_lockouts ADD CONSTRAINT account_lockouts_unlock_reason_requires_unlocked_at_check
                CHECK (unlock_reason IS NULL OR unlocked_at IS NOT NULL)
            SQL);

        $owner->statement(
            'CREATE INDEX account_lockouts_tenant_locked_at_idx ON account_lockouts (tenant_id, locked_at DESC)'
        );
        $owner->statement(
            'CREATE INDEX account_lockouts_tenant_unlock_expires_idx ON account_lockouts (tenant_id, unlock_token_expires_at)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS account_lockouts');
    }
};
