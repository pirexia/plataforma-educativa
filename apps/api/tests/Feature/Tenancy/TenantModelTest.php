<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextMissing;
use App\Support\Tenancy\TenantModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-033 §4. Tabla y modelo desechables: no hay todavía ningún módulo de
// negocio real (app/Modules/ vacío hasta 1.1) sobre el que probar esto.
//
// La tabla se crea una sola vez (persiste entre tests y entre corridas de
// la suite, como `tenants`) y NO se borra en cada test: un DROP/DELETE por
// una conexión distinta de la que escribió las filas de este mismo test
// (pgsql, envuelta en una transacción que Laravel no revierte hasta
// DESPUÉS de que corra afterEach) se queda esperando el lock de esa
// transacción todavía abierta — deadlock real, no hipotético, encontrado
// escribiendo este fichero. Cada test limpia solo lo que escribió por una
// conexión no transaccional (pgsql_platform), que es la única forma segura
// de limpiar entre tests aquí.

class TenantModelProbe extends TenantModel
{
    protected $table = 'tenant_model_probes';

    protected $fillable = ['name'];
}

beforeEach(function (): void {
    if (Schema::connection('pgsql_owner')->hasTable('tenant_model_probes')) {
        return;
    }

    Schema::connection('pgsql_owner')->create('tenant_model_probes', function ($table): void {
        $table->id();
        $table->foreignId('tenant_id');
        $table->text('name');
        $table->timestampsTz();
    });

    DB::connection('pgsql_owner')->statement('ALTER TABLE tenant_model_probes ENABLE ROW LEVEL SECURITY');
    DB::connection('pgsql_owner')->statement('ALTER TABLE tenant_model_probes FORCE ROW LEVEL SECURITY');
    DB::connection('pgsql_owner')->statement(<<<'SQL'
        CREATE POLICY tenant_isolation ON tenant_model_probes
            USING      (tenant_id = app.current_tenant_id())
            WITH CHECK (tenant_id = app.current_tenant_id())
        SQL);
});

afterEach(function (): void {
    // Solo tenants (escritas por pgsql_platform, igual que aquí): las filas
    // de tenant_model_probes escritas por pgsql las revierte Laravel solo
    // al terminar el test (DatabaseTransactions), después de este hook.
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('sin tenant activo, una consulta Eloquent lanza TenantContextMissing', function (): void {
    expect(fn () => TenantModelProbe::query()->get())
        ->toThrow(TenantContextMissing::class);
});

test('creating() rellena tenant_id solo, y el scope filtra por él', function (): void {
    $context = app(TenantContext::class);
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $context->runFor($tenantA->id, function () {
        TenantModelProbe::create(['name' => 'de A']);
    });

    $context->runFor($tenantB->id, function () {
        TenantModelProbe::create(['name' => 'de B']);
    });

    $context->enter($tenantA->id);
    $visibles = TenantModelProbe::query()->where('tenant_id', $tenantA->id)->get();
    $context->leave();

    expect($visibles)->toHaveCount(1)
        ->and($visibles->first()->name)->toBe('de A');
});

test('no se puede cambiar tenant_id tras la creación', function (): void {
    $context = app(TenantContext::class);
    $tenant = Tenant::factory()->create();

    $context->enter($tenant->id);
    $probe = TenantModelProbe::create(['name' => 'original']);

    $probe->tenant_id = $tenant->id + 1;

    expect(fn () => $probe->save())->toThrow(RuntimeException::class);

    $context->leave();
});

test('runAsPlatform() ve todos los tenants', function (): void {
    $context = app(TenantContext::class);
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Insertadas por pgsql_platform directamente (no por pgsql, que es lo
    // que crea Eloquent en modo normal): así quedan comprometidas de
    // verdad y visibles para la lectura en modo plataforma, que usa esa
    // misma conexión — sin cruzar sesiones distintas de PostgreSQL (ver
    // cabecera del fichero y TenantTest.php).
    DB::connection('pgsql_platform')->table('tenant_model_probes')->insert([
        ['tenant_id' => $tenantA->id, 'name' => 'runAsPlatform-A', 'created_at' => now(), 'updated_at' => now()],
        ['tenant_id' => $tenantB->id, 'name' => 'runAsPlatform-B', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $todos = $context->runAsPlatform(
        fn () => TenantModelProbe::query()->whereIn('tenant_id', [$tenantA->id, $tenantB->id])->get()
    );

    expect($todos)->toHaveCount(2);

    DB::connection('pgsql_platform')->table('tenant_model_probes')
        ->whereIn('tenant_id', [$tenantA->id, $tenantB->id])->delete();
});
