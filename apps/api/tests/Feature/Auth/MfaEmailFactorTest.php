<?php

use App\Modules\Auth\Application\MfaRecoveryCodeService;
use App\Modules\Auth\Domain\Models\MfaChallenge;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Infrastructure\Jobs\SendMfaChallengeCodeEmail;
use App\Modules\Auth\Infrastructure\Jobs\SendMfaEnrollmentCodeEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// funcional.md §D.4.1, §D.4.2, §D.4.3. El correo como segundo factor:
// alta, confirmación y login en dos pasos (REQ-AUTH-003, 1.3b).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-AUTH-146, RN-AUTH-75
test('CA-AUTH-146: alta email responde 201 con destination_masked y sin código, y encola el correo', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-146');
    enableEmailMfaMethod($tenant);

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(201);

    expect($enroll->json())->toHaveKey('destination_masked')
        ->and($enroll->json('destination_masked'))->toContain('···')
        ->and($enroll->json())->not->toHaveKey('secret')
        ->and($enroll->json())->not->toHaveKey('otpauth_uri')
        ->and($enroll->json())->not->toHaveKey('code');

    app(TenantContext::class)->runFor($tenant->id, function () use ($enroll): void {
        $factor = MfaFactor::query()->where('public_id', $enroll->json('public_id'))->firstOrFail();
        expect($factor->method->value)->toBe('email')
            ->and($factor->secret_encrypted)->toBeNull()
            ->and($factor->code_hash)->not->toBeNull()
            ->and($factor->confirmed_at)->toBeNull();
    });

    Queue::assertPushed(SendMfaEnrollmentCodeEmail::class);
});

// CA-AUTH-147, RN-AUTH-75
test('CA-AUTH-147: confirmar el alta email con el código correcto activa el factor y limpia el código', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-147');
    enableEmailMfaMethod($tenant);

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(201);

    $code = null;
    Queue::assertPushed(SendMfaEnrollmentCodeEmail::class, function ($job) use (&$code): bool {
        $code = $job->code;

        return true;
    });

    $enrollmentPublicId = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => MfaFactor::query()->where('method', 'email')->firstOrFail()->public_id,
    );

    $confirm = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enrollmentPublicId,
            'code' => $code,
        ])
        ->assertStatus(201);

    expect($confirm->json('factor.confirmed_at'))->not->toBeNull()
        ->and($confirm->json('recovery_codes'))->toHaveCount((int) config('auth-local.mfa.recovery_code_count'));

    app(TenantContext::class)->runFor($tenant->id, function () use ($enrollmentPublicId): void {
        $factor = MfaFactor::query()->where('public_id', $enrollmentPublicId)->firstOrFail();
        expect($factor->code_hash)->toBeNull()
            ->and($factor->code_expires_at)->toBeNull();
    });
});

// CA-AUTH-148, RN-AUTH-59
test('CA-AUTH-148: un código incorrecto responde 422 y el quinto intento mata el alta email', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-148');
    enableEmailMfaMethod($tenant);

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(201);

    for ($i = 0; $i < 5; $i++) {
        withSessionCookie($cookie)
            ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
                'enrollment' => $enroll->json('public_id'),
                'code' => '000000',
            ])
            ->assertStatus(422);
    }

    // El alta ha muerto: ni el código correcto la revive.
    $code = null;
    Queue::assertPushed(SendMfaEnrollmentCodeEmail::class, function ($job) use (&$code): bool {
        $code = $job->code;

        return true;
    });

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enroll->json('public_id'),
            'code' => $code,
        ])
        ->assertStatus(410);
});

// CA-AUTH-149, RN-AUTH-75
test('CA-AUTH-149: un código de alta caducado responde 422 aunque el alta siga viva', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-149');
    enableEmailMfaMethod($tenant);

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(201);

    $code = null;
    Queue::assertPushed(SendMfaEnrollmentCodeEmail::class, function ($job) use (&$code): bool {
        $code = $job->code;

        return true;
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($enroll): void {
        MfaFactor::query()->where('public_id', $enroll->json('public_id'))
            ->update(['code_expires_at' => now()->subMinute()]);
    });

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enroll->json('public_id'),
            'code' => $code,
        ])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function () use ($enroll): void {
        expect(MfaFactor::query()->where('public_id', $enroll->json('public_id'))->firstOrFail()->confirmed_at)->toBeNull();
    });
});

// CA-AUTH-150, RN-AUTH-76
test('CA-AUTH-150: abrir un segundo alta email invalida la primera', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-150');
    enableEmailMfaMethod($tenant);

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $first = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(201);

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(201);

    $secondCode = null;
    Queue::assertPushed(SendMfaEnrollmentCodeEmail::class, function ($job) use (&$secondCode): bool {
        $secondCode = $job->code;

        return true;
    });

    // La primera fila ya no existe: confirmarla responde 410, no 422.
    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $first->json('public_id'),
            'code' => $secondCode,
        ])
        ->assertStatus(410);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(MfaFactor::query()->where('user_id', $user->id)->where('method', 'email')->count())->toBe(1);
    });
});

// CA-AUTH-151, RN-AUTH-69
test('CA-AUTH-151: un tenant que no admite email responde 422 y no crea ninguna fila', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-151');

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(MfaFactor::query()->where('user_id', $user->id)->count())->toBe(0);
    });
});

// CA-AUTH-152, RN-AUTH-84
test('CA-AUTH-152: ninguna respuesta contiene el código en claro ni su hash, y el destino sale enmascarado', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-152');
    enableEmailMfaMethod($tenant);

    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(201);

    $enrollCode = null;
    Queue::assertPushed(SendMfaEnrollmentCodeEmail::class, function ($job) use (&$enrollCode): bool {
        $enrollCode = $job->code;

        return true;
    });

    $hash = hash('sha256', $enrollCode);

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enroll->json('public_id'),
            'code' => $enrollCode,
        ])
        ->assertStatus(201);

    $status = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/mfa'))->assertOk();

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);

    $challengeCode = null;
    Queue::assertPushed(SendMfaChallengeCodeEmail::class, function ($job) use (&$challengeCode): bool {
        $challengeCode = $job->code;

        return true;
    });

    $challengeHash = hash('sha256', $challengeCode);

    $bodies = [$enroll->json(), $status->json(), $challenge->json()];

    foreach ($bodies as $body) {
        $raw = json_encode($body);
        expect($raw)->not->toContain($enrollCode)
            ->and($raw)->not->toContain($hash)
            ->and($raw)->not->toContain($challengeCode)
            ->and($raw)->not->toContain($challengeHash);
    }

    expect($status->json('factors.0.destination_masked'))->toContain('···')
        ->and($challenge->json('destination_masked'))->toContain('···');
});

// CA-AUTH-153, RN-AUTH-52
test('CA-AUTH-153: un usuario cuyo único factor es email recibe 202 con destino enmascarado y encola el código', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-153');
    enableEmailMfaMethod($tenant);
    createConfirmedEmailFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);

    expect($challenge->json('method'))->toBe('email')
        ->and($challenge->json('destination_masked'))->toContain('···')
        ->and($challenge->json('available_methods'))->toBe(['email']);

    withSessionCookie(sessionCookieValue($challenge))
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertStatus(401);

    Queue::assertPushed(SendMfaChallengeCodeEmail::class);
});

// CA-AUTH-154
test('CA-AUTH-154: verificar el desafío con el código entregado completa el login', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-154');
    enableEmailMfaMethod($tenant);
    createConfirmedEmailFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);

    $code = null;
    Queue::assertPushed(SendMfaChallengeCodeEmail::class, function ($job) use (&$code): bool {
        $code = $job->code;

        return true;
    });

    $verify = withSessionCookie(sessionCookieValue($challenge))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => $code])
        ->assertOk();

    $me = withSessionCookie(sessionCookieValue($verify))
        ->getJson(coreApiUrl($tenant->slug, '/me'))
        ->assertOk();

    expect($verify->json())->toBe($me->json());
});

// CA-AUTH-155, RN-AUTH-78
test('CA-AUTH-155: un código usado, incorrecto o caducado responden 401 con cuerpo idéntico', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-155');
    enableEmailMfaMethod($tenant);
    createConfirmedEmailFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($challenge);

    $incorrect = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => '000000'])
        ->assertStatus(401);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        MfaChallenge::query()->update(['code_expires_at' => now()->subMinute()]);
    });

    $expired = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => '111111'])
        ->assertStatus(401);

    $strip = fn (array $body) => collect($body)->except('request_id')->all();
    expect($strip($incorrect->json()))->toBe($strip($expired->json()));

    // El desafío sigue vivo: puede seguir intentando.
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(MfaChallenge::query()->whereNull('consumed_at')->exists())->toBeTrue();
    });
});

// CA-AUTH-156
test('CA-AUTH-156: con TOTP y correo el desafío se abre en totp y no se encola ningún correo', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-156');
    enableEmailMfaMethod($tenant);
    createConfirmedTotpFactor($tenant, $user);
    createConfirmedEmailFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);

    expect($challenge->json('method'))->toBe('totp')
        ->and($challenge->json('available_methods'))->toBe(['totp', 'email']);

    Queue::assertNotPushed(SendMfaChallengeCodeEmail::class);
});

// CA-AUTH-157, RN-AUTH-79
test('CA-AUTH-157: la cuarta petición de reenvío responde 429 sin generar código', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-157');
    enableEmailMfaMethod($tenant);
    createConfirmedEmailFactor($tenant, $user);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($challenge);

    // La apertura ya cuenta como la primera entrega (§D.4.2): dos
    // reenvíos más agotan las 3 de AUTH_MFA_MAX_DELIVERIES.
    for ($i = 0; $i < 2; $i++) {
        withSessionCookie($cookie)
            ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-challenges'), ['method' => 'email'])
            ->assertOk();
    }

    $before = app(TenantContext::class)->runFor($tenant->id, fn () => MfaChallenge::query()->first());

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-challenges'), ['method' => 'email'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    app(TenantContext::class)->runFor($tenant->id, function () use ($before): void {
        $after = MfaChallenge::query()->first();
        expect($after->deliveries)->toBe($before->deliveries)
            ->and($after->attempts)->toBe($before->attempts)
            ->and($after->expires_at->equalTo($before->expires_at))->toBeTrue()
            ->and($after->consumed_at)->toBeNull();
    });

    Queue::assertPushedTimes(SendMfaChallengeCodeEmail::class, 3);
});

// CA-AUTH-158, RN-AUTH-79
test('CA-AUTH-158: cambiar de email a totp no consume una entrega ni encola nada', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-158');
    enableEmailMfaMethod($tenant);
    createConfirmedTotpFactor($tenant, $user);
    createConfirmedEmailFactor($tenant, $user, preferred: true);

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($challenge);

    expect($challenge->json('method'))->toBe('email');

    $before = app(TenantContext::class)->runFor($tenant->id, fn () => MfaChallenge::query()->first());
    expect($before->deliveries)->toBe(1);

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-challenges'), ['method' => 'totp'])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($before): void {
        $after = MfaChallenge::query()->first();
        expect($after->deliveries)->toBe($before->deliveries)
            ->and($after->method->value)->toBe('totp')
            ->and($after->code_hash)->toBeNull()
            ->and($after->code_expires_at)->toBeNull();
    });

    Queue::assertPushedTimes(SendMfaChallengeCodeEmail::class, 1);
});

// CA-AUTH-159, §D.4.4: "ningún camino le deja entrar sin segundo factor"
// se comprueba con el camino que SÍ funciona si el correo no llega — un
// código de respaldo —, no simulando el fallo del trabajo (Queue::fake()
// ya impide que se ejecute ninguno, correo incluido).
test('CA-AUTH-159: un código de respaldo completa el login con email como único factor', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-159');
    enableEmailMfaMethod($tenant);
    createConfirmedEmailFactor($tenant, $user);

    $recoveryCodesInClear = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(MfaRecoveryCodeService::class)->generateInitialBatch($user),
    );

    $challenge = openMfaChallengeFor($tenant->slug, $user->email, $password);

    Queue::assertPushed(SendMfaChallengeCodeEmail::class);

    withSessionCookie(sessionCookieValue($challenge))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['recovery_code' => $recoveryCodesInClear[0]])
        ->assertOk();
});
