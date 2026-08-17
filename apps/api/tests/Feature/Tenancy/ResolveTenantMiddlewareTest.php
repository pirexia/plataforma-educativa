<?php

use App\Http\Middleware\ResolveTenant;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// ADR-014 + ADR-033 §2: middleware previo a cualquier acceso a datos.

beforeEach(function (): void {
    Route::middleware(ResolveTenant::class)->get('/_test/tenant-probe', function () {
        return response()->json([
            'tenant_id' => app(TenantContext::class)->tenantId(),
        ]);
    });
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();

    // La caché de resolución (60s) sobrevive a este proceso: sin esto, un
    // slug reutilizado en la siguiente invocación de la suite podría leer
    // el id cacheado de esta corrida en vez del que acaba de crear.
    Cache::flush();
});

function tenantProbeUrl(string $host): string
{
    return "http://{$host}/_test/tenant-probe";
}

test('404 si el host no tiene subdominio resoluble', function (): void {
    $this->get(tenantProbeUrl(config('tenancy.base_domain')))
        ->assertNotFound();
});

test('404 si el host no coincide con el dominio base configurado', function (): void {
    $this->get(tenantProbeUrl('demo.otro-dominio.test'))
        ->assertNotFound();
});

test('404 si el slug no corresponde a ningún tenant', function (): void {
    $this->get(tenantProbeUrl('no-existe.'.config('tenancy.base_domain')))
        ->assertNotFound();
});

test('404 si el tenant existe pero no está activo (en_alta)', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'en-construccion', 'status' => 'en_alta']);

    $this->get(tenantProbeUrl('en-construccion.'.config('tenancy.base_domain')))
        ->assertNotFound();
});

test('503 si el tenant está suspendido', function (): void {
    Tenant::factory()->suspendido()->create(['slug' => 'suspendido-uno']);

    $this->get(tenantProbeUrl('suspendido-uno.'.config('tenancy.base_domain')))
        ->assertStatus(503);
});

test('entra en el contexto del tenant activo y lo deja disponible a la petición', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'activo-uno']);

    $this->get(tenantProbeUrl('activo-uno.'.config('tenancy.base_domain')))
        ->assertOk()
        ->assertJson(['tenant_id' => $tenant->id]);
});

test('la resolución por slug se cachea', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'cacheado-uno']);

    $this->get(tenantProbeUrl('cacheado-uno.'.config('tenancy.base_domain')))->assertOk();

    // Se borra de verdad de la base, pero la caché de resolución (60s)
    // sigue sirviendo el tenant: siguiente petición, sin volver a tocar BD.
    DB::connection('pgsql_platform')->table('tenants')->where('id', $tenant->id)->delete();

    $this->get(tenantProbeUrl('cacheado-uno.'.config('tenancy.base_domain')))
        ->assertOk()
        ->assertJson(['tenant_id' => $tenant->id]);
});
