<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// funcional.md §B.4.1-§B.4.4, api.md §B.2-§B.4. REQ-AUTH-005 puntos 2-3:
// panel de sesiones activas y cierre remoto (1.2b).
//
// Hallazgo propio de esta sesión (issue #82, severidad Media): reutilizar
// una cookie de sesión entre llamadas de test con
// `test()->withCookie($name, $valorYaCifrado)->postJson/getJson/deleteJson(...)`
// —el patrón que ya usaban SessionEndpointTest.php y otros— NO transmite
// la cookie en absoluto: `MakesHttpRequests::json()` usa
// `prepareCookiesForJsonRequest()`, que descarta TODAS las cookies salvo
// que se llame antes a `withCredentials()`, y además `withCookie()` cifra
// de nuevo un valor que ya venía cifrado desde el `Set-Cookie` de la
// respuesta anterior. Esos tests "pasan" solo porque, dentro de un mismo
// test, `SessionManager::driver()` reutiliza el mismo objeto `Store` en
// memoria entre llamadas — un artefacto que coincide con el resultado
// esperado en un flujo de una sola identidad, pero que este módulo,
// con varias sesiones simultáneas del mismo usuario, delata de inmediato
// (verificado con instrumentación directa antes de escribir estos tests).
// El patrón correcto, usado aquí, es `withCredentials()` +
// `withUnencryptedCookie()` (el valor ya viene cifrado por la propia
// aplicación en el `Set-Cookie`; no hay que volver a cifrarlo) — y,
// además, forzar `Store`/`Guard` nuevos en cada llamada
// (`resetSessionState()`): el mismo objeto `Store` en memoria, si no se
// descarta, arrastra sus atributos de una llamada a la siguiente aunque
// la lectura de esta petición no encuentre nada (Laravel hace
// `array_replace($this->attributes, $this->readFromHandler())`, que no
// vacía nada cuando la lectura viene vacía) — sin este reseteo, una
// sesión ya revocada seguiría pareciendo autenticada dentro del mismo
// test. En producción esto no ocurre: cada petición PHP-FPM es un
// proceso nuevo.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// sessionCookieValue(), withSessionCookie(), resetSessionState() y
// loginFor() viven en tests/Pest.php (compartidas con
// NewDeviceDetectionTest.php y SessionLifecycleTest.php).

// CA-AUTH-080, RN-AUTH-39, RN-AUTH-05
test('CA-AUTH-080: un login correcto crea exactamente una fila viva en user_sessions con el identificador posterior a la regeneración', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-080');

    $anonymous = test()->getJson(coreApiUrl($tenant->slug, '/auth/csrf-cookie'))->assertNoContent();
    $beforeCookie = sessionCookieValue($anonymous);

    withSessionCookie($beforeCookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/session'), ['email' => $user->email, 'password' => $password])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $password): void {
        $sessions = UserSession::query()->where('user_id', $user->id)->whereNull('ended_at')->get();
        expect($sessions)->toHaveCount(1);

        $session = $sessions->first();
        expect($session->started_at)->not->toBeNull()
            ->and($session->ip_address)->not->toBeNull()
            ->and($session->user_agent)->not->toBeNull();

        // Posterior a la regeneración: existe de verdad en `sessions`
        // (la fila anónima previa a la regeneración se destruyó).
        $frameworkRow = DB::table('sessions')->where('id', $session->session_id)->first();
        expect($frameworkRow)->not->toBeNull();

        $raw = json_encode($session->getAttributes());
        expect($raw)->not->toContain($password)
            ->and($raw)->not->toContain('$2y$');
    });
});

// CA-AUTH-081
test('CA-AUTH-081: GET /auth/csrf-cookie sin login posterior no crea fila en user_sessions', function (): void {
    [$tenant] = provisionActiveUser('sess-081');

    $anonymous = test()->getJson(coreApiUrl($tenant->slug, '/auth/csrf-cookie'))->assertNoContent();
    $cookie = sessionCookieValue($anonymous);

    // funcional.md §B.4.1: "no hay fila de user_sessions para las
    // sesiones anónimas". Hay fila en `sessions` (la anónima que acaba de
    // crear StartSession) y ninguna en `user_sessions`.
    expect($cookie)->not->toBeEmpty()
        ->and(DB::table('sessions')->count())->toBeGreaterThan(0);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserSession::query()->count())->toBe(0);
    });
});

// CA-AUTH-082, RN-AUTH-41
test('CA-AUTH-082: el listado devuelve solo las sesiones del solicitante, con exactamente una current', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-082');

    $login1 = loginFor($tenant->slug, $user->email, $password, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0');
    $cookie1 = sessionCookieValue($login1);

    loginFor($tenant->slug, $user->email, $password, 'Mozilla/5.0 (Macintosh) Firefox/130.0');
    loginFor($tenant->slug, $user->email, $password, 'Mozilla/5.0 (iPhone) Safari/605.1 Version/17.0');

    $listing = withSessionCookie($cookie1)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertOk();

    $data = $listing->json('data');
    expect($data)->toHaveCount(3);

    $currentFlags = collect($data)->pluck('current');
    expect($currentFlags->filter(fn ($v) => $v === true))->toHaveCount(1);
    expect(collect($data)->firstWhere('current', true)['public_id'])
        ->not->toBeNull();
});

// CA-AUTH-083, RN-AUTH-40
test('CA-AUTH-083: la respuesta del listado no contiene el identificador de sesión ni material de la cookie de dispositivo', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-083');

    $login = loginFor($tenant->slug, $user->email, $password);
    $sessionCookie = sessionCookieValue($login);
    $deviceCookie = $login->getCookie('pge_device', false)?->getValue();

    $listing = withSessionCookie($sessionCookie)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertOk();

    $raw = json_encode($listing->json());

    expect($raw)->not->toContain($sessionCookie);

    if ($deviceCookie !== null) {
        expect($raw)->not->toContain($deviceCookie);
    }

    expect($listing->json('data.0'))->not->toHaveKey('user_agent')
        ->and($listing->json('data.0'))->not->toHaveKey('session_id');
});

// CA-AUTH-084
test('CA-AUTH-084: una fila viva cuya sesión de framework ya no existe se cierra perezosamente y no aparece en el listado', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-084');

    $login1 = loginFor($tenant->slug, $user->email, $password);
    $cookie1 = sessionCookieValue($login1);

    loginFor($tenant->slug, $user->email, $password);

    // Se simula el recolector del framework: se borra la fila de
    // `sessions` de la SEGUNDA sesión, sin pasar por ningún endpoint.
    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        // La MÁS RECIENTE (id más alto) es la segunda sesión (login2); la
        // primera (cookie1) debe seguir viva para la comprobación de abajo.
        $orphan = UserSession::query()->where('user_id', $user->id)->orderByDesc('id')->first();
        DB::table('sessions')->where('id', $orphan->session_id)->delete();
    });

    $listing = withSessionCookie($cookie1)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertOk();

    expect($listing->json('data'))->toHaveCount(1);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $closed = UserSession::query()->where('user_id', $user->id)->whereNotNull('ended_at')->first();
        expect($closed)->not->toBeNull()
            ->and($closed->end_reason)->toBe(SessionEndReason::Caducidad);
    });
});

// CA-AUTH-085, RN-AUTH-42
test('CA-AUTH-085: revocar una sesión ajena (propia, no actual) la cierra, borra sessions y deja la actual viva', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-085');

    $login1 = loginFor($tenant->slug, $user->email, $password);
    $cookie1 = sessionCookieValue($login1);

    $login2 = loginFor($tenant->slug, $user->email, $password);
    $cookie2 = sessionCookieValue($login2);

    $listing = withSessionCookie($cookie1)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertOk();

    $target = collect($listing->json('data'))->firstWhere('current', false);

    withSessionCookie($cookie1)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/sessions/{$target['public_id']}"))
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $target): void {
        $revoked = UserSession::query()->where('public_id', $target['public_id'])->firstOrFail();
        expect($revoked->ended_at)->not->toBeNull()
            ->and($revoked->end_reason)->toBe(SessionEndReason::RevocadaUsuario)
            ->and($revoked->ended_by)->toBe($user->id);
    });

    // La segunda sesión ya no funciona.
    withSessionCookie($cookie2)
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertStatus(401);

    // La primera sigue viva.
    withSessionCookie($cookie1)
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertOk();
});

// CA-AUTH-086
test('CA-AUTH-086: revocar la sesión actual por su public_id equivale a un logout', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-086');

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $listing = withSessionCookie($cookie)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertOk();

    $current = collect($listing->json('data'))->firstWhere('current', true);

    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/sessions/{$current['public_id']}"))
        ->assertNoContent();

    withSessionCookie($cookie)
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertStatus(401);
});

// CA-AUTH-087, RN-AUTH-41, INV-001, ADR-038 §6.4
test('CA-AUTH-087: revocar una sesión de otro usuario del mismo tenant y de otro tenant responden 404 con cuerpo idéntico', function (): void {
    [$tenantA, $userA, $passwordA] = provisionActiveUser('sess-087a');
    $victim = app(TenantContext::class)->runFor($tenantA->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create([
            'password' => 'Cl4v3-Correcta-2026!',
            'status' => UserStatus::Activo,
        ]);
    });

    loginFor($tenantA->slug, $victim->email, 'Cl4v3-Correcta-2026!');
    $victimSessionPublicId = app(TenantContext::class)->runFor(
        $tenantA->id,
        fn () => UserSession::query()->where('user_id', $victim->id)->firstOrFail()->public_id,
    );

    // Hallazgo propio (issue #82, mismo origen que la nota de cabecera):
    // `RecordsAuthorship` (created_by/updated_by) lee `Auth::id()` en el
    // momento de crear el registro. Tras un login HTTP real dentro de
    // este mismo test, el guard ('web') queda con ese usuario cacheado
    // aunque la llamada siguiente no pase por ningún middleware — crear
    // aquí una persona/usuario de OTRO tenant heredaría por error un
    // `created_by` que apunta a un usuario de un tenant distinto,
    // violando la FK compuesta. `resetSessionState()` también sirve
    // fuera de una petición HTTP para esto.
    resetSessionState();
    [$tenantB, $userB, $passwordB] = provisionActiveUser('sess-087b');
    loginFor($tenantB->slug, $userB->email, $passwordB);
    $ownSessionPublicIdB = app(TenantContext::class)->runFor(
        $tenantB->id,
        fn () => UserSession::query()->where('user_id', $userB->id)->firstOrFail()->public_id,
    );

    $loginA = loginFor($tenantA->slug, $userA->email, $passwordA);
    $cookieA = sessionCookieValue($loginA);

    // Sesión de otro usuario del MISMO tenant (A).
    $sameTenant = withSessionCookie($cookieA)
        ->deleteJson(coreApiUrl($tenantA->slug, "/auth/sessions/{$victimSessionPublicId}"))
        ->assertStatus(404)
        ->json();

    // Sesión de OTRO tenant (B), pedida desde A.
    $otherTenant = withSessionCookie($cookieA)
        ->deleteJson(coreApiUrl($tenantA->slug, "/auth/sessions/{$ownSessionPublicIdB}"))
        ->assertStatus(404)
        ->json();

    // 'instance' varía legítimamente (lleva el public_id solicitado, distinto
    // en cada caso); 'request_id' también. ADR-038 §6.4 exige que el resto
    // del cuerpo —title/status/detail/type— sea indistinguible.
    $strip = fn (array $body) => collect($body)->except(['request_id', 'instance'])->all();
    expect($strip($sameTenant))->toBe($strip($otherTenant));

    app(TenantContext::class)->runFor($tenantA->id, function () use ($victimSessionPublicId): void {
        expect(UserSession::query()->where('public_id', $victimSessionPublicId)->firstOrFail()->ended_at)->toBeNull();
    });
    app(TenantContext::class)->runFor($tenantB->id, function () use ($ownSessionPublicIdB): void {
        expect(UserSession::query()->where('public_id', $ownSessionPublicIdB)->firstOrFail()->ended_at)->toBeNull();
    });
});

// CA-AUTH-088
test('CA-AUTH-088: revocar una sesión ya cerrada responde 409', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-088');

    $login1 = loginFor($tenant->slug, $user->email, $password);
    $cookie1 = sessionCookieValue($login1);
    loginFor($tenant->slug, $user->email, $password);

    $listing = withSessionCookie($cookie1)
        ->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertOk();
    $target = collect($listing->json('data'))->firstWhere('current', false);

    withSessionCookie($cookie1)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/sessions/{$target['public_id']}"))
        ->assertNoContent();

    withSessionCookie($cookie1)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/sessions/{$target['public_id']}"))
        ->assertStatus(409);
});

// CA-AUTH-089, RN-AUTH-43
test('CA-AUTH-089: DELETE /auth/sessions sin parámetros cierra las demás y conserva la actual', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-089');

    $login1 = loginFor($tenant->slug, $user->email, $password);
    $cookie1 = sessionCookieValue($login1);
    loginFor($tenant->slug, $user->email, $password);
    loginFor($tenant->slug, $user->email, $password);

    withSessionCookie($cookie1)
        ->deleteJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserSession::query()->where('user_id', $user->id)->whereNull('ended_at')->count())->toBe(1);
        expect(UserSession::query()->where('user_id', $user->id)->whereNotNull('ended_at')->count())->toBe(2);
        UserSession::query()->where('user_id', $user->id)->whereNotNull('ended_at')->get()->each(
            fn (UserSession $s) => expect($s->end_reason)->toBe(SessionEndReason::RevocadaUsuario)
        );
    });

    withSessionCookie($cookie1)
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertOk();
});

// CA-AUTH-090
test('CA-AUTH-090: DELETE /auth/sessions?scope=all cierra todas, incluida la actual', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-090');

    $login1 = loginFor($tenant->slug, $user->email, $password);
    $cookie1 = sessionCookieValue($login1);
    loginFor($tenant->slug, $user->email, $password);
    loginFor($tenant->slug, $user->email, $password);

    withSessionCookie($cookie1)
        ->deleteJson(coreApiUrl($tenant->slug, '/auth/sessions?scope=all'))
        ->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserSession::query()->where('user_id', $user->id)->whereNull('ended_at')->count())->toBe(0);
    });

    withSessionCookie($cookie1)
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertStatus(401);
});

// CA-AUTH-091
test('CA-AUTH-091: con una sola sesión, DELETE /auth/sessions sin parámetros responde 204 y la sesión sigue viva', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-091');

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, '/auth/sessions'))
        ->assertNoContent();

    withSessionCookie($cookie)
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertOk();
});

// CA-AUTH-092, INV-002, RN-AUTH-29
test('CA-AUTH-092: los tres endpoints sin sesión responden 401; los DELETE sin CSRF válido no cierran nada', function (): void {
    [$tenant, $user, $password] = provisionActiveUser('sess-092');

    test()->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))->assertStatus(401);
    test()->deleteJson(coreApiUrl($tenant->slug, '/auth/sessions/01J8ZZZZZZZZZZZZZZZZZZZZZZ'))->assertStatus(401);
    test()->deleteJson(coreApiUrl($tenant->slug, '/auth/sessions'))->assertStatus(401);

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    app()->detectEnvironment(fn () => 'local');

    try {
        $publicId = app(TenantContext::class)->runFor(
            $tenant->id,
            fn () => UserSession::query()->where('user_id', $user->id)->firstOrFail()->public_id,
        );

        $response = withSessionCookie($cookie)
            ->deleteJson(coreApiUrl($tenant->slug, "/auth/sessions/{$publicId}"));
        expect($response->status())->toBeIn([403, 419]);

        $responseAll = withSessionCookie($cookie)
            ->deleteJson(coreApiUrl($tenant->slug, '/auth/sessions'));
        expect($responseAll->status())->toBeIn([403, 419]);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserSession::query()->where('user_id', $user->id)->whereNull('ended_at')->count())->toBe(1);
    });
});
