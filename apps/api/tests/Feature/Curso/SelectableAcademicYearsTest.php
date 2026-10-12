<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001, 1.11-a. CA-ACAD-043 (issue #409, OPEN-ACAD-11):
// GET /academic-years/selectable, autoservicio sin permiso para el selector de
// curso académico. INV-001, INV-002, INV-015. La ampliación de la lista
// cerrada de AR-07a la comprueba RouteAuthorizationTest.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/** Usuario activo del centro sin ningún rol (sin ningún permiso, RPERM-011). */
function plainUserOf(object $tenant, string $local): User
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($local): User {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create([
            'email' => "{$local}@example.com",
            'status' => UserStatus::Activo,
        ]);
    });
}

/** Usuario con un rol personalizado que concede solo `curso_historico.leer`. */
function historyReaderOf(object $tenant, User $admin, string $local): User
{
    resetSessionState();
    $roleId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'code' => "rol_{$local}",
        'name' => "Rol {$local}",
        'permissions' => [['code' => 'curso_historico.leer', 'effect' => 'allow', 'scope' => 'todos']],
    ])->assertCreated()->json('public_id');

    resetSessionState();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => "{$local}@example.com",
        'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
        'send_invitation' => false,
        'role_ids' => [$roleId],
    ])->assertCreated();

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($local): User {
        $user = User::query()->where('email', "{$local}@example.com")->firstOrFail();
        $user->status = UserStatus::Activo;
        $user->save();

        return $user;
    });

    resetSessionState();

    return $user;
}

test('CA-ACAD-043 #409: un usuario sin ningún permiso de curso recibe solo activo y planificacion, orden -starts_on', function (): void {
    [$tenant] = provisionCoreTenant('acad-043a');
    CursoTestHelpers::year($tenant, '2023-2024', AcademicYearStatus::Archivado, '2023-09-01', '2024-06-30');
    CursoTestHelpers::year($tenant, '2024-2025', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');
    CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-06-30');
    $plain = plainUserOf($tenant, 'plano');

    $response = test()->actingAs($plain)->getJson(coreApiUrl($tenant->slug, '/academic-years/selectable'))
        ->assertOk();

    expect(array_column($response->json('data'), 'code'))->toBe(['2026-2027', '2025-2026'])
        ->and(array_column($response->json('data'), 'status'))->toBe(['planificacion', 'activo'])
        ->and($response->json('data.0'))->toHaveKeys(['public_id', 'code', 'starts_on', 'ends_on', 'status', 'created_at', 'updated_at'])
        ->and($response->json('data.0'))->not->toHaveKeys(['id', 'tenant_id', 'created_by', 'updated_by', 'deleted_at']);

    // La consulta de lista ordinaria les sigue denegada: el selector no la sustituye.
    test()->actingAs($plain)->getJson(coreApiUrl($tenant->slug, '/academic-years'))->assertForbidden();
});

test('CA-ACAD-043 #409 RN-CURSO-25: con curso_historico.leer también recibe cerrado y archivado', function (): void {
    [$tenant, $admin] = provisionCoreTenant('acad-043b');
    CursoTestHelpers::year($tenant, '2023-2024', AcademicYearStatus::Archivado, '2023-09-01', '2024-06-30');
    CursoTestHelpers::year($tenant, '2024-2025', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');
    CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-06-30');
    $reader = historyReaderOf($tenant, $admin, 'historico');

    $response = test()->actingAs($reader)->getJson(coreApiUrl($tenant->slug, '/academic-years/selectable'))->assertOk();

    expect(array_column($response->json('data'), 'code'))->toBe(['2026-2027', '2025-2026', '2024-2025', '2023-2024']);
});

test('CA-ACAD-043 INV-001: nunca devuelve cursos de otro centro', function (): void {
    [$tenantA] = provisionCoreTenant('acad-043c');
    [$tenantB, $adminB] = provisionCoreTenant('acad-043d');
    CursoTestHelpers::year($tenantA, 'curso-de-A', AcademicYearStatus::Activo);
    CursoTestHelpers::year($tenantB, 'curso-de-B', AcademicYearStatus::Activo);
    CursoTestHelpers::year($tenantB, 'cerrado-de-B', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    $plainA = plainUserOf($tenantA, 'planoa');

    $codes = array_column(test()->actingAs($plainA)->getJson(coreApiUrl($tenantA->slug, '/academic-years/selectable'))->assertOk()->json('data'), 'code');

    expect($codes)->toBe(['curso-de-A']);

    // Y el administrador (con curso_historico.leer) de B solo ve los de B.
    $codesB = array_column(test()->actingAs($adminB)->getJson(coreApiUrl($tenantB->slug, '/academic-years/selectable'))->assertOk()->json('data'), 'code');

    expect($codesB)->toEqualCanonicalizing(['curso-de-B', 'cerrado-de-B']);
});

test('CA-ACAD-043 INV-002: sin sesión responde 401', function (): void {
    [$tenant] = provisionCoreTenant('acad-043e');

    test()->getJson(coreApiUrl($tenant->slug, '/academic-years/selectable'))->assertUnauthorized();
});

test('CA-ACAD-043: la ruta no se confunde con /{publicId} (devuelve la lista, no un 404 de curso)', function (): void {
    [$tenant, $admin] = provisionCoreTenant('acad-043f');

    test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/academic-years/selectable'))
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('CA-ACAD-043 INV-001 (#416): la cookie de sesión de un centro presentada en el host de otro no devuelve sus cursos (401)', function (): void {
    [$tenantA, $userA, $passwordA] = provisionActiveUser('acad-043g');
    [$tenantB] = provisionCoreTenant('acad-043h');
    CursoTestHelpers::year($tenantA, 'curso-secreto-de-A', AcademicYearStatus::Activo);
    CursoTestHelpers::year($tenantB, 'curso-de-B', AcademicYearStatus::Activo);

    // Sesión real de un usuario del centro A.
    $cookie = sessionCookieValue(loginFor($tenantA->slug, $userA->email, $passwordA));

    // Sanidad: en su propio host la sesión sirve.
    withSessionCookie($cookie);
    test()->getJson(coreApiUrl($tenantA->slug, '/academic-years/selectable'))->assertOk()->assertJsonPath('data.0.code', 'curso-secreto-de-A');

    // La misma cookie en el host del centro B.
    withSessionCookie($cookie);
    $response = test()->getJson(coreApiUrl($tenantB->slug, '/academic-years/selectable'));

    // Comportamiento real: el usuario de A no existe para el ámbito de B (`TenantScope`), así que la petición es anónima.
    $response->assertUnauthorized();

    expect($response->getContent())->not->toContain('curso-secreto-de-A')
        ->and($response->getContent())->not->toContain('curso-de-B');
});

test('CA-ACAD-043 INV-001 (#416): defensa en profundidad: aunque Auth::user() resolviera al usuario de A, verify-session-tenant responde 401 en el host de B sin cursos', function (): void {
    [$tenantA, $userA, $passwordA] = provisionActiveUser('acad-043i');
    [$tenantB] = provisionCoreTenant('acad-043j');
    CursoTestHelpers::year($tenantA, 'curso-secreto-de-A', AcademicYearStatus::Activo);
    CursoTestHelpers::year($tenantB, 'curso-de-B', AcademicYearStatus::Activo);

    $cookie = sessionCookieValue(loginFor($tenantA->slug, $userA->email, $passwordA));

    // Mismo montaje que SessionLifecycleTest (RN-AUTH-31): cookie real de A y
    // el guard forzado a A, lo que solo ocurriría si TenantScope fallara.
    resetSessionState();
    test()->withCredentials()->withUnencryptedCookie(config('session.cookie'), $cookie);
    test()->actingAs($userA);

    $response = test()->getJson(coreApiUrl($tenantB->slug, '/academic-years/selectable'));

    $response->assertUnauthorized();

    expect($response->getContent())->not->toContain('curso-secreto-de-A')
        ->and($response->getContent())->not->toContain('curso-de-B');
});
