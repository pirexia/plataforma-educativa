<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-033 §5, §6, §9. Tabla creada una sola vez (mismo motivo que
// TenantModelTest: DROP/DELETE de una conexión distinta a la que escribió
// dentro del mismo test se queda esperando un lock).

beforeEach(function (): void {
    if (Schema::connection('pgsql_owner')->hasTable('tenant_migration_probes')) {
        return;
    }

    TenantMigration::tenantTable('tenant_migration_probes', function ($table): void {
        $table->text('name');
        // Sin timestampsTz() aquí: tenantTable() ya lo añade (y también
        // softDeletesTz()), ver TenantMigration.php.
    });
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('rechaza un nombre de tabla que no sea un identificador seguro', function (): void {
    expect(fn () => TenantMigration::tenantTable('users; drop table users;--', fn ($t) => null))
        ->toThrow(InvalidArgumentException::class);
});

test('la tabla queda con RLS forzado y la política estándar', function (): void {
    $row = DB::selectOne(<<<'SQL'
        SELECT relrowsecurity, relforcerowsecurity
        FROM pg_class
        WHERE relname = 'tenant_migration_probes'
        SQL);

    $policy = DB::selectOne(
        "SELECT polname FROM pg_policy WHERE polrelid = 'tenant_migration_probes'::regclass"
    );

    expect($row->relrowsecurity)->toBeTrue()
        ->and($row->relforcerowsecurity)->toBeTrue()
        ->and($policy->polname)->toBe('tenant_isolation');
});

test('tenant_id tiene DEFAULT app.current_tenant_id() y (tenant_id, id) es único', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenant->id);
    DB::table('tenant_migration_probes')->insert(['name' => 'sin tenant_id explícito']);
    $row = DB::table('tenant_migration_probes')->where('name', 'sin tenant_id explícito')->first();
    $context->leave();

    expect($row->tenant_id)->toBe($tenant->id);

    $indexDefs = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'tenant_migration_probes'"))
        ->pluck('indexdef');

    expect($indexDefs->contains(
        fn (string $def) => str_contains($def, 'UNIQUE') && str_contains($def, 'tenant_id') && str_contains($def, ', id')
    ))->toBeTrue();
});

// ADR-034 §6/§7 (0.8.1): created_by/updated_by nullable en toda tabla de
// tenant, sin FK hasta que `users` tenga tenant_id (aquí no lo tiene
// todavía: el `users` provisional del starter kit no lo lleva).
test('tenantTable() añade created_by y updated_by, nullable y sin FK antes de 0.8.4', function (): void {
    $columns = collect(Schema::connection('pgsql_owner')->getColumns('tenant_migration_probes'));

    $createdBy = $columns->firstWhere('name', 'created_by');
    $updatedBy = $columns->firstWhere('name', 'updated_by');

    expect($createdBy)->not->toBeNull()
        ->and($createdBy['nullable'])->toBeTrue()
        ->and($updatedBy)->not->toBeNull()
        ->and($updatedBy['nullable'])->toBeTrue();

    $fkNames = collect(DB::select(<<<'SQL'
        SELECT conname FROM pg_constraint
        WHERE conrelid = 'tenant_migration_probes'::regclass AND contype = 'f'
        SQL))->pluck('conname');

    expect($fkNames->contains(fn (string $name) => str_contains($name, 'created_by')))->toBeFalse();
});

test('tenantTableAppendOnly() crea la tabla sin deleted_at y revoca UPDATE/DELETE a plataforma_app', function (): void {
    if (! Schema::connection('pgsql_owner')->hasTable('append_only_probes')) {
        TenantMigration::tenantTableAppendOnly('append_only_probes', function ($table): void {
            $table->text('event');
        });
    }

    expect(Schema::connection('pgsql_owner')->hasColumn('append_only_probes', 'deleted_at'))->toBeFalse()
        ->and(Schema::connection('pgsql_owner')->hasColumn('append_only_probes', 'created_by'))->toBeFalse();

    // Conexión `pgsql`: es literalmente plataforma_app (config/database.php),
    // el rol al que se le revoca UPDATE/DELETE — no hace falta SET ROLE.
    expect(fn () => DB::connection('pgsql')->statement('UPDATE append_only_probes SET event = ? WHERE id = -1', ['x']))
        ->toThrow(QueryException::class);
});

test('tenantForeignId() crea columna, índice y FK compuesta obligatoria', function (): void {
    if (! Schema::connection('pgsql_owner')->hasTable('fk_helper_parents')) {
        TenantMigration::tenantTable('fk_helper_parents', function ($table): void {
            $table->text('name');
        });
    }

    if (! Schema::connection('pgsql_owner')->hasTable('fk_helper_children')) {
        TenantMigration::tenantTable('fk_helper_children', function ($table): void {
            TenantMigration::tenantForeignId($table, 'parent_id', 'fk_helper_parents');
        });
    }

    $column = collect(Schema::connection('pgsql_owner')->getColumns('fk_helper_children'))
        ->firstWhere('name', 'parent_id');

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeFalse();

    $fkExists = DB::selectOne(<<<'SQL'
        SELECT 1 AS found FROM pg_constraint
        WHERE conrelid = 'fk_helper_children'::regclass AND contype = 'f'
        SQL);

    expect($fkExists)->not->toBeNull();
});
