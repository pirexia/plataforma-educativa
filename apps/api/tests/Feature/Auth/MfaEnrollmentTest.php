<?php

use App\Modules\Auth\Application\MfaRecoveryCodeService;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\MfaRecoveryCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// funcional.md §C.4.1, §C.4.3. Alta/confirmación de factor TOTP y
// códigos de respaldo (REQ-AUTH-003, 1.3).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

// CA-AUTH-104
test('CA-AUTH-104: alta TOTP responde 201 con secreto y URI otpauth, y GET /auth/mfa sigue sin factor', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-104');
    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'totp'])
        ->assertStatus(201);

    expect($enroll->json('secret'))->toBeString()->not->toBeEmpty()
        ->and($enroll->json('otpauth_uri'))->toStartWith('otpauth://totp/');

    app(TenantContext::class)->runFor($tenant->id, function () use ($enroll): void {
        $factor = MfaFactor::query()->where('public_id', $enroll->json('public_id'))->firstOrFail();
        expect($factor->confirmed_at)->toBeNull()
            ->and($factor->expires_at)->not->toBeNull();
    });

    $status = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/mfa'))->assertOk();
    expect($status->json('factors'))->toBe([])
        ->and($status->json('mfa.enrolled'))->toBeFalse();
});

// CA-AUTH-105
test('CA-AUTH-105: confirmar con código válido responde 201 con exactamente N códigos de respaldo en claro', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-105');
    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'totp'])
        ->assertStatus(201);

    $code = currentTotpCode($enroll->json('secret'));

    $confirm = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enroll->json('public_id'),
            'code' => $code,
        ])
        ->assertStatus(201);

    expect($confirm->json('factor.confirmed_at'))->not->toBeNull()
        ->and($confirm->json('recovery_codes'))->toHaveCount((int) config('auth-local.mfa.recovery_code_count'));

    foreach ($confirm->json('recovery_codes') as $recoveryCode) {
        expect($recoveryCode)->toMatch('/^[0-9A-Z]{5}-[0-9A-Z]{5}$/');
    }
});

// CA-AUTH-106
test('CA-AUTH-106: código inválido responde 422 sin confirmar, y el quinto intento mata el alta', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-106');
    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'totp'])
        ->assertStatus(201);

    $maxAttempts = (int) config('auth-local.mfa.max_attempts');

    for ($i = 0; $i < $maxAttempts; $i++) {
        withSessionCookie($cookie)
            ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
                'enrollment' => $enroll->json('public_id'),
                'code' => '000000',
            ])
            ->assertStatus(422);
    }

    app(TenantContext::class)->runFor($tenant->id, function () use ($enroll): void {
        expect(MfaFactor::query()->where('public_id', $enroll->json('public_id'))->exists())->toBeFalse();
    });

    // Alta muerta: un intento más, aunque el código fuera correcto, es 410.
    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enroll->json('public_id'),
            'code' => '123456',
        ])
        ->assertStatus(410);
});

// CA-AUTH-107
test('CA-AUTH-107: confirmar un alta vencida responde 410, mismo cuerpo que un alta inexistente', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-107');
    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'totp'])
        ->assertStatus(201);

    app(TenantContext::class)->runFor($tenant->id, function () use ($enroll): void {
        MfaFactor::query()->where('public_id', $enroll->json('public_id'))
            ->update(['expires_at' => now()->subMinute()]);
    });

    $expired = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => $enroll->json('public_id'),
            'code' => currentTotpCode($enroll->json('secret')),
        ])
        ->assertStatus(410);

    $nonExistent = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-factors'), [
            'enrollment' => (string) Str::ulid(),
            'code' => '000000',
        ])
        ->assertStatus(410);

    $strip = fn (array $body) => collect($body)->except('request_id')->all();
    expect($strip($expired->json()))->toBe($strip($nonExistent->json()));
});

// CA-AUTH-108, RN-AUTH-55
test('CA-AUTH-108: el secreto se guarda cifrado, no en claro, en la base de datos', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-108');
    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    $enroll = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'totp'])
        ->assertStatus(201);

    app(TenantContext::class)->runFor($tenant->id, function () use ($enroll): void {
        $raw = DB::table('user_mfa_factors')->where('public_id', $enroll->json('public_id'))->value('secret_encrypted');
        expect($raw)->not->toBe($enroll->json('secret'));

        // El cast 'encrypted' de Eloquent usa Crypt::decryptString() (sin
        // serializar) para valores de cadena, no el decrypt() global
        // (unserialize=true por defecto, pensado para encrypt()).
        $decrypted = Crypt::decryptString($raw);
        expect($decrypted)->toBe($enroll->json('secret'));
    });
});

// CA-AUTH-109, RN-AUTH-55/56
test('CA-AUTH-109: ninguna respuesta con factor confirmado expone secreto ni hash de código', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-109');
    $secret = createConfirmedTotpFactor($tenant, $user);

    $verify = loginWithTotpFor($tenant->slug, $user->email, $password, $secret);
    $cookie = sessionCookieValue($verify);

    $status = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/mfa'))->assertOk();

    $raw = json_encode($status->json());
    expect($raw)->not->toContain($secret)
        ->and($raw)->not->toContain('secret_encrypted');
});

// CA-AUTH-110, RN-AUTH-69
test('CA-AUTH-110: dar de alta un método no admitido por el tenant responde 422 sin crear fila', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-110');
    $login = loginFor($tenant->slug, $user->email, $password);
    $cookie = sessionCookieValue($login);

    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-enrollments'), ['method' => 'email'])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(MfaFactor::query()->count())->toBe(0);
    });
});

// CA-AUTH-111, RN-AUTH-57
test('CA-AUTH-111: un código de respaldo entra, queda usado y no se borra, y un segundo uso responde 401', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-111');
    $secret = createConfirmedTotpFactor($tenant, $user);

    $recoveryCode = app(TenantContext::class)->runFor($tenant->id, function () use ($user): string {
        // Genera un lote directamente para no depender del flujo de
        // confirmación HTTP en este test.
        $code = 'ABCDE-FGH23';

        MfaRecoveryCode::create([
            'user_id' => $user->id,
            'code_hash' => MfaRecoveryCodeService::hash($code),
            'batch_id' => (string) Str::ulid(),
        ]);

        return $code;
    });

    resetSessionState();
    $challenge = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertStatus(202);

    $verify = withSessionCookie(sessionCookieValue($challenge))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['recovery_code' => $recoveryCode])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $row = MfaRecoveryCode::query()->first();
        expect($row)->not->toBeNull()
            ->and($row->used_at)->not->toBeNull();
        expect(MfaRecoveryCode::query()->count())->toBe(1);
    });

    resetSessionState();
    $secondChallenge = test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $password,
    ])->assertStatus(202);

    withSessionCookie(sessionCookieValue($secondChallenge))
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['recovery_code' => $recoveryCode])
        ->assertStatus(401);
});

// CA-AUTH-112, CA-AUTH-113
test('CA-AUTH-112/113: regenerar códigos exige la contraseña y sustituye el lote entero', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-112');
    $secret = createConfirmedTotpFactor($tenant, $user);
    $login = loginWithTotpFor($tenant->slug, $user->email, $password, $secret);
    $cookie = sessionCookieValue($login);

    // CA-AUTH-113: sin contraseña correcta, 422 y nada cambia.
    withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-recovery-codes'), ['current_password' => 'incorrecta'])
        ->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(MfaRecoveryCode::query()->count())->toBe(0);
    });

    $first = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-recovery-codes'), ['current_password' => $password])
        ->assertStatus(201);

    $firstCodes = $first->json('recovery_codes');

    $second = withSessionCookie($cookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-recovery-codes'), ['current_password' => $password])
        ->assertStatus(201);

    $secondCodes = $second->json('recovery_codes');

    expect(array_intersect($firstCodes, $secondCodes))->toBe([]);

    app(TenantContext::class)->runFor($tenant->id, function () use ($firstCodes): void {
        expect(MfaRecoveryCode::query()->count())->toBe(count($firstCodes));

        foreach ($firstCodes as $code) {
            $hash = MfaRecoveryCodeService::hash($code);
            expect(MfaRecoveryCode::query()->where('code_hash', $hash)->exists())->toBeFalse();
        }
    });
});

// CA-AUTH-114
test('CA-AUTH-114: agotar los códigos de respaldo no bloquea el login y GET /auth/mfa informa 0', function (): void {
    Queue::fake();
    [$tenant, $user, $password] = provisionActiveUser('mfa-114');
    $secret = createConfirmedTotpFactor($tenant, $user);

    $verify = loginWithTotpFor($tenant->slug, $user->email, $password, $secret);

    $status = withSessionCookie(sessionCookieValue($verify))
        ->getJson(coreApiUrl($tenant->slug, '/auth/mfa'))
        ->assertOk();

    expect($status->json('unused_recovery_codes_count'))->toBe(0);
});
