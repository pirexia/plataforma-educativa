<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-BO-007, datos.md §2.5. `cidr` nativo de PostgreSQL, no `text`
 * (ADR-029 prohíbe `varchar(n)` y `ENUM`, no los tipos de red nativos):
 * da el operador de contención `>>=`, verificado por el motor en vez de
 * analizar la máscara en PHP en cada petición.
 *
 * Lista vacía = denegar a todos (RN-BO-07): no hay ninguna fila "por
 * defecto" que sembrar aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::platformTable('platform_ip_allowlist', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->ipAddress('cidr');
            $table->text('description');
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // Blueprint::ipAddress() genera `inet`, no `cidr` — RN-BO exige el
        // tipo de rango nativo (datos.md §2.5): se corrige aquí, es más
        // simple que un tipo de columna sin helper de Blueprint.
        $owner->statement('ALTER TABLE platform_ip_allowlist ALTER COLUMN cidr TYPE cidr USING cidr::cidr');

        $owner->statement(
            'ALTER TABLE platform_ip_allowlist ADD CONSTRAINT platform_ip_allowlist_created_by_fk '.
            'FOREIGN KEY (created_by) REFERENCES platform_admins (id)'
        );
        $owner->statement(
            'ALTER TABLE platform_ip_allowlist ADD CONSTRAINT platform_ip_allowlist_updated_by_fk '.
            'FOREIGN KEY (updated_by) REFERENCES platform_admins (id)'
        );

        // La comprobación en caliente de EnforcePlatformIpAllowlist: solo
        // las entradas activas.
        $owner->statement(
            'CREATE INDEX platform_ip_allowlist_enabled_idx ON platform_ip_allowlist (enabled) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_ip_allowlist');
    }
};
