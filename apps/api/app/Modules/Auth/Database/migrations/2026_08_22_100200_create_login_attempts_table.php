<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §A.1. Telemetría de intentos de acceso, append-only (sin
 * deleted_at, sin created_by/updated_by — el actor es el propio sujeto del
 * intento). Sin `public_id`: en 1.2 no se expone por ninguna API.
 *
 * Retención de 90 días (`AUTH_LOGIN_ATTEMPT_RETENTION_DAYS`, datos.md §A.9),
 * purgada físicamente con el rol propietario (`PurgeLoginAttempts`,
 * operacion.md §4) — tenantTableAppendOnly() revoca DELETE a los roles de
 * aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTableAppendOnly('login_attempts', function (Blueprint $table): void {
            $table->text('email');
            // FK compuesta opcional: NULL cuando el correo no corresponde
            // a ninguna cuenta (RN-AUTH-15, bloqueo fantasma). tenantForeignId()
            // es NOT NULL siempre (ADR-034 §4), así que se declara a mano.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('outcome');
            // Precisión de microsegundo, no el 0 (entero) por defecto de
            // timestampTz(): la consulta caliente de datos.md §A.7 ordena
            // por esta columna para contar fallos consecutivos desde el
            // último éxito, y varios intentos por segundo — el propio
            // patrón que RN-AUTH-14 quiere detectar — colisionarían en el
            // mismo segundo con precisión 0. Ver LoginAttemptRecorder::
            // consecutiveFailures(), que además desempata por `id`.
            $table->timestampTz('attempted_at', 6);
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('request_id')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_outcome_check
                CHECK (outcome IN ('exito', 'credenciales_invalidas', 'cuenta_bloqueada', 'estado_no_activo'))
            SQL);

        $owner->statement(
            'ALTER TABLE login_attempts ADD CONSTRAINT login_attempts_user_id_fkey '.
            'FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id)'
        );

        // Consulta caliente: recuento de fallos consecutivos desde el
        // último éxito, ejecutada en cada intento de login antes de
        // verificar ninguna contraseña (datos.md §A.7).
        $owner->statement(
            'CREATE INDEX login_attempts_tenant_email_attempted_idx ON login_attempts (tenant_id, email, attempted_at DESC)'
        );
        $owner->statement(
            'CREATE INDEX login_attempts_tenant_attempted_idx ON login_attempts (tenant_id, attempted_at DESC)'
        );
        $owner->statement(
            'CREATE INDEX login_attempts_tenant_user_attempted_idx ON login_attempts (tenant_id, user_id, attempted_at DESC)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS login_attempts');
    }
};
