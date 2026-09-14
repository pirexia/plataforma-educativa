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

// CA-BO-110 (RN-BO-50, REQ-BO/funcional.md §5.4.1, 1.6b): `en_alta` pasa
// a responder 503, no 404 — la ventana de aprovisionamiento es
// observable, no indistinguible de un host inexistente. Antes de 1.6b
// este mismo test comprobaba (incorrectamente, era el defecto que
// RN-BO-50 corrige) un 404.
test('CA-BO-110: 503 si el tenant existe pero está en_alta (aprovisionando)', function (): void {
    Tenant::factory()->create(['slug' => 'en-construccion', 'status' => 'en_alta']);

    $this->get(tenantProbeUrl('en-construccion.'.config('tenancy.base_domain')))
        ->assertStatus(503)
        ->assertHeader('Retry-After');
});

// CA-BO-110: los cinco estados de la tabla de RN-BO-50 completa, en un
// solo test parametrizado — activo pasa, los otros cuatro dan 503 salvo
// que no exista ningún tenant con ese slug (ver el 404 de arriba).
test('CA-BO-110: suspendido, en_baja y eliminado responden 503 con Retry-After', function (string $status) {
    $tenant = Tenant::factory()->create(['slug' => "tenant-{$status}", 'status' => $status]);

    if ($status === 'eliminado') {
        $tenant->delete();
    }

    $this->get(tenantProbeUrl("tenant-{$status}.".config('tenancy.base_domain')))
        ->assertStatus(503)
        ->assertHeader('Retry-After');
})->with(['suspendido', 'en_baja', 'eliminado']);

// CA-BO-111: un tenant `eliminado` lleva `deleted_at` (SoftDeletes) — la
// resolución tiene que encontrarlo pese al borrado lógico (RN-BO-50,
// funcional.md §5.4.1 punto 2) y responder 503, nunca el 404 de "no
// existe" que daría una búsqueda que no mirase los borrados.
test('CA-BO-111: un tenant eliminado (borrado lógico) responde 503, no 404', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'centro-cerrado', 'status' => 'eliminado']);
    $tenant->delete();

    expect(Tenant::withTrashed()->find($tenant->id)->deleted_at)->not->toBeNull();

    $this->get(tenantProbeUrl('centro-cerrado.'.config('tenancy.base_domain')))
        ->assertStatus(503);
});

// CA-BO-112 (funcional.md §5.4.2): la unicidad de `slug` es parcial
// (`WHERE deleted_at IS NULL`), así que un mismo slug puede pertenecer a
// la vez a un tenant vivo y a uno eliminado. La resolución prefiere
// siempre al vivo.
test('CA-BO-112: un slug reutilizado por un tenant nuevo resuelve al vivo, no al eliminado', function (): void {
    $old = Tenant::factory()->create(['slug' => 'colegio-reutilizado', 'status' => 'eliminado']);
    $old->delete();

    $live = Tenant::factory()->create(['slug' => 'colegio-reutilizado', 'status' => 'activo']);

    $this->get(tenantProbeUrl('colegio-reutilizado.'.config('tenancy.base_domain')))
        ->assertOk()
        ->assertJson(['tenant_id' => $live->id]);
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
