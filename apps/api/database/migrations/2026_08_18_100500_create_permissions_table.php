<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-034 §2, §7 (subpaso 0.8.6). `permissions` es catálogo de plataforma,
 * no dato del tenant: un centro elige qué permisos conceder, no cuáles
 * existen. La fuente de verdad es el código de cada módulo (INV-007),
 * materializado aquí por el comando idempotente de 0.8.11 — nunca a mano.
 *
 * `permission_role` sí es de tenant: las concesiones son decisión de cada
 * centro sobre sus propios roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        Schema::connection('pgsql_owner')->create('permissions', function (Blueprint $table): void {
            $table->text('code')->primary();
            $table->text('resource');
            $table->text('action');
            $table->text('module_code');
            $table->boolean('is_special_category')->default(false);
            $table->timestampTz('retired_at')->nullable();
        });

        // Escritura solo por el propietario (el comando de 0.8.11 corre
        // como plataforma_owner): ni la API ni el backoffice escriben aquí
        // directamente, o el catálogo se desincroniza del código en
        // silencio. ALTER DEFAULT PRIVILEGES de 0.7.1 les había dado
        // INSERT/UPDATE/DELETE como a cualquier tabla nueva.
        $owner->statement('REVOKE INSERT, UPDATE, DELETE ON permissions FROM plataforma_app, plataforma_platform');

        TenantMigration::tenantTable('permission_role', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'role_id', 'roles');
            $table->text('permission_code');
            $table->foreign('permission_code')->references('code')->on('permissions');
            $table->text('effect');
            $table->text('scope')->nullable();
        });

        $owner->statement(<<<'SQL'
            ALTER TABLE permission_role ADD CONSTRAINT permission_role_effect_check
                CHECK (effect IN ('allow', 'deny'))
            SQL);

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX permission_role_tenant_role_permission_unique
                ON permission_role (tenant_id, role_id, permission_code)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP TABLE IF EXISTS permission_role');
        $owner->statement('DROP TABLE IF EXISTS permissions');
    }
};
