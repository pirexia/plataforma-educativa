<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-BO-005 puntos 1-2, sub-paso 1.6e. `docs/modulos/REQ-BO/datos.md §9`,
 * §12 fila 6. Dos tablas nuevas, sin dependencia externa (ni
 * laravel/pennant, ni Unleash, ni Flagsmith): el motor son estas dos
 * tablas y una función hash determinista (funcional.md §5.11.6).
 *
 * Mismo reparto que `modules`/`permissions` (ADR-034 §5): el catálogo
 * (`feature_flags`) lo materializa `platform:sync-registry` desde el
 * descriptor declarado en código, nunca se crea desde la API. Las reglas
 * (`feature_flag_rules`) son lo único que un operador escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->text('public_id')->unique();
            $table->text('key')->unique();
            $table->text('module_code')->nullable();
            $table->text('name_key');
            $table->text('description_key');
            $table->text('rollout_unit')->default('tenant');
            $table->text('status')->default('activo');
            $table->text('status_reason')->nullable();
            $table->unsignedBigInteger('rules_version')->default(0);
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();

            $table->foreign('module_code')->references('code')->on('modules');
        });

        Schema::connection('pgsql_owner')->create('feature_flag_rules', function (Blueprint $table): void {
            $table->id();
            $table->text('public_id')->unique();
            $table->foreignId('feature_flag_id')->constrained('feature_flags')->cascadeOnDelete();
            $table->text('scope_type');
            $table->unsignedBigInteger('affected_tenant_id')->nullable();
            $table->text('role_code')->nullable();
            $table->smallInteger('percentage')->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('reason');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->foreign('affected_tenant_id')->references('id')->on('tenants');
            $table->foreign('created_by')->references('id')->on('platform_admins');
            $table->foreign('updated_by')->references('id')->on('platform_admins');
        });

        $owner = DB::connection('pgsql_owner');

        // datos.md §9.1: formato validado por el comando de sincronización,
        // no aquí — el CHECK del `rollout_unit` y `status` sí es de motor.
        $owner->statement("ALTER TABLE feature_flags ADD CONSTRAINT feature_flags_rollout_unit_check CHECK (rollout_unit IN ('tenant', 'user'))");
        $owner->statement("ALTER TABLE feature_flags ADD CONSTRAINT feature_flags_status_check CHECK (status IN ('activo', 'forced_off'))");
        $owner->statement('CREATE INDEX feature_flags_module_code_idx ON feature_flags (module_code) WHERE retired_at IS NULL');

        // datos.md §9.3: vocabulario cerrado y las tres restricciones que
        // hacen imposible una regla incoherente en el motor, no en el
        // FormRequest.
        $owner->statement("ALTER TABLE feature_flag_rules ADD CONSTRAINT feature_flag_rules_scope_type_check CHECK (scope_type IN ('global', 'tenant', 'early_adopters', 'percentage', 'role'))");
        $owner->statement('ALTER TABLE feature_flag_rules ADD CONSTRAINT feature_flag_rules_percentage_check CHECK (percentage BETWEEN 0 AND 100)');
        $owner->statement(<<<'SQL'
            ALTER TABLE feature_flag_rules ADD CONSTRAINT feature_flag_rules_tenant_axis_check
                CHECK ((scope_type = 'tenant') = (affected_tenant_id IS NOT NULL))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE feature_flag_rules ADD CONSTRAINT feature_flag_rules_role_axis_check
                CHECK ((scope_type = 'role') = (role_code IS NOT NULL))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE feature_flag_rules ADD CONSTRAINT feature_flag_rules_percentage_axis_check
                CHECK ((scope_type = 'percentage') = (percentage IS NOT NULL))
            SQL);

        // Un solo cubo por eje y por objetivo: sin esto, "al 20 %" y "al
        // 60 %" podrían coexistir sobre el mismo flag (datos.md §9.3).
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX feature_flag_rules_singleton_axis_unique ON feature_flag_rules (feature_flag_id, scope_type)
                WHERE deleted_at IS NULL AND scope_type IN ('global', 'early_adopters', 'percentage')
            SQL);
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX feature_flag_rules_tenant_unique ON feature_flag_rules (feature_flag_id, affected_tenant_id)
                WHERE deleted_at IS NULL AND scope_type = 'tenant'
            SQL);
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX feature_flag_rules_role_unique ON feature_flag_rules (feature_flag_id, role_code)
                WHERE deleted_at IS NULL AND scope_type = 'role'
            SQL);

        // Índices de consulta (datos.md §9.3): el evaluador siempre lee el
        // conjunto completo de un flag, nunca una regla suelta.
        $owner->statement('CREATE INDEX feature_flag_rules_flag_idx ON feature_flag_rules (feature_flag_id) WHERE deleted_at IS NULL');
        $owner->statement(<<<'SQL'
            CREATE INDEX feature_flag_rules_tenant_lookup_idx ON feature_flag_rules (affected_tenant_id)
                WHERE deleted_at IS NULL AND scope_type = 'tenant'
            SQL);

        // datos.md §9.6: dos escritores con dos alcances distintos.
        // platform:sync-registry (pgsql_owner) escribe el catálogo
        // completo; el backoffice (plataforma_platform) sólo status,
        // status_reason y rules_version de feature_flags, y el conjunto
        // completo de feature_flag_rules (que es enteramente suyo).
        $owner->statement('REVOKE INSERT, UPDATE, DELETE ON feature_flags FROM plataforma_platform');
        $owner->statement('GRANT UPDATE (status, status_reason, rules_version, updated_at) ON feature_flags TO plataforma_platform');

        // La aplicación de los centros sólo evalúa: lee y nada más.
        $owner->statement('REVOKE INSERT, UPDATE, DELETE ON feature_flags, feature_flag_rules FROM plataforma_app');
        $owner->statement('GRANT SELECT ON feature_flags, feature_flag_rules TO plataforma_app');

        // Las secuencias, que el REVOKE de tabla no toca (datos.md §12.1).
        $owner->statement('REVOKE ALL ON SEQUENCE feature_flags_id_seq FROM plataforma_app');
        $owner->statement('REVOKE ALL ON SEQUENCE feature_flag_rules_id_seq FROM plataforma_app');
    }

    public function down(): void
    {
        Schema::connection('pgsql_owner')->dropIfExists('feature_flag_rules');
        Schema::connection('pgsql_owner')->dropIfExists('feature_flags');
    }
};
