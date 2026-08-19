<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();

    // phpunit.xml usa Redis real para CACHE_STORE (ADR-033 §9: así se
    // prueba el aislamiento de verdad). Sobrevive entre invocaciones del
    // proceso, a diferencia de 'array' — sin este flush, un slug de
    // tenant reutilizado en la siguiente ejecución de la suite podría
    // leer el tenant_id cacheado de esta corrida (mismo motivo que
    // tests/Feature/Tenancy/ResolveTenantMiddlewareTest.php).
    Cache::flush();
});

// CA-CORE-001
test('CA-CORE-001: GET /tenant/settings devuelve la configuración del propio centro, nada de otro tenant', function (): void {
    [$tenant, $admin] = provisionCoreTenant('settings-uno');
    [$otherTenant] = provisionCoreTenant('settings-otro');

    $response = test()->actingAs($admin)
        ->get(coreApiUrl($tenant->slug, '/tenant/settings'))
        ->assertOk();

    $response->assertJsonPath('regional.default_locale', 'es-ES')
        ->assertJsonPath('regional.active_locales', ['es-ES'])
        ->assertJsonPath('regional.timezone', 'Europe/Madrid')
        ->assertJsonPath('regional.currency', 'EUR')
        ->assertJsonPath('fiscal.country_code', 'ES');

    expect($response->json('public_id'))->not->toBe($otherTenant->public_id);
});

// CA-CORE-070 (parte 1: 401 sin sesión)
test('CA-CORE-070: sin sesión, GET /tenant/settings devuelve 401 sin datos', function (): void {
    [$tenant] = provisionCoreTenant('settings-401');

    test()->get(coreApiUrl($tenant->slug, '/tenant/settings'))
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:pge:error:unauthenticated')
        ->assertJsonMissing(['regional']);
});

// CA-CORE-019 (aplicado aquí a configuración: mismo mecanismo que usuarios)
test('CA-CORE-070: autenticado sin el permiso configuracion.leer, GET /tenant/settings devuelve 403', function (): void {
    [$tenant, $admin] = provisionCoreTenant('settings-403');

    // docente no tiene ningún permiso de REQ-CORE (permisos.md §4.1).
    $docente = app(TenantContext::class)->runFor($tenant->id, function () use ($admin) {
        $person = App\Models\Person::factory()->create(['contact_email' => 'docente@example.com']);
        $user = App\Models\User::factory()->for($person)->create(['email' => 'docente@example.com']);
        $role = App\Models\Role::where('code', 'docente')->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    });

    test()->actingAs($docente)
        ->get(coreApiUrl($tenant->slug, '/tenant/settings'))
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:pge:error:forbidden');
});

// CA-CORE-007
test('CA-CORE-007: GET /tenant/branding sin sesión devuelve solo nombre, colores, activos e idiomas', function (): void {
    [$tenant] = provisionCoreTenant('branding-uno');

    $response = test()->get(coreApiUrl($tenant->slug, '/tenant/branding'))
        ->assertOk();

    $response->assertJsonStructure([
        'name', 'color_primary', 'color_secondary', 'logo_url', 'favicon_url',
        'login_background_url', 'default_locale', 'active_locales',
    ])->assertJsonPath('name', $tenant->name)
        ->assertJsonPath('default_locale', 'es-ES');

    foreach (['status', 'tax_id', 'legal_name', 'fiscal', 'modules', 'users_count'] as $forbiddenKey) {
        expect($response->json($forbiddenKey))->toBeNull();
    }
});

test('GET /tenant devuelve la identidad del centro sin la clave interna', function (): void {
    [$tenant, $admin] = provisionCoreTenant('tenant-show');

    test()->actingAs($admin)
        ->get(coreApiUrl($tenant->slug, '/tenant'))
        ->assertOk()
        ->assertJsonPath('slug', $tenant->slug)
        ->assertJsonPath('status', 'activo')
        ->assertJsonMissing(['id']);
});
