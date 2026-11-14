<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CORE-002 (paso 1.1). datos.md §A.1: una fila por tenant, tabla de
 * tenant (no columnas en `tenants`) precisamente para que el observer de
 * 0.9 la audite sin reabrir ADR-036.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('tenant_settings', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('default_locale')->default('es-ES');
            $table->jsonb('active_locales')->default(DB::raw("'[\"es-ES\"]'::jsonb"));
            $table->text('timezone')->default('Europe/Madrid');
            $table->text('currency')->default('EUR');
            $table->text('autonomous_community')->nullable();
            $table->text('legal_name')->nullable();
            $table->text('tax_id')->nullable();
            $table->text('fiscal_address')->nullable();
            $table->text('fiscal_postal_code')->nullable();
            $table->text('fiscal_city')->nullable();
            $table->text('fiscal_province')->nullable();
            $table->text('fiscal_country_code')->default('ES');
            $table->text('color_primary')->nullable();
            $table->text('color_secondary')->nullable();
            $table->text('logo_object_key')->nullable();
            $table->text('favicon_object_key')->nullable();
            $table->text('login_background_object_key')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX tenant_settings_tenant_unique
                ON tenant_settings (tenant_id)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_default_locale_check
                CHECK (default_locale IN ('es-ES', 'en', 'de', 'fr'))
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_active_locales_check
                CHECK (jsonb_typeof(active_locales) = 'array' AND jsonb_array_length(active_locales) > 0)
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_currency_check
                CHECK (currency ~ '^[A-Z]{3}$')
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_color_primary_check
                CHECK (color_primary IS NULL OR color_primary ~ '^#[0-9A-Fa-f]{6}$')
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE tenant_settings ADD CONSTRAINT tenant_settings_color_secondary_check
                CHECK (color_secondary IS NULL OR color_secondary ~ '^#[0-9A-Fa-f]{6}$')
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS tenant_settings');
    }
};
