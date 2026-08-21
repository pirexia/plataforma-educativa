<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * @return array{0: \App\Support\Tenancy\Tenant, 1: \App\Models\User}
 */
function provisionCoreTenant(?string $slug = null): array
{
    test()->artisan('platform:sync-registry')->run();

    $tenant = \App\Support\Tenancy\Tenant::factory()->create($slug !== null ? ['slug' => $slug] : []);

    // phpunit.xml fuerza CACHE_STORE=redis para los tests (a propósito:
    // así se prueba el aislamiento real de prefijo de tenant, ADR-033 §9,
    // que el driver 'array' no ejercitaría). Redis sobrevive entre
    // invocaciones del proceso — a diferencia de 'array' — así que un
    // slug reutilizado en ejecuciones sucesivas dentro de los 60s de TTL
    // de ResolveTenant leería el tenant_id de una tanda anterior ya
    // borrada. Mismo motivo y mismo remedio que
    // tests/Feature/Tenancy/ResolveTenantMiddlewareTest.php.
    \Illuminate\Support\Facades\Cache::forget("tenant-resolution:{$tenant->slug}");

    app(\App\Modules\Core\Application\ProvisionTenantDefaults::class)->provision(
        $tenant,
        'admin@example.com',
        'Ana',
        'Perez',
    );

    $admin = app(\App\Support\Tenancy\TenantContext::class)->runFor(
        $tenant->id,
        fn () => \App\Models\User::query()->where('email', 'admin@example.com')->firstOrFail(),
    );

    return [$tenant, $admin];
}
