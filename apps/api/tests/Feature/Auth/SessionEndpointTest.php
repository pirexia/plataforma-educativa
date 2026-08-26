<?php

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\AccountLockout;
use App\Modules\Auth\Domain\Models\LoginAttempt;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// api.md §2, funcional.md §4.2/§4.3. Login y logout locales.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-AUTH-010
test('CA-AUTH-010: login correcto responde 200 con el mismo recurso que GET /me, sin hash ni token', function (): void {
    // Issue #83: el driver 'array' forzado por phpunit.xml vive en la
    // instancia del store, que resetSessionState() olvida a propósito
    // entre peticiones simuladas — con 'array' el segundo request nunca
    // vería la sesión de verdad. 'database' persiste de verdad entre
    // peticiones, como en producción (mismo motivo que CA-AUTH-016).
    config(['session.driver' => 'database']);
    [$tenant, $user, $password] = provisionActiveUser('sess-010');

    $login = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email,
        'password' => $password,
    ])->assertOk();

    expect($login->json())->not->toHaveKey('password')
        ->and($login->json())->not->toHaveKey('token')
        ->and(json_encode($login->json()))->not->toContain('$2y$');

    $sessionCookie = sessionCookieValue($login);

    $me = withSessionCookie($sessionCookie)
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertOk();

    expect($login->json())->toBe($me->json());
});

// CA-AUTH-004
test('CA-AUTH-004: el identificador de sesión se regenera en el login (fijación de sesión)', function (): void {
    // Issue #83: ver comentario equivalente en CA-AUTH-010 / CA-AUTH-016 —
    // 'array' no sobrevive al resetSessionState() entre las dos peticiones
    // de este test.
    config(['session.driver' => 'database']);
    [$tenant, $user, $password] = provisionActiveUser('sess-004');

    $anonymous = test()->getJson(coreApiUrl($tenant->slug, '/auth/csrf-cookie'))->assertNoContent();
    $beforeCookie = sessionCookieValue($anonymous);
    // Issue #83 (verificación adicional): el valor CIFRADO de la cookie
    // (sessionCookieValue, usado para reenviarla) lleva un IV aleatorio
    // distinto en cada respuesta, así que dos cifrados del MISMO
    // identificador de sesión ya son distintos entre sí en bruto. Comparar
    // los valores en bruto no detectaría una regresión real en la
    // regeneración de sesión (CA-AUTH-004 pasaría "por casualidad" siempre,
    // igual que con el patrón roto que corrige este issue). Hay que
    // descifrar y comparar el identificador de sesión real.
    $beforeSessionId = $anonymous->getCookie(config('session.cookie'))->getValue();

    $login = withSessionCookie($beforeCookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/session'), ['email' => $user->email, 'password' => $password])
        ->assertOk();

    $afterSessionId = $login->getCookie(config('session.cookie'))->getValue();

    expect($afterSessionId)->not->toBe($beforeSessionId);
});

// CA-AUTH-003
test('CA-AUTH-003: la cookie de sesión es HttpOnly, SameSite=Lax y sin atributo Domain', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-003');

    $login = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), ['email' => $user->email, 'password' => $password])
        ->assertOk();

    $cookie = $login->getCookie(config('session.cookie'), false);

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getDomain())->toBeEmpty();
});

// CA-AUTH-011: cuatro escenarios indistinguibles.
test('CA-AUTH-011: contraseña incorrecta, correo inexistente, pendiente e inactivo responden 401 con cuerpo idéntico', function (): void {
    [$tenantWrong, $activeUser, $correctPassword] = provisionActiveUser('sess-011-a');

    [$tenantPending, $pendingUser] = provisionActiveUser('sess-011-b', ['status' => UserStatus::Pendiente]);
    [$tenantInactive, $inactiveUser] = provisionActiveUser('sess-011-c', ['status' => UserStatus::Inactivo]);

    $wrongPassword = test()->postJson(coreApiUrl($tenantWrong->slug, '/auth/session'), [
        'email' => $activeUser->email, 'password' => 'esto-no-es-la-clave',
    ])->assertStatus(401)->json();

    $nonExistentEmail = test()->postJson(coreApiUrl($tenantWrong->slug, '/auth/session'), [
        'email' => 'no-existe-nadie@example.com', 'password' => $correctPassword,
    ])->assertStatus(401)->json();

    $pending = test()->postJson(coreApiUrl($tenantPending->slug, '/auth/session'), [
        'email' => $pendingUser->email, 'password' => $correctPassword,
    ])->assertStatus(401)->json();

    $inactive = test()->postJson(coreApiUrl($tenantInactive->slug, '/auth/session'), [
        'email' => $inactiveUser->email, 'password' => $correctPassword,
    ])->assertStatus(401)->json();

    $strip = fn (array $body) => collect($body)->except('request_id')->all();

    expect($strip($wrongPassword))
        ->toBe($strip($nonExistentEmail))
        ->toBe($strip($pending))
        ->toBe($strip($inactive))
        ->and($wrongPassword['type'])->toBe('urn:pge:error:unauthenticated');
});

// CA-AUTH-012, RN-AUTH-08
test('CA-AUTH-012: la contraseña del tenant A no vale en el host del tenant B con el mismo correo, y el intento se registra en B', function (): void {
    $sharedEmail = 'compartido@example.com';

    [$tenantA] = provisionActiveUser('sess-012-a', ['email' => $sharedEmail, 'raw_password' => 'ContraseñaDeA-2026!']);
    [$tenantB] = provisionActiveUser('sess-012-b', ['email' => $sharedEmail, 'raw_password' => 'ContraseñaDeB-2026!']);

    test()->postJson(coreApiUrl($tenantB->slug, '/auth/session'), [
        'email' => $sharedEmail, 'password' => 'ContraseñaDeA-2026!',
    ])->assertStatus(401);

    app(TenantContext::class)->runFor($tenantB->id, function () use ($sharedEmail): void {
        expect(LoginAttempt::where('email', $sharedEmail)->count())->toBe(1);
    });

    app(TenantContext::class)->runFor($tenantA->id, function () use ($sharedEmail): void {
        expect(LoginAttempt::where('email', $sharedEmail)->count())->toBe(0);
    });
});

// CA-AUTH-013, RN-AUTH-03
test('CA-AUTH-013: un login correcto reamasa un hash con coste inferior al configurado', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-013', []);

    app(TenantContext::class)->runFor($tenant->id, function () use ($password): void {
        $user = User::query()->first();
        // Hash "legado" a coste 4, simulando una fila anterior al
        // endurecimiento de AUTH_BCRYPT_ROUNDS.
        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::driver('bcrypt')->make($password, ['rounds' => 4]),
        ]);
    });

    $costBefore = app(TenantContext::class)->runFor($tenant->id, fn () => password_get_info(User::query()->first()->password)['options']['cost']);
    expect($costBefore)->toBe(4);

    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();

    $costAfter = app(TenantContext::class)->runFor($tenant->id, fn () => password_get_info(User::query()->first()->password)['options']['cost']);
    expect($costAfter)->toBeGreaterThanOrEqual(12);
});

// CA-AUTH-014, RN-AUTH-05
test('CA-AUTH-014: cada intento de login escribe exactamente una fila en login_attempts, sin la contraseña', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-014');

    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($password): void {
        $attempts = LoginAttempt::query()->get();
        expect($attempts)->toHaveCount(1)
            ->and($attempts->first()->outcome->value)->toBe('exito');

        foreach ($attempts as $attempt) {
            $raw = json_encode($attempt->getAttributes());
            expect($raw)->not->toContain($password);
        }
    });
});

// CA-AUTH-015, RN-AUTH-24
test('CA-AUTH-015: una contraseña correcta sobre un usuario inactivo no cuenta como fallo y no bloquea la cuenta', function (): void {
    // El foco de este test es RN-AUTH-24, no el límite de tasa de
    // CA-AUTH-074 (ya cubierto en su propio test) — se sube para que seis
    // intentos legítimos no se confundan con fuerza bruta.
    config(['auth-local.rate_limits.session_email.max' => 100]);

    [$tenant, $user, $password] = provisionActiveUser('sess-015', ['status' => UserStatus::Inactivo]);

    for ($i = 0; $i < 5; $i++) {
        test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
            'email' => $user->email, 'password' => $password,
        ])->assertStatus(401);
    }

    app(TenantContext::class)->runFor($tenant->id, function () {
        expect(AccountLockout::query()->whereNull('unlocked_at')->count())->toBe(0);
    });

    // Sexto intento: sigue siendo 401 genérico, no 423.
    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertStatus(401);
});

// CA-AUTH-016
test('CA-AUTH-016: logout invalida la sesión y una petición posterior con la misma cookie responde 401', function (): void {
    config(['session.driver' => 'database']);
    [$tenant, $user, $password] = provisionActiveUser('sess-016');

    $login = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();
    $cookie = sessionCookieValue($login);

    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, '/auth/session'))
        ->assertNoContent();

    withSessionCookie($cookie)
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertStatus(401);
});

// CA-AUTH-017
test('CA-AUTH-017: cerrar sesión sin ninguna sesión responde 204, no 401 (idempotente)', function (): void {
    [$tenant] = provisionActiveUser('sess-017');

    test()->deleteJson(coreApiUrl($tenant->slug, '/auth/session'))->assertNoContent();
});

// CA-AUTH-074 (sesión): límite por (tenant, email) — el más bajo de los
// dos (5/min), para no alargar el test con 11 peticiones.
test('CA-AUTH-074: el límite de tasa por (tenant, email) en login responde 429 con Retry-After', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-074');

    for ($i = 0; $i < 5; $i++) {
        test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
            'email' => $user->email, 'password' => 'contraseña-incorrecta',
        ])->assertStatus(401);
    }

    $response = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertStatus(429);

    expect($response->headers->has('Retry-After'))->toBeTrue()
        ->and($response->json('type'))->toBe('urn:pge:error:too-many-requests');
});

// Issue #74: GET /auth/csrf-cookie era el único de los 6 endpoints
// anónimos sin límite de tasa — el bucket ya existía en auth-local.php
// pero SessionController::csrfCookie() nunca lo invocaba. Cada llamada
// abre una sesión nueva sin autenticación: sin límite era un vector de
// agotamiento de recursos.
test('el límite de tasa por IP en GET /auth/csrf-cookie responde 429 con Retry-After', function (): void {
    [$tenant] = provisionActiveUser('sess-csrf-074');
    $max = (int) config('auth-local.rate_limits.csrf_cookie_ip.max');

    for ($i = 0; $i < $max; $i++) {
        test()->getJson(coreApiUrl($tenant->slug, '/auth/csrf-cookie'))->assertNoContent();
    }

    $response = test()->getJson(coreApiUrl($tenant->slug, '/auth/csrf-cookie'))->assertStatus(429);

    expect($response->headers->has('Retry-After'))->toBeTrue()
        ->and($response->json('type'))->toBe('urn:pge:error:too-many-requests');
});

// CA-AUTH-005: se fuerza environment='local' para que PreventRequestForgery
// deje de saltarse la comprobación por runningUnitTests() (Illuminate\
// Foundation\Http\Middleware\PreventRequestForgery::runningUnitTests()
// solo devuelve true con APP_ENV=testing, que es lo que fuerza
// phpunit.xml). Mismo patrón de app()->detectEnvironment() que
// tests/Feature/Core/DocumentValidationConfigTest.php.
test('CA-AUTH-005: POST /auth/session sin token CSRF se rechaza y no modifica nada', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-005');

    app()->detectEnvironment(fn () => 'local');

    try {
        $response = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
            'email' => $user->email, 'password' => $password,
        ]);

        expect($response->status())->toBeIn([403, 419]);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    app(TenantContext::class)->runFor($tenant->id, function () {
        expect(LoginAttempt::query()->count())->toBe(0);
    });
});
