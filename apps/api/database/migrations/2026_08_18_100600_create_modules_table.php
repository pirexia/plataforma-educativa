<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-034 §5, §7 (subpaso 0.8.7). `modules` es catálogo de plataforma,
 * igual patrón que `permissions` (0.8.6): materializado desde el código por
 * el comando de 0.8.11, escritura solo para el propietario.
 *
 * `module_subscriptions` es dato del tenant, con RLS: RMOD-009 se
 * comprueba en cada petición del tenant, y el Administrador de Centro debe
 * poder leer sus propios módulos contratados (REQ-CORE-002). Ausencia de
 * fila = módulo desactivado (falla en cerrado, ADR-034 §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        Schema::connection('pgsql_owner')->create('modules', function (Blueprint $table): void {
            $table->text('code')->primary();
            $table->text('name_key');
            $table->text('phase');
            $table->timestampTz('retired_at')->nullable();
        });

        $owner->statement('REVOKE INSERT, UPDATE, DELETE ON modules FROM plataforma_app, plataforma_platform');

        TenantMigration::tenantTable('module_subscriptions', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('module_code');
            $table->foreign('module_code')->references('code')->on('modules');
            $table->boolean('enabled')->default(false);
            $table->timestampTz('enabled_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('settings')->nullable();
        });

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX module_subscriptions_tenant_module_unique
                ON module_subscriptions (tenant_id, module_code)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP TABLE IF EXISTS module_subscriptions');
        $owner->statement('DROP TABLE IF EXISTS modules');
    }
};
