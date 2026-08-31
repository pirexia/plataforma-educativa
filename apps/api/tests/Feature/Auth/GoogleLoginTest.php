<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\LinkMethod;
use App\Modules\Auth\Domain\Models\AccountLockout;
use App\Modules\Auth\Domain\Models\LoginAttempt;
use App\Modules\Auth\Domain\Models\MfaChallenge;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Modules\Auth\Infrastructure\Jobs\SendIdentityLinkedEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// funcional.md §E.4, §E.4.2, §E.4.6, api.md §E.2-§E.4. Login con Google:
// descubrimiento, arranque, callback y fusión de cuentas (REQ-AUTH-002, 1.4).

beforeEach(function (): void {
    config(['auth-local.oauth.driver' => 'fake']);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function fakeGoogleClaims(array $overrides = []): array
{
    return array_merge([
        'sub' => 'fake-sub-'.Str::random(8),
        'email' => 'persona-'.Str::random(6).'@example.com',
        'email_verified' => '1',
        'given_name' => 'Nombre',
        'family_name' => 'Apellidos',
    ], $overrides);
}

// CA-AUTH-200
test('CA-AUTH-200: con AUTH_OAUTH_DRIVER=none (por defecto), identity-providers devuelve data vacía', function (): void {
    config(['auth-local.oauth.driver' => 'none']);
    [$tenant] = provisionActiveUser('goog-200');

    test()->getJson(coreApiUrl($tenant->slug, '/auth/identity-providers'))
        ->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['total' => 0]]);
});

test('con el proveedor simulado configurado, identity-providers devuelve google', function (): void {
    [$tenant] = provisionActiveUser('goog-200b');

    test()->getJson(coreApiUrl($tenant->slug, '/auth/identity-providers'))
        ->assertOk()
        ->assertJson(['data' => [['provider' => 'google', 'label_key' => 'auth.providers.google']], 'meta' => ['total' => 1]]);
});

// CA-AUTH-201, RN-AUTH-29
test('CA-AUTH-201: POST /auth/oauth-authorizations sin token CSRF se rechaza y no deja state en la sesión', function (): void {
    [$tenant] = provisionActiveUser('goog-201');

    app()->detectEnvironment(fn () => 'local');

    try {
        $response = test()->postJson(coreApiUrl($tenant->slug, '/auth/oauth-authorizations'), [
            'provider' => 'google', 'intent' => 'login',
        ]);

        expect($response->status())->toBeIn([403, 419]);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

// CA-AUTH-204, RN-AUTH-91
test('CA-AUTH-204: un callback con state que no coincide responde estado_no_valido sin crear sesión ni vínculo', function (): void {
    [$tenant] = provisionActiveUser('goog-204');
    [$authorizationUrl, $cookie] = beginFakeGoogleFlow($tenant->slug);

    $callback = withSessionCookie($cookie)
        ->get(coreApiUrl($tenant->slug, '/auth/oauth/google/callback?code=cualquiera&state=state-equivocado'))
        ->assertRedirect();

    expect(oauthCallbackResultCode($callback))->toBe('estado_no_valido');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-205
test('CA-AUTH-205: repetir el mismo code y state responde estado_no_valido la segunda vez y no crea una segunda sesión', function (): void {
    [$tenant, $user] = provisionActiveUser('goog-205', ['email' => 'repite@example.com']);
    [$authorizationUrl, $beginCookie] = beginFakeGoogleFlow($tenant->slug);

    $claims = fakeGoogleClaims(['email' => 'repite@example.com', 'email_verified' => '1']);

    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
    $authorizePath = (string) parse_url($authorizationUrl, PHP_URL_PATH);

    $redirectToCallback = withSessionCookie($beginCookie)
        ->get($authorizePath.'?'.http_build_query(array_merge(['state' => $query['state'], 'submit' => '1'], $claims)))
        ->assertRedirect();

    $callbackUrl = $redirectToCallback->headers->get('Location');
    $callbackCookie = sessionCookieValue($redirectToCallback);

    $first = withSessionCookie($callbackCookie)->get($callbackUrl)->assertRedirect();
    expect(oauthCallbackResultCode($first))->toBeNull();

    $second = withSessionCookie(sessionCookieValue($first))->get($callbackUrl)->assertRedirect();
    expect(oauthCallbackResultCode($second))->toBe('estado_no_valido');

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserIdentity::query()->where('user_id', $user->id)->count())->toBe(1);
    });
});

// CA-AUTH-206
test('CA-AUTH-206: cancelar en Google responde cancelado, sin fila de fallo ni incremento de bloqueo', function (): void {
    [$tenant] = provisionActiveUser('goog-206');
    [$authorizationUrl, $beginCookie] = beginFakeGoogleFlow($tenant->slug);

    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
    $authorizePath = (string) parse_url($authorizationUrl, PHP_URL_PATH);

    $redirectToCallback = withSessionCookie($beginCookie)
        ->get($authorizePath.'?'.http_build_query(['state' => $query['state'], 'submit' => '1', 'cancel' => '1']))
        ->assertRedirect();

    $callback = withSessionCookie(sessionCookieValue($redirectToCallback))
        ->get($redirectToCallback->headers->get('Location'))
        ->assertRedirect();

    expect(oauthCallbackResultCode($callback))->toBe('cancelado');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(LoginAttempt::query()->count())->toBe(0);
    });
});

// CA-AUTH-207, RN-AUTH-93
test('CA-AUTH-207: ninguna respuesta del callback lleva code, state, token, correo, public_id ni datos personales', function (): void {
    [$tenant] = provisionActiveUser('goog-207', ['email' => 'nadie-207@example.com']);

    $cases = [];

    // estado_no_valido
    [, $cookie1] = beginFakeGoogleFlow($tenant->slug);
    $cases[] = withSessionCookie($cookie1)
        ->get(coreApiUrl($tenant->slug, '/auth/oauth/google/callback?code=x&state=malo'))
        ->assertRedirect();

    // sin_cuenta
    [$url2, $cookie2] = beginFakeGoogleFlow($tenant->slug);
    $cases[] = completeFakeGoogleFlow($url2, $cookie2, fakeGoogleClaims(['email' => 'sin-cuenta-207@example.com']));

    // login completo (sin código, es el caso de éxito)
    [$url3, $cookie3] = beginFakeGoogleFlow($tenant->slug);
    $cases[] = completeFakeGoogleFlow($url3, $cookie3, fakeGoogleClaims(['email' => 'nadie-207@example.com', 'email_verified' => '1']));

    foreach ($cases as $case) {
        $location = (string) $case->headers->get('Location');

        expect($location)->not->toContain('code=')
            ->and($location)->not->toContain('state=')
            ->and($location)->not->toContain('token')
            ->and($location)->not->toContain('%40') // correo url-encoded
            ->and($location)->not->toContain('@');
    }
});

// CA-AUTH-208, RN-AUTH-88
test('CA-AUTH-208: fusión automática crea un solo vínculo, inicia sesión y no toca ningún dato del usuario', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('goog-208', [
        'email' => 'fusion-208@example.com',
    ], ['locale' => 'fr']);

    $before = $user->only(['password', 'status', 'email', 'person_id']);

    $cookie = loginWithFakeGoogleFor($tenant->slug, fakeGoogleClaims([
        'email' => 'fusion-208@example.com',
        'email_verified' => '1',
    ]));

    withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/me'))->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $before): void {
        $identities = UserIdentity::query()->where('user_id', $user->id)->get();
        expect($identities)->toHaveCount(1);
        expect($identities->first()->link_method)->toBe(LinkMethod::FusionAutomatica);

        $fresh = User::query()->find($user->id);
        expect($fresh->only(['password', 'status', 'email', 'person_id']))->toBe($before);
        expect($fresh->person->locale)->toBe('fr');
    });
});

// CA-AUTH-209, RN-AUTH-74
test('CA-AUTH-209: la fusión audita un created sobre user_identity y un login, nunca un updated sobre user', function (): void {
    Queue::fake();
    [$tenant, $user] = provisionActiveUser('goog-209', ['email' => 'audit-209@example.com']);

    loginWithFakeGoogleFor($tenant->slug, fakeGoogleClaims([
        'email' => 'audit-209@example.com',
        'email_verified' => '1',
    ]));

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(AuditLog::query()->where('auditable_type', 'user_identity')->where('event', 'created')->count())->toBe(1);
        expect(AuditLog::query()->where('auditable_type', 'user')->where('event', 'login')->count())->toBe(1);
        expect(AuditLog::query()->where('auditable_type', 'user')->where('event', 'updated')->count())->toBe(0);
    });
});

// CA-AUTH-210, RN-AUTH-97
test('CA-AUTH-210: la fusión encola el aviso al titular', function (): void {
    Queue::fake();
    [$tenant] = provisionActiveUser('goog-210', ['email' => 'aviso-210@example.com']);

    loginWithFakeGoogleFor($tenant->slug, fakeGoogleClaims([
        'email' => 'aviso-210@example.com',
        'email_verified' => '1',
    ]));

    Queue::assertPushed(SendIdentityLinkedEmail::class, function ($job): bool {
        return $job->linkMethod === 'fusion_automatica';
    });
});

// CA-AUTH-211, RN-AUTH-87, §E.4.6
test('CA-AUTH-211: email_verified=false con cuenta existente responde exactamente igual que sin cuenta', function (): void {
    [$tenant] = provisionActiveUser('goog-211', ['email' => 'existe-211@example.com']);

    [$url1, $cookie1] = beginFakeGoogleFlow($tenant->slug);
    $withAccount = completeFakeGoogleFlow($url1, $cookie1, fakeGoogleClaims([
        'email' => 'existe-211@example.com', 'email_verified' => '0',
    ]));

    [$url2, $cookie2] = beginFakeGoogleFlow($tenant->slug);
    $withoutAccount = completeFakeGoogleFlow($url2, $cookie2, fakeGoogleClaims([
        'email' => 'no-existe-211@example.com', 'email_verified' => '0',
    ]));

    expect(oauthCallbackResultCode($withAccount))->toBe('sin_cuenta')
        ->and(oauthCallbackResultCode($withoutAccount))->toBe('sin_cuenta')
        ->and($withAccount->headers->get('Location'))->toBe($withoutAccount->headers->get('Location'))
        ->and($withAccount->getContent())->toBe($withoutAccount->getContent());

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-212, RN-AUTH-86
test('CA-AUTH-212: un usuario ya vinculado que cambia su correo en Google sigue entrando en la misma cuenta', function (): void {
    Queue::fake();
    [$tenant, $user] = provisionActiveUser('goog-212', ['email' => 'original-212@example.com']);
    $sub = 'sub-cambia-212';

    loginWithFakeGoogleFor($tenant->slug, fakeGoogleClaims([
        'sub' => $sub, 'email' => 'original-212@example.com', 'email_verified' => '1',
    ]));

    // Mismo sub, correo distinto en Google — RN-AUTH-86: el correo ya no
    // importa una vez hay vínculo.
    $secondCookie = loginWithFakeGoogleFor($tenant->slug, fakeGoogleClaims([
        'sub' => $sub, 'email' => 'nuevo-212@gmail.com', 'email_verified' => '1',
    ]));

    $me = withSessionCookie($secondCookie)->getJson(coreApiUrl($tenant->slug, '/me'))->assertOk();
    expect($me->json('public_id'))->toBe($user->public_id);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserIdentity::query()->where('user_id', $user->id)->count())->toBe(1);
    });
});

// CA-AUTH-213, RN-AUTH-86
test('CA-AUTH-213: un sub vinculado a A entra como A aunque el correo actual sea el de B', function (): void {
    Queue::fake();
    [$tenant, $userA] = provisionActiveUser('goog-213', ['email' => 'usuario-a-213@example.com']);
    $userB = app(TenantContext::class)->runFor($tenant->id, function (): User {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create([
            'email' => 'usuario-b-213@example.com', 'status' => UserStatus::Activo,
        ]);
    });

    $sub = 'sub-compartido-213';
    loginWithFakeGoogleFor($tenant->slug, fakeGoogleClaims([
        'sub' => $sub, 'email' => 'usuario-a-213@example.com', 'email_verified' => '1',
    ]));

    // El sub sigue vinculado a A; llega un callback con ESE sub pero el
    // correo que hoy tiene B.
    $cookie = loginWithFakeGoogleFor($tenant->slug, fakeGoogleClaims([
        'sub' => $sub, 'email' => 'usuario-b-213@example.com', 'email_verified' => '1',
    ]));

    $me = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/me'))->assertOk();
    expect($me->json('public_id'))->toBe($userA->public_id)
        ->and($me->json('public_id'))->not->toBe($userB->public_id);
});

// CA-AUTH-214, RN-AUTH-90, INV-001
test('CA-AUTH-214: la misma cuenta de Google vinculada en dos tenants resuelve al usuario de cada uno, sin fuga', function (): void {
    Queue::fake();
    [$tenantA, $userA] = provisionActiveUser('goog-214-a', ['email' => 'a@example.com']);
    [$tenantB, $userB] = provisionActiveUser('goog-214-b', ['email' => 'b@example.com']);
    $sub = 'sub-multitenant-214';

    loginWithFakeGoogleFor($tenantA->slug, fakeGoogleClaims(['sub' => $sub, 'email' => 'a@example.com', 'email_verified' => '1']));
    $cookieB = loginWithFakeGoogleFor($tenantB->slug, fakeGoogleClaims(['sub' => $sub, 'email' => 'b@example.com', 'email_verified' => '1']));

    $me = withSessionCookie($cookieB)->getJson(coreApiUrl($tenantB->slug, '/me'))->assertOk();
    expect($me->json('public_id'))->toBe($userB->public_id);

    app(TenantContext::class)->runFor($tenantA->id, function () use ($userA): void {
        expect(UserIdentity::query()->where('user_id', $userA->id)->where('subject', 'sub-multitenant-214')->count())->toBe(1);
    });
    app(TenantContext::class)->runFor($tenantB->id, function () use ($userB): void {
        expect(UserIdentity::query()->where('user_id', $userB->id)->where('subject', 'sub-multitenant-214')->count())->toBe(1);
    });
});

// CA-AUTH-216, CA-AUTH-217, RN-AUTH-94
test('CA-AUTH-216/CA-AUTH-217: con TOTP confirmado, el callback abre desafío y no crea sesión; superarlo crea la sesión con method=google', function (): void {
    Queue::fake();
    [$tenant, $user] = provisionActiveUser('goog-216', ['email' => 'totp-216@example.com']);
    $secret = createConfirmedTotpFactor($tenant, $user);

    [$url, $beginCookie] = beginFakeGoogleFlow($tenant->slug);
    $callback = completeFakeGoogleFlow($url, $beginCookie, fakeGoogleClaims([
        'email' => 'totp-216@example.com', 'email_verified' => '1',
    ]));

    expect(oauthCallbackResultCode($callback))->toBe('segundo_factor');

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(MfaChallenge::query()->where('user_id', $user->id)->whereNull('consumed_at')->count())->toBe(1);
        expect(UserIdentity::query()->where('user_id', $user->id)->count())->toBe(0);
    });

    $verify = withSessionCookie(sessionCookieValue($callback))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => currentTotpCode($secret)])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserIdentity::query()->where('user_id', $user->id)->count())->toBe(1);
        expect(LoginAttempt::query()->where('outcome', 'exito')->where('method', 'google')->count())->toBe(1);
    });
});

// CA-AUTH-218
test('CA-AUTH-218: obligado con gracia vencida y sin factor obtiene sesión restringida, y vincular responde 403', function (): void {
    [$tenant, $user] = provisionActiveUser('goog-218', ['email' => 'muro-218@example.com']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $role = Role::create(['code' => 'rol-218', 'name' => 'Rol 218', 'is_system' => false, 'mfa_required' => true]);
        $user->roles()->attach($role->id);

        UserMfaObligation::create([
            'user_id' => $user->id,
            'obligated_since' => now()->subDays(10),
            'grace_deadline_at' => now()->subDay(),
            'trigger' => 'rol_asignado',
        ]);
    });

    $cookie = loginWithFakeGoogleForRestricted($tenant->slug, fakeGoogleClaims([
        'email' => 'muro-218@example.com', 'email_verified' => '1',
    ]));

    $blocked = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/sessions'))->assertStatus(403);
    expect($blocked->json('type'))->toBe('urn:pge:error:mfa-enrollment-required');

    $link = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/oauth-authorizations'), ['provider' => 'google', 'intent' => 'link']);
    expect($link->json('type'))->toBe('urn:pge:error:mfa-enrollment-required');
});

/**
 * Variante de `loginWithFakeGoogleFor()` para el caso `alta_mfa_requerida`:
 * el login sí completa (sesión restringida), así que espera ese código en
 * vez de `null`.
 */
function loginWithFakeGoogleForRestricted(string $slug, array $claims): string
{
    [$authorizationUrl, $beginCookie] = beginFakeGoogleFlow($slug);
    $callback = completeFakeGoogleFlow($authorizationUrl, $beginCookie, $claims);

    expect(oauthCallbackResultCode($callback))->toBe('alta_mfa_requerida');

    return sessionCookieValue($callback);
}

// CA-AUTH-219, RN-AUTH-23
test('CA-AUTH-219: un usuario pendiente con correo verificado en Google no entra y no crea vínculo', function (): void {
    [$tenant, $user] = provisionActiveUser('goog-219', [
        'email' => 'pendiente-219@example.com', 'status' => UserStatus::Pendiente,
    ]);

    [$url, $cookie] = beginFakeGoogleFlow($tenant->slug);
    $callback = completeFakeGoogleFlow($url, $cookie, fakeGoogleClaims([
        'email' => 'pendiente-219@example.com', 'email_verified' => '1',
    ]));

    expect(oauthCallbackResultCode($callback))->toBe('acceso_denegado');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-220
test('CA-AUTH-220: un usuario inactivo obtiene la misma salida genérica', function (): void {
    [$tenant] = provisionActiveUser('goog-220', [
        'email' => 'inactivo-220@example.com', 'status' => UserStatus::Inactivo,
    ]);

    [$url, $cookie] = beginFakeGoogleFlow($tenant->slug);
    $callback = completeFakeGoogleFlow($url, $cookie, fakeGoogleClaims([
        'email' => 'inactivo-220@example.com', 'email_verified' => '1',
    ]));

    expect(oauthCallbackResultCode($callback))->toBe('acceso_denegado');
});

// CA-AUTH-221, §E.6
test('CA-AUTH-221: un bloqueo vivo impide también entrar con Google', function (): void {
    [$tenant, $user] = provisionActiveUser('goog-221', ['email' => 'bloqueado-221@example.com']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        AccountLockout::create([
            'email' => $user->email,
            'user_id' => $user->id,
            'locked_at' => now(),
            'unlock_token_hash' => hash('sha256', 'x'),
            'unlock_token_expires_at' => now()->addHours(24),
            'failed_count' => 5,
        ]);
    });

    [$url, $cookie] = beginFakeGoogleFlow($tenant->slug);
    $callback = completeFakeGoogleFlow($url, $cookie, fakeGoogleClaims([
        'email' => 'bloqueado-221@example.com', 'email_verified' => '1',
    ]));

    expect(oauthCallbackResultCode($callback))->toBe('cuenta_bloqueada');
});

// CA-AUTH-229, RN-AUTH-95
test('CA-AUTH-229: user_identities no tiene columna de access_token ni de refresh_token', function (): void {
    $columns = DB::connection('pgsql')->select(
        "select column_name from information_schema.columns where table_name = 'user_identities'"
    );
    $names = array_map(fn ($row) => $row->column_name, $columns);

    expect($names)->not->toContain('access_token')
        ->and($names)->not->toContain('refresh_token')
        ->and($names)->not->toContain('id_token');
});

// CA-AUTH-231, RN-AUTH-35
test('CA-AUTH-231: ninguna de las cinco rutas de 1.4 lleva el middleware module-enabled', function (): void {
    $routeNames = [
        'auth.identity-providers.index',
        'auth.oauth-authorizations.store',
        'auth.oauth.google.callback',
        'auth.identities.index',
        'auth.identities.destroy',
    ];

    foreach ($routeNames as $name) {
        $route = app('router')->getRoutes()->getByName($name);
        expect($route)->not->toBeNull();
        expect($route->gatherMiddleware())->not->toContain('module-enabled');
    }
});

// CA-AUTH-232, permisos.md §E.1
test('CA-AUTH-232: tras platform:sync-registry siguen habiendo exactamente siete permisos de auth', function (): void {
    test()->artisan('platform:sync-registry')->run();

    $count = DB::connection('pgsql_platform')->table('permissions')->where('module_code', 'auth')->count();

    expect($count)->toBe(7);
});

// CA-AUTH-236, operacion.md §E.1, issue #140
test('CA-AUTH-236: con AUTH_OAUTH_DRIVER=none, el flujo responde 422/estado_no_valido pero /auth/identities sigue funcionando', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('goog-236', ['email' => 'none-236@example.com']);

    // Vínculo previo creado con el proveedor simulado, ANTES de apagarlo.
    $cookie = loginWithFakeGoogleFor($tenant->slug, fakeGoogleClaims([
        'email' => 'none-236@example.com', 'email_verified' => '1',
    ]));

    config(['auth-local.oauth.driver' => 'none']);

    test()->postJson(coreApiUrl($tenant->slug, '/auth/oauth-authorizations'), ['provider' => 'google', 'intent' => 'login'])
        ->assertStatus(422);

    [, $anonCookie] = [null, sessionCookieValue(test()->getJson(coreApiUrl($tenant->slug, '/auth/csrf-cookie'))->assertNoContent())];
    $callback = withSessionCookie($anonCookie)
        ->get(coreApiUrl($tenant->slug, '/auth/oauth/google/callback?code=x&state=y'))
        ->assertRedirect();
    expect(oauthCallbackResultCode($callback))->toBe('estado_no_valido');

    $list = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/identities'))->assertOk();
    expect($list->json('meta.total'))->toBe(1);

    $publicId = $list->json('data.0.public_id');
    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/identities/{$publicId}"), ['current_password' => $password])
        ->assertNoContent();
});
