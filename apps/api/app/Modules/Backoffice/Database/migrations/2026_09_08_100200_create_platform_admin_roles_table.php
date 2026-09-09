<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-BO-007, datos.md §2.2. Pivote entre administrador y rol interno.
 * Los cuatro roles viven en un CHECK, no en una tabla de catálogo
 * (permisos.md §2): no hay requisito que pida roles de plataforma
 * personalizados.
 *
 * RN-BO-11 ("siempre al menos un superadministrador vivo y activo") no
 * se expresa con un CHECK — es una restricción sobre el conjunto de
 * filas, no sobre una fila — y se implementa en el servicio con bloqueo
 * de fila (funcional.md §4.3, datos.md §2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('platform_admin_roles', function (Blueprint $table): void {
            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->text('role');
            $table->foreignId('granted_by')->nullable()->constrained('platform_admins');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE platform_admin_roles ADD CONSTRAINT platform_admin_roles_role_check
                CHECK (role IN ('soporte', 'operaciones', 'comercial', 'superadministrador'))
            SQL);

        $owner->statement(
            'CREATE UNIQUE INDEX platform_admin_roles_admin_role_unique ON platform_admin_roles (platform_admin_id, role) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_admin_roles');
    }
};
