<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §F.3 (REQ-AUTH-004, 1.4b). La credencial de cliente de un
 * proveedor, cifrada con `APP_KEY` (`RN-AUTH-112`, decisión del usuario
 * sobre `ADR-043 §8.2`). Tabla propia y no columna de `identity_providers`,
 * por dos motivos: la decisión del usuario, y que una sola columna
 * produciría una caída total sin aviso el día del vencimiento (`ADR-043
 * §2.4`, aplicado aquí a nuestra credencial en el emisor).
 *
 * Sin `UNIQUE` de "una sola credencial activa por proveedor": la ventana
 * de rotación exige que haya dos a la vez. Lo garantiza la regla de
 * elección (la de `activated_at` más reciente), determinista y en el
 * índice.
 *
 * `client_secret` se cifra con el cast `encrypted` de Eloquent (mismo
 * patrón que `user_mfa_factors.secret_encrypted`, `§C.2`) — se declara
 * `text` aquí y el cifrado vive en el modelo, no en el esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('identity_provider_secrets', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'identity_provider_id', 'identity_providers');
            $table->text('client_secret');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('activated_at');
            $table->timestampTz('retired_at')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // La consulta caliente: la credencial vigente de un proveedor, una
        // vez por canje de código.
        $owner->statement(<<<'SQL'
            CREATE INDEX identity_provider_secrets_active_idx
                ON identity_provider_secrets (tenant_id, identity_provider_id, activated_at DESC)
                WHERE deleted_at IS NULL AND retired_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE identity_provider_secrets ADD CONSTRAINT identity_provider_secrets_retired_after_activated_check
                CHECK (retired_at IS NULL OR retired_at >= activated_at)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS identity_provider_secrets');
    }
};
