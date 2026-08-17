<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-033 §4 y §7: raíz del aislamiento. tenants no lleva tenant_id (se
 * identifica a sí misma por su propio id) y su política RLS es distinta de
 * la del resto de tablas de negocio: un tenant solo ve su propia fila.
 *
 * Conexión pgsql_owner fijada explícitamente (hallazgo de db-reviewer
 * antes de mezclar 0.7): sin esto, la migración depende de que el
 * operador recuerde ejecutar `--database=pgsql_owner` a mano — la misma
 * "disciplina no verificada" que ADR-033 existe para eliminar.
 * TenantMigration::tenantTable() (0.7.9) ya sigue este patrón para las
 * tablas de negocio; tenants es anterior en el tiempo y se alinea aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->text('slug')->unique();
            $table->text('name');
            $table->text('status')->default('en_alta');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        $connection = DB::connection('pgsql_owner');

        $connection->statement(<<<'SQL'
            ALTER TABLE tenants ADD CONSTRAINT tenants_status_check
                CHECK (status IN ('en_alta', 'activo', 'suspendido', 'en_baja', 'eliminado'))
            SQL);

        // Política propia (ADR-033 §7): no filtra por tenant_id (no lo
        // tiene), filtra por su propio id. Sin tenant activo, cero filas.
        $connection->statement('ALTER TABLE tenants ENABLE ROW LEVEL SECURITY');
        $connection->statement('ALTER TABLE tenants FORCE ROW LEVEL SECURITY');
        $connection->statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON tenants
                USING      (id = app.current_tenant_id())
                WITH CHECK (id = app.current_tenant_id())
            SQL);
    }

    public function down(): void
    {
        Schema::connection('pgsql_owner')->dropIfExists('tenants');
    }
};
