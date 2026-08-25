<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * REQ-CORE (paso 1.1). ADR-014/ADR-033 §2: toda ruta de negocio resuelve
 * tenant por host, así que un test HTTP de un endpoint de `/api/v1`
 * siempre pide una URL con el subdominio del tenant, nunca "localhost".
 */
function coreApiUrl(string $slug, string $path): string
{
    return 'http://'.$slug.'.'.config('tenancy.base_domain').'/api/v1'.$path;
}

/**
 * Aprovisiona un tenant completo (`tenant:provision-defaults`, funcional.md
 * §4.7) para los tests HTTP de REQ-CORE: siembra los 16 roles, sus
 * permisos y el primer Administrador de Centro. Requiere
 * `platform:sync-registry` (permission_role.permission_code es FK de
 * `permissions`) — se ejecuta aquí, no en cada test.
 *
 * @return array{0: Tenant, 1: User}
 */
function provisionCoreTenant(?string $slug = null): array
{
    test()->artisan('platform:sync-registry')->run();

    $tenant = Tenant::factory()->create($slug !== null ? ['slug' => $slug] : []);

    // phpunit.xml fuerza CACHE_STORE=redis para los tests (a propósito:
    // así se prueba el aislamiento real de prefijo de tenant, ADR-033 §9,
    // que el driver 'array' no ejercitaría). Redis sobrevive entre
    // invocaciones del proceso — a diferencia de 'array' — así que un
    // slug reutilizado en ejecuciones sucesivas dentro de los 60s de TTL
    // de ResolveTenant leería el tenant_id de una tanda anterior ya
    // borrada. Mismo motivo y mismo remedio que
    // tests/Feature/Tenancy/ResolveTenantMiddlewareTest.php.
    Cache::forget("tenant-resolution:{$tenant->slug}");

    app(ProvisionTenantDefaults::class)->provision(
        $tenant,
        'admin@example.com',
        'Ana',
        'Perez',
    );

    $admin = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => User::query()->where('email', 'admin@example.com')->firstOrFail(),
    );

    return [$tenant, $admin];
}

/**
 * REQ-AUTH (paso 1.2), permisos.md §1: "REQ-AUTH es, casi entero, un
 * módulo sin permisos" — siete de nueve endpoints son anónimos o se
 * autorizan por identidad/posesión de token. Para esos, sembrar los 16
 * roles de `provisionCoreTenant()` es trabajo innecesario que ralentiza
 * la suite sin cubrir nada nuevo. Crea un tenant + un usuario `activo`
 * con contraseña conocida y válida contra la política (RN-AUTH-01),
 * sin `platform:sync-registry` ni roles.
 *
 * Usa `provisionCoreTenant()` en su lugar para los dos endpoints de
 * `bloqueo_cuenta` (`GET`/`DELETE /account-lockouts`), que sí exigen
 * permiso.
 *
 * @param  array<string, mixed>  $userAttrs  Sobrescribe atributos de User;
 *                                           'raw_password' fija la
 *                                           contraseña en claro devuelta.
 * @param  array<string, mixed>  $personAttrs
 * @return array{0: Tenant, 1: User, 2: string}
 */
function provisionActiveUser(?string $slug = null, array $userAttrs = [], array $personAttrs = []): array
{
    $tenant = Tenant::factory()->create($slug !== null ? ['slug' => $slug] : []);

    // Mismo motivo que provisionCoreTenant(): CACHE_STORE=redis en tests
    // (ADR-033 §9) sobrevive entre invocaciones del proceso.
    Cache::forget("tenant-resolution:{$tenant->slug}");

    $rawPassword = $userAttrs['raw_password'] ?? 'Cl4v3-Correcta-2026!';
    unset($userAttrs['raw_password']);

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($userAttrs, $personAttrs, $rawPassword) {
        $person = Person::factory()->create($personAttrs);

        return User::factory()->for($person)->create([
            'password' => $rawPassword,
            'status' => UserStatus::Activo,
            ...$userAttrs,
        ]);
    });

    return [$tenant, $user, $rawPassword];
}
