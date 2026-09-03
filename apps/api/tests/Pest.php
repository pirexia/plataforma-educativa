<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\MfaMethod;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Infrastructure\TenantSettingsCache;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

    // Issue #110 (severidad Media, hallazgo propio de 1.3b): `Route::
    // getController()` (vendor/laravel/framework .../Routing/Route.php)
    // cachea la instancia del controlador EN EL PROPIO OBJETO Route la
    // primera vez que se resuelve, y esa Route sobrevive sin cambios
    // entre las distintas peticiones HTTP simuladas de un mismo test —
    // `forgetScopedInstances()` (AuthServiceProvider, invocado en cada
    // `Kernel::terminate()`) solo vacía el caché de bindings `scoped()`
    // del CONTENEDOR, no esta caché paralela de la Route. Consecuencia
    // real: un controlador con una dependencia `scoped()` en el
    // constructor (`SessionController` con `MfaPolicy`) memoiza esa
    // dependencia la PRIMERA vez que el test llama a su ruta y la
    // conserva en las llamadas siguientes DENTRO DEL MISMO TEST, aunque
    // el estado subyacente cambie (p. ej., confirmar un factor MFA entre
    // dos peticiones a `POST /auth/session` del mismo test) — un test
    // que encadena "sin factor" → "confirma factor" → "vuelve a pedir
    // /auth/session" ve el resultado ANTIGUO. No reproducible en
    // producción con `ADR-037` (cada petición real es un proceso PHP
    // nuevo, sin Route compartida entre peticiones), pero sí sería un
    // riesgo real si el proyecto adoptase Octane (varias peticiones
    // reales por proceso) sin este mismo arreglo — el comentario de
    // `AuthServiceProvider` que introdujo `forgetScopedInstances()` para
    // `CA-AUTH-130/131` anticipó justo ese escenario y no cubrió esta
    // caché paralela porque en 1.3 ningún test dependía de ella. Se
    // vacía aquí, no en código de producción: es infraestructura de
    // test, y `resetSessionState()` es exactamente el punto ya dedicado
    // a dejar cada petición simulada libre de residuos de la anterior.
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
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

/**
 * REQ-AUTH-003 (1.3b), funcional.md §D.0.1/§D.2.4. `mfa_allowed_methods`
 * por defecto es `["totp"]`: los tests del correo como segundo factor
 * necesitan que el tenant lo admita explícitamente. Crea la fila de
 * `tenant_settings` si `provisionActiveUser()` (sin
 * `tenant:provision-defaults`) no la dejó puesta, e invalida la caché
 * (`operacion.md §D.7`) para que la lectura siguiente vea el cambio.
 */
function enableEmailMfaMethod(Tenant $tenant): void
{
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $settings = TenantSetting::query()->first();

        if ($settings === null) {
            TenantSetting::create(['mfa_allowed_methods' => ['totp', 'email']]);
        } else {
            $settings->update(['mfa_allowed_methods' => ['totp', 'email']]);
        }

        app(TenantSettingsCache::class)->forget();
    });
}

/**
 * REQ-AUTH-003 (1.3b), funcional.md §D.4.1. Crea directamente un factor
 * `email` ya confirmado para `$user`, sin pasar por el flujo HTTP de
 * alta — análogo a `createConfirmedTotpFactor()`.
 */
function createConfirmedEmailFactor(Tenant $tenant, User $user, bool $preferred = false): void
{
    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $preferred): void {
        MfaFactor::create([
            'user_id' => $user->id,
            'method' => MfaMethod::Email,
            'confirmed_at' => now(),
            'is_preferred' => $preferred,
        ]);
    });
}

/**
 * REQ-AUTH-002 (1.4), `operacion.md §E.10.2`. Arranca `POST
 * /auth/oauth-authorizations` con el proveedor simulado y devuelve
 * `[$authorizationUrl, $sessionCookie]` — la cookie de la sesión que
 * arrancó el flujo, imprescindible para completar el *callback* con el
 * mismo `state` (`RN-AUTH-91`). `$sessionCookie` de entrada permite
 * encadenar sobre una sesión ya autenticada (`intent = 'link'`).
 *
 * @return array{0: string, 1: string}
 */
function beginFakeGoogleFlow(string $slug, string $intent = 'login', ?string $sessionCookie = null): array
{
    if ($sessionCookie !== null) {
        $client = withSessionCookie($sessionCookie);
    } else {
        // Issue #83 (ver resetSessionState()): sin esto, una sesión ya
        // autenticada de una llamada anterior en el mismo test —u otro
        // tenant— queda adjunta al cliente de test y este arranque
        // "anónimo" en realidad la reenvía, con el mismo fallo silencioso
        // que motivó ese hallazgo (VerifySessionTenant 401 al cruzar de
        // tenant, o un `state` que en realidad pertenece a otra sesión).
        resetSessionState();
        $client = test();
    }

    $begin = $client->postJson(coreApiUrl($slug, '/auth/oauth-authorizations'), [
        'provider' => 'google',
        'intent' => $intent,
    ])->assertStatus(201);

    return [$begin->json('authorization_url'), sessionCookieValue($begin)];
}

/**
 * Completa el flujo simulado enviando el formulario del proveedor
 * simulado (`FakeGoogleAuthorizationController`) y siguiendo su
 * redirección hasta el *callback* real — el mismo camino que un
 * navegador de verdad, sin usar Google. Devuelve la respuesta del
 * *callback* (siempre `302`, `RN-AUTH-93`): los tests leen `Location`
 * para el código de resultado, o encadenan la sesión resultante con
 * `sessionCookieValue()` cuando el login se completó.
 *
 * @param  array<string, mixed>  $claims
 */
function completeFakeGoogleFlow(string $authorizationUrl, string $sessionCookie, array $claims): TestResponse
{
    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    $formSubmission = array_merge(['state' => $query['state'], 'submit' => '1'], $claims);

    $authorizePath = (string) parse_url($authorizationUrl, PHP_URL_PATH);

    $redirectToCallback = withSessionCookie($sessionCookie)
        ->get($authorizePath.'?'.http_build_query($formSubmission))
        ->assertRedirect();

    $callbackUrl = $redirectToCallback->headers->get('Location');

    return withSessionCookie(sessionCookieValue($redirectToCallback))
        ->get($callbackUrl)
        ->assertRedirect();
}

/**
 * REQ-AUTH-004 (1.4b). La URL de descubrimiento del emisor OIDC
 * simulado (`operacion.md §F.10`), alcanzable desde dentro del propio
 * contenedor de la API. Constante global de test: la usan varios
 * ficheros de `tests/Feature/Auth`.
 */
const OIDC_DISCOVERY_URL = 'http://localhost:8000/api/_sso-simulator/.well-known/openid-configuration';

/**
 * REQ-AUTH-004 (1.4b), operacion.md §F.10. Da de alta y activa un
 * proveedor OIDC catalogado que apunta al emisor simulado servido por la
 * propia API — el mismo host que atiende la petición de test, así que
 * `CurlDiscoveryDocumentValidator` lo descarga de verdad (AUTH_SSO_
 * ALLOW_INSECURE_DISCOVERY=true en local/testing afloja las guardas 1 y
 * 2 para permitirlo, operacion.md §F.2.1). Requiere `$admin` con los
 * cuatro permisos `proveedor_identidad.*` (`provisionCoreTenant()`).
 *
 * @param  array<string, mixed>  $overrides  Campos del cuerpo de POST /identity-providers a sustituir.
 * @return array{public_id: string, secret_public_id: string}
 */
function createActiveOidcProvider(string $slug, User $admin, array $overrides = []): array
{
    resetSessionState();

    $body = array_merge([
        // REQ-AUTH-004 (1.4c), api.md §G.2: protocol es obligatorio desde
        // 1.4c y sin valor por defecto en la API.
        'protocol' => 'oidc',
        'display_name' => 'Proveedor OIDC de prueba',
        'discovery_url' => 'http://localhost:8000/api/_sso-simulator/.well-known/openid-configuration',
        'client_id' => 'test-client-'.Str::random(8),
        'provisioning_mode' => 'emparejamiento',
    ], $overrides);

    $store = test()->actingAs($admin)
        ->postJson(coreApiUrl($slug, '/identity-providers'), $body)
        ->assertStatus(201);

    $publicId = $store->json('public_id');

    $secret = test()->actingAs($admin)
        ->postJson(coreApiUrl($slug, "/identity-providers/{$publicId}/secrets"), [
            'client_secret' => 'test-client-secret-'.Str::random(16),
        ])
        ->assertStatus(201);

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($slug, "/identity-providers/{$publicId}"), ['is_enabled' => true])
        ->assertOk();

    resetSessionState();

    return ['public_id' => $publicId, 'secret_public_id' => $secret->json('public_id')];
}

/**
 * REQ-AUTH-004 (1.4b). Tenant con `provisionCoreTenant()` (permisos
 * `proveedor_identidad.*`), un usuario `activo` con correo conocido, y
 * un proveedor OIDC catalogado, activado y con credencial vigente, listo
 * para completar un login (`Person`/`User::factory()->for()`).
 *
 * @return array{0: Tenant, 1: User, 2: string}
 */
function provisionOidcTenantWithActiveUser(string $slug, array $userAttrs = []): array
{
    [$tenant, $admin] = provisionCoreTenant($slug);

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($userAttrs) {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(array_merge([
            'status' => UserStatus::Activo,
        ], $userAttrs));
    });

    $provider = createActiveOidcProvider($slug, $admin);

    return [$tenant, $user, $provider['public_id']];
}

/**
 * REQ-AUTH-004 (1.4b). Arranca el flujo institucional — paralelo de
 * `beginFakeGoogleFlow()`.
 *
 * @return array{0: string, 1: string}
 */
function beginOidcFlow(string $slug, string $providerPublicId, string $intent = 'login', ?string $sessionCookie = null): array
{
    if ($sessionCookie !== null) {
        $client = withSessionCookie($sessionCookie);
    } else {
        resetSessionState();
        $client = test();
    }

    $begin = $client->postJson(coreApiUrl($slug, '/auth/oauth-authorizations'), [
        'provider' => $providerPublicId,
        'intent' => $intent,
    ])->assertStatus(201);

    return [$begin->json('authorization_url'), sessionCookieValue($begin)];
}

/**
 * REQ-AUTH-004 (1.4b). Completa el flujo enviando el formulario del
 * emisor simulado (`FakeOidcIssuerController`) — a diferencia de
 * `completeFakeGoogleFlow()`, reenvía **todos** los parámetros de la URL
 * de autorización (`client_id`, `redirect_uri`, `nonce`, `code_challenge`),
 * porque el emisor simulado es genérico y no conoce de antemano la ruta
 * de *callback* de este producto.
 *
 * @param  array<string, mixed>  $claims
 */
function completeOidcFlow(string $authorizationUrl, string $sessionCookie, array $claims): TestResponse
{
    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    $formSubmission = array_merge($query, ['submit' => '1'], $claims);

    $authorizePath = (string) parse_url($authorizationUrl, PHP_URL_PATH);

    // El emisor simulado vive fuera del grupo de rutas con sesión
    // (routes/api.php: es un emisor de plataforma, no del tenant) y no
    // devuelve ninguna cookie propia — a diferencia de
    // FakeGoogleAuthorizationController, que sí está dentro de ese
    // grupo. Se reutiliza la misma cookie con la que arrancó el flujo.
    $redirectToCallback = withSessionCookie($sessionCookie)
        ->get($authorizePath.'?'.http_build_query($formSubmission))
        ->assertRedirect();

    $callbackUrl = $redirectToCallback->headers->get('Location');

    return withSessionCookie($sessionCookie)
        ->get($callbackUrl)
        ->assertRedirect();
}

/**
 * REQ-AUTH-004 (1.4b), paralelo de `loginWithFakeGoogleFor()`.
 *
 * @param  array<string, mixed>  $claims
 */
function loginWithOidcFor(string $slug, string $providerPublicId, array $claims): string
{
    [$authorizationUrl, $beginCookie] = beginOidcFlow($slug, $providerPublicId);
    $callback = completeOidcFlow($authorizationUrl, $beginCookie, $claims);

    expect(oauthCallbackResultCode($callback))->toBeNull();

    return sessionCookieValue($callback);
}

/**
 * Código de resultado (`resultado=<código>`) de la URL de destino de una
 * respuesta del *callback* (`302`), o `null` si la redirección no lleva
 * ninguno (login completado, `api.md §E.4.2`).
 */
function oauthCallbackResultCode($response): ?string
{
    $location = $response->headers->get('Location');
    $query = [];
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query['resultado'] ?? null;
}

/**
 * `operacion.md §E.10.2`, login completo en un solo paso con el
 * proveedor simulado: arranca, completa el formulario y sigue el
 * *callback* hasta la sesión ya autenticada (sin desafío de MFA de por
 * medio). Devuelve la cookie de la sesión autenticada resultante.
 *
 * @param  array<string, mixed>  $claims
 */
function loginWithFakeGoogleFor(string $slug, array $claims): string
{
    [$authorizationUrl, $beginCookie] = beginFakeGoogleFlow($slug);
    $callback = completeFakeGoogleFlow($authorizationUrl, $beginCookie, $claims);

    expect(oauthCallbackResultCode($callback))->toBeNull();

    return sessionCookieValue($callback);
}

/**
 * REQ-AUTH-004 (1.4c), operacion.md §G.10. La URL de metadatos del IdP
 * SAML simulado (`FakeSamlIdentityProviderController::metadata()`),
 * alcanzable desde dentro del propio contenedor de la API — paralela de
 * `OIDC_DISCOVERY_URL`. `AUTH_SAML_ALLOW_INSECURE_METADATA=true` en
 * local/testing (phpunit.xml) afloja la guarda de esquema para permitir
 * `http` sobre este host.
 */
const SAML_FAKE_IDP_METADATA_URL = 'http://localhost:8000/api/_sso-simulator/saml/metadata';

/**
 * La URL de SSO del IdP SAML simulado, sin *query*
 * (`FakeSamlIdentityProviderController::sso()`). Los tests la recomponen
 * con el `SAMLRequest` real (de `beginSamlFlow()`) más los parámetros de
 * `operacion.md §G.10.2` que fuerzan una aserción rota.
 */
const SAML_FAKE_IDP_SSO_URL = 'http://localhost:8000/api/_sso-simulator/saml/sso';

/**
 * REQ-AUTH-004 (1.4c), operacion.md §G.10. Da de alta y activa un
 * proveedor SAML catalogado que apunta al IdP simulado servido por la
 * propia API — hermana de `createActiveOidcProvider()`. El origen es
 * `metadata_url` (no XML pegado): así se ejercita también
 * `CurlSamlMetadataValidator` de verdad, mismo criterio que
 * `createActiveOidcProvider()` con `discovery_url`.
 *
 * El IdP simulado declara tres `NameIDFormat` en sus metadatos y
 * `CurlSamlMetadataValidator::extractNameIdFormat()` se queda con el
 * **primero** de la lista blanca que encuentra: `persistent`
 * (`FakeSamlIdentityProviderController::metadata()`). Con
 * `name_id_format = persistent`, el `CHECK` de `datos.md §G.3` exige
 * `email_attribute` — se fija por defecto a `'mail'`, el atributo que
 * `completeSamlFlow()` rellena en la aserción vía `attribute_name`/
 * `attribute_value` para que el emparejamiento tenga de dónde sacar el
 * correo.
 *
 * Requiere `$admin` con los cuatro permisos `proveedor_identidad.*`
 * (`provisionCoreTenant()`).
 *
 * @param  array<string, mixed>  $overrides  Campos del cuerpo de POST /identity-providers a sustituir.
 * @return array{public_id: string}
 */
function createActiveSamlProvider(string $slug, User $admin, array $overrides = []): array
{
    resetSessionState();

    $body = array_merge([
        'protocol' => 'saml',
        'display_name' => 'Proveedor SAML de prueba',
        'metadata_url' => SAML_FAKE_IDP_METADATA_URL,
        'email_attribute' => 'mail',
        'provisioning_mode' => 'emparejamiento',
    ], $overrides);

    $store = test()->actingAs($admin)
        ->postJson(coreApiUrl($slug, '/identity-providers'), $body)
        ->assertStatus(201);

    $publicId = $store->json('public_id');

    test()->actingAs($admin)
        ->patchJson(coreApiUrl($slug, "/identity-providers/{$publicId}"), ['is_enabled' => true])
        ->assertOk();

    resetSessionState();

    return ['public_id' => $publicId];
}

/**
 * REQ-AUTH-004 (1.4c). Tenant con `provisionCoreTenant()` (permisos
 * `proveedor_identidad.*`), un usuario `activo` con correo conocido, y
 * un proveedor SAML catalogado, activado y con certificado de firma
 * vigente, listo para completar un login — hermana de
 * `provisionOidcTenantWithActiveUser()`.
 *
 * @return array{0: Tenant, 1: User, 2: string}
 */
function provisionSamlTenantWithActiveUser(string $slug, array $userAttrs = []): array
{
    [$tenant, $admin] = provisionCoreTenant($slug);

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($userAttrs) {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(array_merge([
            'status' => UserStatus::Activo,
        ], $userAttrs));
    });

    $provider = createActiveSamlProvider($slug, $admin);

    return [$tenant, $user, $provider['public_id']];
}

/**
 * REQ-AUTH-004 (1.4c), funcional.md §G.4.3. Arranca `POST
 * /auth/oauth-authorizations` con un proveedor SAML — paralela de
 * `beginOidcFlow()`. A diferencia de OIDC, el arranque SAML **no
 * escribe nada en la sesión** (`§G.0.4`, `RN-AUTH-114`): la fila de
 * correlación vive en `saml_auth_requests`. `$sessionCookie` de entrada
 * sigue haciendo falta para `intent = 'link'` (exige sesión autenticada
 * en el momento de emitir la petición, `§G.4.4`) y para no arrastrar la
 * sesión de una llamada anterior en el mismo test (issue #83, ver
 * `resetSessionState()`).
 *
 * @return array{0: string, 1: string} [authorization_url, session_cookie]
 */
function beginSamlFlow(string $slug, string $providerPublicId, string $intent = 'login', ?string $sessionCookie = null): array
{
    if ($sessionCookie !== null) {
        $client = withSessionCookie($sessionCookie);
    } else {
        resetSessionState();
        $client = test();
    }

    $begin = $client->postJson(coreApiUrl($slug, '/auth/oauth-authorizations'), [
        'provider' => $providerPublicId,
        'intent' => $intent,
    ])->assertStatus(201);

    return [$begin->json('authorization_url'), sessionCookieValue($begin)];
}

/**
 * REQ-AUTH-004 (1.4c), operacion.md §G.10.2. Completa el flujo SAML
 * simulado: llama al `GET` de SSO del IdP simulado con el `SAMLRequest`
 * real que devolvió `beginSamlFlow()` (mismo `ID`, mismo `Destination`,
 * mismo `Issuer`) más los parámetros que fuerzan una aserción rota o
 * personalizan el sujeto (`sub`, `broken`, `name_id_format`,
 * `attribute_name`, `attribute_value`, `assertion_id_override`,
 * `acs_url_override`, `audience_override`), extrae el formulario de
 * auto-envío que produce (`SAMLResponse` + la URL del ACS de destino) y
 * lo entrega al ACS real por `POST` — **sin cookie de sesión y sin
 * token CSRF**, tal y como llega desde un navegador real tras un `POST`
 * entre sitios (`RN-AUTH-124`, `CA-AUTH-347`). Devuelve la respuesta del
 * ACS, siempre `302` (`api.md §G.7.2`).
 *
 * `resetSessionState()` justo antes del `POST` al ACS, no al principio de
 * la función: el ACS crea una sesión propia, genuinamente nueva, sin
 * relación con la de la petición `GET` de SSO que la precede (que a su
 * vez puede no llevar ninguna). Sin este reseteo, `Illuminate\Session\
 * DatabaseSessionHandler::$exists` —una bandera de instancia, no por
 * sesión— queda en `true` desde una escritura anterior de este mismo
 * test (p. ej. `provisionCoreTenant()`/`beginSamlFlow()`), y
 * `DatabaseSessionHandler::write()` se lo cree para el nuevo `id` del
 * ACS y llama a `performUpdate()` en vez de `performInsert()`: un
 * `UPDATE` de cero filas, sin excepción, sin aviso. La cookie de sesión
 * que el ACS devuelve queda "viva" a ojos del cliente de test, pero la
 * fila de `sessions` nunca llega a existir hasta la primera petición
 * posterior que sí resetea o que golpea un handler fresco — momento en
 * el que cualquier cosa guardada en el `payload` del ACS (aquí,
 * `stashPendingSsoMatch()` para el vínculo pendiente de MFA) se ha
 * perdido en silencio. Bug real encontrado con `CA-AUTH-354`, mismo
 * mecanismo general que el issue #83 ya documentado en
 * `resetSessionState()`, pero disparado por la reutilización del
 * handler entre sesiones distintas, no por la cookie adjunta al cliente.
 *
 * @param  array<string, mixed>  $params
 */
function completeSamlFlow(string $authorizationUrl, array $params = []): TestResponse
{
    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    $ssoUrl = SAML_FAKE_IDP_SSO_URL.'?'.http_build_query(array_merge($query, $params));

    $form = test()->get($ssoUrl)->assertOk();

    $acsUrl = samlAutoSubmitFormValue($form->getContent(), 'action');
    $samlResponseB64 = samlAutoSubmitFormValue($form->getContent(), 'SAMLResponse');

    resetSessionState();

    return test()->post($acsUrl, ['SAMLResponse' => $samlResponseB64])->assertRedirect();
}

/**
 * Extrae, del HTML de auto-envío de `FakeSamlIdentityProviderController::
 * sso()`, el destino del formulario (`$name = 'action'`) o el valor de
 * uno de sus campos ocultos (`$name` = nombre del campo, p. ej.
 * `'SAMLResponse'`). Infraestructura de test, no cliente SAML genérico:
 * el HTML lo produce siempre el mismo controlador con la misma forma.
 */
function samlAutoSubmitFormValue(string $html, string $name): string
{
    if ($name === 'action') {
        preg_match('/<form method="post" action="([^"]*)"/', $html, $matches);
    } else {
        preg_match('/name="'.preg_quote($name, '/').'" value="([^"]*)"/', $html, $matches);
    }

    return html_entity_decode($matches[1] ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * REQ-AUTH-004 (1.4c). Login SAML completo en un solo paso, sin desafío
 * de MFA de por medio — paralela de `loginWithOidcFor()`. `$params` debe
 * traer al menos `sub` y, salvo que el proveedor cataloge
 * `name_id_format = emailAddress`, `attribute_name`/`attribute_value`
 * con el atributo de correo configurado (`'mail'` por defecto en
 * `createActiveSamlProvider()`).
 *
 * @param  array<string, mixed>  $params
 */
function loginWithSamlFor(string $slug, string $providerPublicId, array $params): string
{
    [$authorizationUrl, $beginCookie] = beginSamlFlow($slug, $providerPublicId);
    $callback = completeSamlFlow($authorizationUrl, $params);

    expect(oauthCallbackResultCode($callback))->toBeNull();

    return sessionCookieValue($callback);
}

/**
 * REQ-AUTH-004 (1.4c), `RN-AUTH-126`. Genera un par clave/certificado
 * X.509 autofirmado **de prueba** (`REQ-SEED-005`: nunca material de
 * producción), con la vigencia pedida a partir de ahora — para los
 * tests de carga manual de certificados del IdP (`CA-AUTH-328`-`330`)
 * que necesitan un PEM real y no el del IdP simulado (que solo conoce
 * una clave). Con `Carbon::setTestNow()` activo (viaje en el tiempo),
 * `$days` se cuenta desde el "ahora" congelado.
 *
 * @return array{key: string, cert: string}
 */
function generateSelfSignedTestCertificate(int $days = 3650, int $keyBits = 2048): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => $keyBits,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $csr = openssl_csr_new(['CN' => 'saml-test-cert.example.com'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);

    openssl_pkey_export($key, $keyPem);
    openssl_x509_export($cert, $certPem);

    return ['key' => $keyPem, 'cert' => $certPem];
}

/**
 * El cuerpo Base64 de un PEM, sin cabeceras `BEGIN/END CERTIFICATE` ni
 * espacio en blanco — la forma en la que un `ds:X509Certificate` de
 * metadatos SAML lo lleva.
 */
function stripPemHeaders(string $pem): string
{
    return trim((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem));
}

/**
 * REQ-AUTH-004 (1.4c), `funcional.md §G.4.2`. Construye a mano un
 * documento de metadatos de IdP mínimo pero válido, con el certificado
 * y el `NameIDFormat` que se le pidan — para los tests de las guardas de
 * contenido (`CA-AUTH-318`, `321`-`323`) que necesitan una forma
 * concreta que el IdP simulado no ofrece (p. ej. `NameIDFormat`
 * `transient`, o ningún `KeyDescriptor`).
 *
 * @param  list<string>  $nameIdFormats  URN completos. `[]` omite el elemento por completo.
 */
function buildSamlMetadataXml(
    string $entityId = 'https://idp-manual.example.com/entity',
    ?string $certificatePem = null,
    string $ssoBinding = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
    string $ssoUrl = 'https://idp-manual.example.com/sso',
    array $nameIdFormats = ['urn:oasis:names:tc:SAML:2.0:nameid-format:persistent'],
): string {
    $keyDescriptor = $certificatePem !== null
        ? '<md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'.
            '<ds:X509Data><ds:X509Certificate>'.stripPemHeaders($certificatePem).'</ds:X509Certificate></ds:X509Data>'.
            '</ds:KeyInfo></md:KeyDescriptor>'
        : '';

    $nameIdFormatsXml = implode('', array_map(
        fn (string $urn): string => '<md:NameIDFormat>'.htmlspecialchars($urn, ENT_QUOTES).'</md:NameIDFormat>',
        $nameIdFormats,
    ));

    $entityIdAttr = htmlspecialchars($entityId, ENT_QUOTES);
    $ssoUrlAttr = htmlspecialchars($ssoUrl, ENT_QUOTES);
    $ssoBindingAttr = htmlspecialchars($ssoBinding, ENT_QUOTES);

    return <<<XML
        <?xml version="1.0"?>
        <md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityIdAttr}">
          <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
            {$keyDescriptor}
            {$nameIdFormatsXml}
            <md:SingleSignOnService Binding="{$ssoBindingAttr}" Location="{$ssoUrlAttr}"/>
          </md:IDPSSODescriptor>
        </md:EntityDescriptor>
        XML;
}
