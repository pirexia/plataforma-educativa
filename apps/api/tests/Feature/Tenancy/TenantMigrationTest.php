<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
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
        $table->timestampsTz();
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
