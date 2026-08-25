<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
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
    $docente = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create(['contact_email' => 'docente@example.com']);
        $user = User::factory()->for($person)->create(['email' => 'docente@example.com']);
        $role = Role::where('code', 'docente')->firstOrFail();
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

// CA-CORE-002
test('CA-CORE-002: PATCH /tenant/settings con default_locale fuera de active_locales devuelve 422 y no modifica nada', function (): void {
    [$tenant, $admin] = provisionCoreTenant('settings-patch-002');

    $response = test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
            'regional' => ['default_locale' => 'de'],
        ])
        ->assertStatus(422);

    expect($response->json('type'))->toBe('urn:pge:error:validation');

    test()->actingAs($admin)
        ->get(coreApiUrl($tenant->slug, '/tenant/settings'))
        ->assertJsonPath('regional.default_locale', 'es-ES');
});

// CA-CORE-003
test('CA-CORE-003: PATCH /tenant/settings con paleta de contraste insuficiente devuelve 422 con ratio y mínimo, sin guardar', function (): void {
    [$tenant, $admin] = provisionCoreTenant('settings-patch-003');

    // Dos grises casi idénticos: contraste ~1:1, muy por debajo de 4.5:1.
    $response = test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
            'branding' => ['color_primary' => '#777777', 'color_secondary' => '#787878'],
        ])
        ->assertStatus(422);

    $response->assertJsonPath('type', 'urn:pge:error:validation');
    $errorParams = $response->json('errors.branding.0.params');
    expect($errorParams['required'])->toBe(4.5)
        ->and($errorParams['ratio'])->toBeLessThan(4.5);

    test()->actingAs($admin)
        ->get(coreApiUrl($tenant->slug, '/tenant/settings'))
        ->assertJsonPath('branding.color_primary', null);
});

// CA-CORE-004
test('CA-CORE-004: un cambio válido de configuración se audita y la caché se invalida', function (): void {
    [$tenant, $admin] = provisionCoreTenant('settings-patch-004');

    // Precalienta la caché de configuración (RN-CORE-17: debe invalidarse
    // en la escritura, no seguir sirviendo el valor viejo).
    test()->actingAs($admin)->get(coreApiUrl($tenant->slug, '/tenant/settings'));

    $response = test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
            'regional' => ['timezone' => 'Europe/Berlin'],
        ])
        ->assertOk();

    $response->assertJsonPath('regional.timezone', 'Europe/Berlin')
        // Fusión en profundidad (ADR-038 §9.2 regla 4): currency no se toca.
        ->assertJsonPath('regional.currency', 'EUR');

    test()->actingAs($admin)
        ->get(coreApiUrl($tenant->slug, '/tenant/settings'))
        ->assertJsonPath('regional.timezone', 'Europe/Berlin');

    $log = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => AuditLog::where('auditable_type', 'tenant_setting')->where('event', 'updated')->latest('id')->first(),
    );

    expect($log)->not->toBeNull()
        ->and($log->changes['timezone'])->toEqual(['from' => 'Europe/Madrid', 'to' => 'Europe/Berlin']);
});
