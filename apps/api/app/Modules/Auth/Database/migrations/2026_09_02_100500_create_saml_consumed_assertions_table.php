<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * datos.md §G.4.2 (REQ-AUTH-004, 1.4c). Cubre un ataque distinto de
 * `saml_auth_requests`: que una misma aserición legítima se reenvíe
 * contra OTRA petición viva (`RN-AUTH-122`). El rechazo lo produce la
 * violación del índice único, no una lectura previa (`CA-AUTH-344`).
 *
 * No se guarda el XML de la aserción ni ningún fragmento suyo: de una
 * aserción solo sobreviven su `ID` y su `NotOnOrAfter` (`CA-AUTH-363`,
 * `RN-AUTH-95` ampliado). Sin `public_id`. Auditoría: `None`, mismo
 * argumento que `saml_auth_requests` — el modelo no implementa
 * `Auditable`.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('saml_consumed_assertions', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'identity_provider_id', 'identity_providers');
            $table->text('assertion_id');
            $table->timestampTz('not_on_or_after');
        });

        $owner = DB::connection('pgsql_owner');

        // La protección, no un índice de apoyo: el rechazo de la
        // repetición lo produce esta violación (CA-AUTH-344). Tecleado
        // por proveedor: dos IdP distintos pueden emitir legítimamente el
        // mismo ID.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX saml_consumed_assertions_tenant_provider_assertion_unique
                ON saml_consumed_assertions (tenant_id, identity_provider_id, assertion_id)
            SQL);

        // La purga programada.
        $owner->statement(<<<'SQL'
            CREATE INDEX saml_consumed_assertions_tenant_not_on_or_after_idx
                ON saml_consumed_assertions (tenant_id, not_on_or_after)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS saml_consumed_assertions');
    }
};
