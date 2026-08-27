<?php

use App\Models\AuditLog;
use App\Modules\Auth\Domain\LoginOutcome;
use App\Modules\Auth\Domain\Models\LoginAttempt;
use App\Modules\Auth\Domain\Models\MfaChallenge;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// funcional.md §C.4.4, §C.6. Login en dos pasos (REQ-AUTH-003, 1.3).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-AUTH-115, RN-AUTH-52
test('CA-AUTH-115: credenciales correctas con factor confirmado responden 202 sin autenticar', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-115');
    createConfirmedTotpFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($challenge);

    withSessionCookie($cookie)
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertStatus(401);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserSession::query()->where('user_id', $user->id)->count())->toBe(0);
    });
});

// CA-AUTH-116, RN-AUTH-53/56
test('CA-AUTH-116: la respuesta 202 no contiene token, session_id ni secreto', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-116');
    createConfirmedTotpFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);

    $raw = json_encode($challenge->json());
    expect($raw)->not->toContain('token')
        ->and($challenge->json())->not->toHaveKey('session_id');
});

// CA-AUTH-117, RN-AUTH-53/72
test('CA-AUTH-117: verificar un desafío desde otra sesión responde 410, igual que uno inexistente', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-117');
    $secret = createConfirmedTotpFactor($tenant, $user);

    $challengeA = openMfaChallengeFor($tenant->slug, $user->email, $password);
    $cookieA = sessionCookieValue($challengeA);

    // Sesión anónima B, distinta, sin desafío propio.
    resetSessionState();
    $anonymousB = test()->getJson(coreApiUrl($tenant->slug, '/auth/csrf-cookie'))->assertNoContent();
    $cookieB = sessionCookieValue($anonymousB);

    $fromB = withSessionCookie($cookieB)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => currentTotpCode($secret)])
        ->assertStatus(410);

    resetSessionState();
    $anonymousC = test()->getJson(coreApiUrl($tenant->slug, '/auth/csrf-cookie'))->assertNoContent();
    $fromC = withSessionCookie(sessionCookieValue($anonymousC))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => '000000'])
        ->assertStatus(410);

    $strip = fn (array $body) => collect($body)->except('request_id')->all();
    expect($strip($fromB->json()))->toBe($strip($fromC->json()));

    // La sesión A, con su propio desafío, sí puede verificar.
    withSessionCookie($cookieA)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => currentTotpCode($secret)])
        ->assertOk();
});

// CA-AUTH-118, ADR-039 §4.5
test('CA-AUTH-118: verificar con el código correcto completa el login, regenera la sesión y audita un solo login', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-118');
    $secret = createConfirmedTotpFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
    $beforeSessionId = $challenge->getCookie(config('session.cookie'))->getValue();

    $verify = withSessionCookie(sessionCookieValue($challenge))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => currentTotpCode($secret)])
        ->assertOk();

    $afterSessionId = $verify->getCookie(config('session.cookie'))->getValue();
    expect($afterSessionId)->not->toBe($beforeSessionId);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserSession::query()->where('user_id', $user->id)->count())->toBe(1);
        expect(AuditLog::query()->where('event', 'login')->count())->toBe(1);
    });

    $me = withSessionCookie(sessionCookieValue($verify))
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertOk();

    expect($verify->json())->toBe($me->json());
});

// CA-AUTH-119, RN-AUTH-54
test('CA-AUTH-119: un desafío caducado responde 410 en la verificación', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-119');
    $secret = createConfirmedTotpFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        MfaChallenge::query()->update(['expires_at' => now()->subMinute()]);
    });

    withSessionCookie(sessionCookieValue($challenge))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => currentTotpCode($secret)])
        ->assertStatus(410);
});

// CA-AUTH-120, RN-AUTH-54
test('CA-AUTH-120: reenviar/cambiar de método no reinicia el contador de intentos ni la caducidad', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-120');
    createConfirmedTotpFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($challenge);

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => '000000'])
        ->assertStatus(401);

    $before = app(TenantContext::class)->runFor($tenant->id, fn () => MfaChallenge::query()->first());

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-challenges'), ['method' => 'totp'])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($before): void {
        $after = MfaChallenge::query()->first();
        expect($after->attempts)->toBe($before->attempts)
            ->and($after->expires_at->equalTo($before->expires_at))->toBeTrue();
    });
});

// CA-AUTH-121, RN-AUTH-58
test('CA-AUTH-121: un código TOTP ya usado se rechaza si se reenvía dentro de la misma ventana', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-121');
    $secret = createConfirmedTotpFactor($tenant, $user);
    $code = currentTotpCode($secret);

    loginWithTotpFor($tenant->slug, $user->email, $password, $secret);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
    withSessionCookie(sessionCookieValue($challenge))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => $code])
        ->assertStatus(401);
});

// CA-AUTH-122
test('CA-AUTH-122: un usuario sin MFA y sin obligación inicia sesión en un solo paso, como en 1.2', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-122');

    $login = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertOk();

    expect($login->json('mfa.enrolled'))->toBeFalse()
        ->and($login->json('mfa.obligated'))->toBeFalse();
});

// CA-AUTH-123/124, RN-AUTH-63/64
test('CA-AUTH-123/124: los fallos de segundo factor bloquean la cuenta, y el sexto intento de login es 423', function (): void {
    Queue::fake();
    // El foco es RN-AUTH-63/64 (bloqueo de cuenta), no el límite de tasa
    // de CA-AUTH-074 (ya cubierto en su propio test) — seis POST /auth/session
    // legítimos no deben confundirse con el límite de 5/min de session_email.
    config(['auth-local.rate_limits.session_email.max' => 100]);
    [$tenant, $user, $password] = provisionActiveUser('mfa-123');
    createConfirmedTotpFactor($tenant, $user);

    for ($i = 0; $i < 5; $i++) {
        $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
        withSessionCookie(sessionCookieValue($challenge))
            ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => '000000'])
            ->assertStatus(401);
    }

    // Sexto intento de login: bloqueado antes de llegar al segundo factor.
    resetSessionState();
    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertStatus(423);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $attempts = LoginAttempt::query()->where('user_id', $user->id)->get();
        expect($attempts->where('outcome', LoginOutcome::PendienteSegundoFactor)->count())->toBe(5)
            ->and($attempts->where('outcome', LoginOutcome::SegundoFactorInvalido)->count())->toBe(5)
            ->and($attempts->where('outcome', LoginOutcome::Exito)->count())->toBe(0);
    });
});

// CA-AUTH-125
test('CA-AUTH-125: cuatro fallos de segundo factor seguidos de un login completo no bloquean la cuenta', function (): void {
    Queue::fake();
    config(['auth-local.rate_limits.session_email.max' => 100]);
    [$tenant, $user, $password] = provisionActiveUser('mfa-125');
    $secret = createConfirmedTotpFactor($tenant, $user);

    for ($i = 0; $i < 4; $i++) {
        $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
        withSessionCookie(sessionCookieValue($challenge))
            ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => '000000'])
            ->assertStatus(401);
    }

    loginWithTotpFor($tenant->slug, $user->email, $password, $secret);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
    withSessionCookie(sessionCookieValue($challenge))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => '000000'])
        ->assertStatus(401);

    // Cuenta NO bloqueada: un séptimo intento de login sigue abriendo desafío.
    openMfaChallengeFor($tenant->slug, $user->email, $password);
});
