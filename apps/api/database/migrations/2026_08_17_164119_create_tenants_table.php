<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-033 §4 y §7: raíz del aislamiento. tenants no lleva tenant_id (se
 * identifica a sí misma por su propio id) y su política RLS es distinta de
 * la del resto de tablas de negocio: un tenant solo ve su propia fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->text('slug')->unique();
            $table->text('name');
            $table->text('status')->default('en_alta');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tenants ADD CONSTRAINT tenants_status_check
                CHECK (status IN ('en_alta', 'activo', 'suspendido', 'en_baja', 'eliminado'))
            SQL);

        // Política propia (ADR-033 §7): no filtra por tenant_id (no lo
        // tiene), filtra por su propio id. Sin tenant activo, cero filas.
        DB::statement('ALTER TABLE tenants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenants FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON tenants
                USING      (id = app.current_tenant_id())
                WITH CHECK (id = app.current_tenant_id())
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
