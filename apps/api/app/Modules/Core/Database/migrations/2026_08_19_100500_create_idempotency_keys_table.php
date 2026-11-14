<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * ADR-038 §8.3 (introducida por REQ-CORE 1.1, no es suya: primitiva
 * transversal que comparten los 53 módulos, igual que audit_logs o
 * data_exports — datos.md §A.5). PostgreSQL, no Redis: una clave de
 * idempotencia que no sobrevive a un reinicio no es idempotencia.
 *
 * Usa tenantTable() por simplicidad de infraestructura compartida: las
 * columnas de autoría (created_by/updated_by) quedan sin uso — no hay
 * actor de negocio que registrar en un cerrojo técnico de 24 horas — y la
 * purga (PurgeExpiredIdempotencyKeys) borra físicamente, sin pasar por
 * deleted_at, tal como exige datos.md §A.9 ("purga física diaria").
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('idempotency_keys', function (Blueprint $table): void {
            $table->text('endpoint');
            $table->text('idempotency_key');
            $table->text('request_body_hash');
            $table->text('status')->default('en_curso');
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at');
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_check
                CHECK (status IN ('en_curso', 'completado'))
            SQL);

        // Cerrojo natural contra dos peticiones concurrentes con la misma
        // clave (ADR-038 §8.2): la inserción con este índice único falla
        // para la segunda antes de que exista ningún otro mecanismo.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX idempotency_keys_tenant_endpoint_key_unique
                ON idempotency_keys (tenant_id, endpoint, idempotency_key)
            SQL);

        $owner->statement(
            'CREATE INDEX idempotency_keys_tenant_expires_idx ON idempotency_keys (tenant_id, expires_at)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS idempotency_keys');
    }
};
