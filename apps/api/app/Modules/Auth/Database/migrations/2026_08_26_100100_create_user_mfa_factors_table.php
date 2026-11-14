<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §C.2 (REQ-AUTH-003, 1.3). Un solo tipo de fila para el alta
 * provisional y para el factor confirmado (`confirmed_at NULL` mientras
 * está a medias) — el índice único parcial de abajo es lo que hace esto
 * seguro: solo se aplica a las filas confirmadas.
 *
 * `secret_encrypted` usa el cast `encrypted` del framework (RN-AUTH-55,
 * ADR-029/APP_KEY): la columna es `text` porque el valor cifrado no tiene
 * un tamaño fijo. Es la primera columna cifrada en reposo del producto
 * (permisos.md §C.9, datos.md §C.11.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('user_mfa_factors', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            $table->text('method');
            $table->text('secret_encrypted')->nullable();
            $table->bigInteger('last_used_step')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->smallInteger('confirmation_attempts')->default(0);
            $table->timestampTz('last_used_at')->nullable();
            $table->boolean('is_preferred')->default(false);
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX user_mfa_factors_tenant_user_method_confirmed_unique
                ON user_mfa_factors (tenant_id, user_id, method)
                WHERE confirmed_at IS NOT NULL AND deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_method_check
                CHECK (method IN ('totp', 'email', 'sms'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_secret_matches_method_check
                CHECK ((method = 'totp') = (secret_encrypted IS NOT NULL))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_last_used_step_only_totp_check
                CHECK (last_used_step IS NULL OR method = 'totp')
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_confirmed_or_expires_check
                CHECK (confirmed_at IS NOT NULL OR expires_at IS NOT NULL)
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_confirmed_xor_expires_check
                CHECK ((confirmed_at IS NULL) OR (expires_at IS NULL))
            SQL);

        // La consulta caliente: "¿tiene este usuario factor utilizable?",
        // en cada login y en cada evaluación de MfaPolicy.
        $owner->statement(<<<'SQL'
            CREATE INDEX user_mfa_factors_tenant_user_confirmed_idx
                ON user_mfa_factors (tenant_id, user_id)
                WHERE confirmed_at IS NOT NULL AND deleted_at IS NULL
            SQL);
        // Purga de altas caducadas (PurgeMfaEnrollments, operacion.md §C.4).
        $owner->statement(<<<'SQL'
            CREATE INDEX user_mfa_factors_tenant_expires_idx
                ON user_mfa_factors (tenant_id, expires_at)
                WHERE confirmed_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS user_mfa_factors');
    }
};
