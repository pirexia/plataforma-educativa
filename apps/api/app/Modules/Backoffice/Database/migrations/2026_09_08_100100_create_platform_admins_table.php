<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-BO-007, datos.md §2.1. Identidad de plataforma: un `platform_admin`
 * no tiene tenant (RN-BO-01) — ninguna de sus columnas es `tenant_id`, ni
 * nula ni de ningún tipo, y esta tabla no tiene `person_id` (`Person` es
 * de tenant, ADR-034 §1).
 *
 * `mfa_required` no existe a propósito (datos.md §2.1): es obligatorio
 * para los cuatro roles sin excepción (RN-BO-05), y una columna que solo
 * puede valer `true` es una columna que alguien pondrá a `false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('platform_admins', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('email');
            $table->text('name');
            $table->text('password');
            $table->text('status')->default('activo');
            $table->text('locale')->default('es-ES');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('password_changed_at');
            $table->timestampTz('mfa_enrolled_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_status_check
                CHECK (status IN ('activo', 'suspendido'))
            SQL);

        $owner->statement(
            'ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_created_by_fk '.
            'FOREIGN KEY (created_by) REFERENCES platform_admins (id)'
        );
        $owner->statement(
            'ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_updated_by_fk '.
            'FOREIGN KEY (updated_by) REFERENCES platform_admins (id)'
        );

        // Unicidad de negocio, no de rendimiento (datos.md §2.1): entre
        // los vivos, por correo en minúsculas.
        $owner->statement(
            'CREATE UNIQUE INDEX platform_admins_email_unique ON platform_admins (lower(email)) WHERE deleted_at IS NULL'
        );

        // GET /admins filtra por status (api.md §2.2): decenas de filas,
        // pero es una consulta real del listado, no un índice "por si
        // acaso".
        $owner->statement(
            'CREATE INDEX platform_admins_status_idx ON platform_admins (status) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_admins');
    }
};
