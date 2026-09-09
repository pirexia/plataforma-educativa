<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-BO-007, datos.md §2.3, §2.4. Tres tablas propias de MFA de
 * plataforma: se reutiliza el mecanismo (MfaVerifier, TotpProvisioner,
 * ADR-041) pero no el almacenamiento de 1.3 — las seis tablas `mfa_*` son
 * de tenant y esta identidad no lo es (datos.md §2.3).
 *
 * `type` admite un solo valor hoy (`totp`): el correo **no** es segundo
 * factor en el backoffice (datos.md §2.4) — es un canal fuera de nuestro
 * control y la cuenta que protege puede eliminar cualquier centro. El
 * CHECK se puede ampliar por migración aditiva si algún día se decide
 * otra cosa.
 *
 * **Desviación deliberada de la lista literal de columnas de datos.md
 * §2.3, para que la revise `db-reviewer`**: añade `last_used_step`, que
 * esa lista no enumera. `datos.md §2.3` dice "mismo diseño que sus
 * homólogas de 1.3" y `funcional.md §2.3` dice explícitamente "qué sí se
 * reutiliza: el mecanismo" (`MfaVerifier`, `ADR-041`) — y ese mecanismo
 * exige `last_used_step` para RN-AUTH-58 (rechazar la reutilización del
 * mismo paso de 30s dentro de la ventana de tolerancia): sin él, un
 * código TOTP capturado sería válido varias veces mientras dure la
 * ventana, sobre la cuenta más peligrosa del producto. Se trata como una
 * necesidad de seguridad del mecanismo reutilizado, no como una columna
 * "por si acaso" (`ADR-034 OPEN-13`).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('platform_admin_mfa_factors', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->text('type')->default('totp');
            $table->text('secret_encrypted');
            $table->bigInteger('last_used_step')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE platform_admin_mfa_factors ADD CONSTRAINT platform_admin_mfa_factors_type_check
                CHECK (type IN ('totp'))
            SQL);

        // Un solo factor confirmado por administrador, igual que su
        // homóloga de tenant (UNIQUE (tenant_id, user_id, method) WHERE
        // confirmed_at IS NOT NULL) — aquí `type` solo puede valer 'totp',
        // así que no hace falta incluirlo en la clave.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX platform_admin_mfa_factors_admin_confirmed_unique
                ON platform_admin_mfa_factors (platform_admin_id)
                WHERE confirmed_at IS NOT NULL AND deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_admin_mfa_factors');
    }
};
