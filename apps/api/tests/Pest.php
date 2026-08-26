<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\MfaMethod;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
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

/**
 * Issue #83 (severidad Media, CLAUDE.md §5). El `Store` de sesión
 * ('session', 'session.store') y los `Guard` de autenticación se
 * cachean como singletons del contenedor durante todo el proceso de
 * test — `Illuminate\Session\SessionServiceProvider` registra
 * 'session.store' aparte de `SessionManager::$drivers`, y
 * `AuthManager::createSessionDriver()` resuelve el guard contra ESE
 * binding. Sin este reseteo, una llamada HTTP posterior en el MISMO
 * test (otro login, otra cookie, o incluso una
 * `Model::factory()->create()` fuera de una petición HTTP que dispare
 * `RecordsAuthorship`) puede leer el actor autenticado de una petición
 * anterior en vez de la actual — verificado con instrumentación
 * directa (DB::listen, logging de `session()->getId()`) durante la
 * implementación de 1.2b, al escribir tests que necesitaban distinguir
 * varias sesiones simultáneas del mismo usuario. En producción esto no
 * ocurre: cada petición PHP-FPM es un proceso nuevo. Llamar antes de
 * cualquier segunda petición/identidad dentro del mismo test que
 * dependa de sesión o de `Auth::user()`.
 */
function resetSessionState(): void
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app('auth')->forgetGuards();

    // REQ-AUTH-003 (1.3), hallazgo propio: `withSessionCookie()` (más
    // abajo) deja la cookie adjunta en `$this->defaultCookies`/
    // `unencryptedCookies` del propio TestCase — Laravel no la olvida
    // sola entre peticiones (`withCookie()`/`withUnencryptedCookie()`
    // mutan esas propiedades, no hay `withoutCookies()` público). Sin
    // este reseteo, un test que abre varios desafíos de MFA en sesiones
    // "anónimas" sucesivas (un bucle de intentos fallidos, por ejemplo)
    // en realidad reenvía la cookie de la ÚLTIMA sesión ya autenticada
    // o con desafío, y el segundo intento choca con el índice único
    // `mfa_challenges_tenant_session_live_unique` en vez de abrir un
    // desafío nuevo de verdad — un fallo silencioso y confuso (una
    // `UniqueConstraintViolationException` de 500, no el 401/202 que el
    // test espera). Sin propiedad pública para limpiarlas: Reflection,
    // solo en infraestructura de test.
    // test() devuelve Pest\Support\HigherOrderTapProxy, no el TestCase de
    // PHPUnit — reflejar el proxy ve sus propias propiedades (ninguna
    // coincide con 'defaultCookies'), así que la comprobación hasProperty()
    // callaba en silencio sin limpiar nada. El TestCase real es ->target.
    $testCase = test()->target;
    $class = new ReflectionClass($testCase);

    foreach (['defaultCookies', 'unencryptedCookies'] as $property) {
        if ($class->hasProperty($property)) {
            $prop = $class->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($testCase, []);
        }
    }
}

/**
 * Valor RAW (ya cifrado) de la cookie de sesión de una respuesta —
 * `Set-Cookie` tal cual, listo para reenviar con `withSessionCookie()`.
 */
function sessionCookieValue($response): string
{
    return $response->getCookie(config('session.cookie'), false)->getValue();
}

/**
 * Adjunta una cookie de sesión YA CIFRADA (de un `Set-Cookie` anterior) a
 * la siguiente llamada del cliente de test. `MakesHttpRequests::json()`
 * (usado por `postJson()`/`getJson()`/`deleteJson()`) descarta TODAS las
 * cookies salvo que se llame antes a `withCredentials()`, y `withCookie()`
 * cifraría de nuevo un valor que ya viene cifrado — `withUnencryptedCookie()`
 * lo envía tal cual, que es lo correcto aquí. Ver issue #83: el patrón
 * `withCookie() + postJson/getJson/deleteJson()` sin `withCredentials()`
 * previo no transmite la cookie en absoluto.
 */
function withSessionCookie(string $cookieValue)
{
    resetSessionState();

    return test()->withCredentials()->withUnencryptedCookie(config('session.cookie'), $cookieValue);
}

/**
 * Login real por HTTP (1.2b). `resetSessionState()` primero, para que un
 * login posterior en el mismo test no herede el `Guard`/`Store` de uno
 * anterior (ver `resetSessionState()`).
 */
function loginFor(string $slug, string $email, string $password, ?string $userAgent = null)
{
    resetSessionState();

    $request = test();

    if ($userAgent !== null) {
        $request = $request->withHeader('User-Agent', $userAgent);
    }

    return $request->postJson(coreApiUrl($slug, '/auth/session'), ['email' => $email, 'password' => $password])
        ->assertOk();
}

/**
 * REQ-AUTH-003 (1.3). Crea un factor TOTP ya confirmado para `$user`
 * directamente (sin pasar por el flujo de alta HTTP), dentro del
 * contexto del tenant indicado (RLS). Devuelve el secreto en base32 para
 * poder generar códigos válidos con `currentTotpCode()`.
 */
function createConfirmedTotpFactor(Tenant $tenant, User $user, bool $preferred = false): string
{
    $secret = (new Google2FA)->generateSecretKey(32);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $secret, $preferred): void {
        MfaFactor::create([
            'user_id' => $user->id,
            'method' => MfaMethod::Totp,
            'secret_encrypted' => $secret,
            'confirmed_at' => now(),
            'is_preferred' => $preferred,
        ]);
    });

    return $secret;
}

/**
 * Código TOTP válido para el instante actual, a partir de un secreto en
 * base32 (`ADR-041`: mismo motor que `Google2FaTotpVerifier`).
 */
function currentTotpCode(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

/**
 * REQ-AUTH-003 (1.3). Login completo en dos pasos con un factor TOTP ya
 * confirmado (`createConfirmedTotpFactor()`): paso 1 (202, sin cookie
 * reutilizable todavía) + paso 2 con el código correcto. Devuelve la
 * respuesta final (200, con la cookie de sesión YA autenticada/regenerada
 * — `sessionCookieValue()` sobre ella da la cookie válida para peticiones
 * posteriores). `resetSessionState()` primero, igual que `loginFor()`.
 */
function loginWithTotpFor(string $slug, string $email, string $password, string $secret)
{
    $challenge = openMfaChallengeFor($slug, $email, $password);

    return withSessionCookie(sessionCookieValue($challenge))
        ->postJson(coreApiUrl($slug, '/auth/mfa-verifications'), ['code' => currentTotpCode($secret)])
        ->assertOk();
}

/**
 * REQ-AUTH-003 (1.3). Paso 1 del login en dos pasos (`§C.4.4`): abre el
 * desafío y devuelve la respuesta `202` — `sessionCookieValue()` sobre
 * ella da la cookie de la sesión ANÓNIMA a la que el desafío está ligado
 * (RN-AUTH-53), imprescindible para el paso 2 (`POST /auth/mfa-challenges`
 * o `POST /auth/mfa-verifications`): sin reenviarla, el paso 2 llega con
 * una sesión anónima distinta y responde `410` (desafío "inexistente").
 */
function openMfaChallengeFor(string $slug, string $email, string $password)
{
    resetSessionState();

    return test()->postJson(coreApiUrl($slug, '/auth/session'), [
        'email' => $email, 'password' => $password,
    ])->assertStatus(202);
}
