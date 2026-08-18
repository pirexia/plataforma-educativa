<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * ADR-034 §2 (subpaso 0.8.5): esquema completo de roles ahora, resolutor
 * de permisos en 1.5. `role_user` es la asignación de rol a una cuenta, no
 * a una persona (ADR-034 §1: los permisos describen lo que una sesión
 * autenticada puede hacer).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('roles', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('code');
            $table->text('name_key')->nullable();
            $table->text('name')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('mfa_required')->default(false);
            $table->boolean('special_data_access')->default(false);
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX roles_tenant_code_unique
                ON roles (tenant_id, code)
                WHERE deleted_at IS NULL
            SQL);

        // Roles predefinidos (sección 11.1): clave de traducción, cuatro
        // idiomas (INV-009). Roles personalizados (RPERM-005): literal,
        // contenido del centro. Exactamente uno de los dos, nunca ninguno
        // ni los dos.
        $owner->statement(<<<'SQL'
            ALTER TABLE roles ADD CONSTRAINT roles_name_source_check
                CHECK ((name_key IS NOT NULL) <> (name IS NOT NULL))
            SQL);

        TenantMigration::tenantTable('role_user', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'user_id', 'users');
            TenantMigration::tenantForeignId($table, 'role_id', 'roles');
        });

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX role_user_tenant_user_role_unique
                ON role_user (tenant_id, user_id, role_id)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP TABLE IF EXISTS role_user');
        $owner->statement('DROP TABLE IF EXISTS roles');
    }
};
