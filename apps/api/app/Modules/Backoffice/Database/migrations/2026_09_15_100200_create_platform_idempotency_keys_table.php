<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * `ADR-038 §8`, funcional.md §5.8.4 (1.6c). **Hallazgo propio, corregido
 * en este mismo commit**: la instrucción de partida era reutilizar
 * `App\Http\Middleware\RequireIdempotencyKey` y `App\Models\
 * IdempotencyKey` sin crear un mecanismo nuevo (`ADR-038 §8.1`,
 * precedente de `UserImportsController`). Verificado que es
 * **técnicamente imposible**: `IdempotencyKey` extiende `TenantModel`
 * (`idempotency_keys` es tabla de tenant, con `tenant_id` `NOT NULL` y
 * parte de su índice único de deduplicación) y por tanto exige un
 * contexto de tenant activo para cualquier consulta — que
 * `POST /module-rollouts` nunca tiene, porque el backoffice no es un
 * tenant (`RN-BO-01`). No es un `REVOKE`/`GRANT` lo que falta: es que la
 * primitiva entera se diseñó para las peticiones de los 53 módulos de
 * producto, no para la superficie sin tenant de `REQ-BO`.
 *
 * Esta tabla replica el diseño de `idempotency_keys` (`datos.md §A.5`)
 * quitando `tenant_id`: es la versión de plataforma de la misma
 * primitiva, con el mismo criterio de purga física a las 24 h
 * (`PurgePlatformIdempotencyKeys`). `plataforma_app` no la necesita en
 * absoluto —ningún *endpoint* del producto la usa— así que
 * `platformTable()` la deja fuera con su `REVOKE ALL` de siempre;
 * `plataforma_platform` es su único escritor.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('platform_idempotency_keys', function (Blueprint $table): void {
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
            ALTER TABLE platform_idempotency_keys ADD CONSTRAINT platform_idempotency_keys_status_check
                CHECK (status IN ('en_curso', 'completado'))
            SQL);

        // Cerrojo natural contra dos peticiones concurrentes con la misma
        // clave (ADR-038 §8.2), mismo criterio que idempotency_keys.
        $owner->statement(
            'CREATE UNIQUE INDEX platform_idempotency_keys_endpoint_key_unique ON platform_idempotency_keys (endpoint, idempotency_key)'
        );

        $owner->statement(
            'CREATE INDEX platform_idempotency_keys_expires_idx ON platform_idempotency_keys (expires_at)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_idempotency_keys');
    }
};
