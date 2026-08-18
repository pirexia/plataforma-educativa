<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * ADR-034 §4 (subpaso 0.8.2): tabla de tenant ordinaria — cada centro
 * define sus propios cursos, con sus fechas y su estado; no es catálogo
 * compartido.
 *
 * Los dos índices únicos parciales son invariantes de datos, no reglas de
 * flujo (una condición de carrera entre dos peticiones simultáneas no las
 * respetaría si vivieran solo en el servicio), así que van en el motor.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('academic_years', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('code');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('status')->default('planificacion');
        });

        $connection = DB::connection('pgsql_owner');

        $connection->statement(<<<'SQL'
            ALTER TABLE academic_years ADD CONSTRAINT academic_years_status_check
                CHECK (status IN ('planificacion', 'activo', 'cerrado', 'archivado'))
            SQL);

        $connection->statement(
            'ALTER TABLE academic_years ADD CONSTRAINT academic_years_dates_check CHECK (ends_on > starts_on)'
        );

        $connection->statement(<<<'SQL'
            CREATE UNIQUE INDEX academic_years_tenant_code_unique
                ON academic_years (tenant_id, code)
                WHERE deleted_at IS NULL
            SQL);

        // Como mucho un curso activo y uno en planificación por centro
        // (REQ-CURSO-001), impuesto en el motor.
        $connection->statement(<<<'SQL'
            CREATE UNIQUE INDEX academic_years_tenant_status_unique
                ON academic_years (tenant_id, status)
                WHERE status IN ('activo', 'planificacion') AND deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS academic_years');
    }
};
