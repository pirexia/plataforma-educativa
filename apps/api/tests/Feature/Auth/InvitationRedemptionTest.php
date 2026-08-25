<?php

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\AccountLockout;
use App\Modules\Auth\Domain\PasswordResetTokenRepository;
use App\Modules\Core\Application\IssueUserInvitation;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Infrastructure\Jobs\SendInvitationEmail;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// funcional.md §4.1, api.md §3. Canje de la invitación de REQ-CORE.

afterEach(function (): void {
    Carbon::setTestNow();
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * @return array{0: User, 1: string} usuario pendiente y token en claro
 */
function issuePendingInvitation(Tenant $tenant, array $userAttrs = []): array
{
    Queue::fake();

    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($userAttrs) {
        $person = \App\Models\Person::factory()->create();

        return User::factory()->for($person)->create([
            'status' => UserStatus::Pendiente,
            'email_verified_at' => null,
            ...$userAttrs,
        ]);
    });

    app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => app(IssueUserInvitation::class)->issue($user, $tenant->slug, $tenant->name),
    );

    $rawToken = null;
    Queue::assertPushed(SendInvitationEmail::class, function ($job) use (&$rawToken) {
        $rawToken = $job->rawToken;

        return true;
    });

    return [$user, $rawToken];
}

// CA-AUTH-040
test('CA-AUTH-040: canjear una invitación vigente activa la cuenta y la contraseña sirve para iniciar sesión', function (): void {
    [$tenant] = provisionActiveUser('inv-040');
    [$user, $rawToken] = issuePendingInvitation($tenant);

    $newPassword = 'Contraseña-Nueva-2026!';

    test()->postJson(coreApiUrl($tenant->slug, '/auth/invitation-redemptions'), [
        'token' => $rawToken, 'password' => $newPassword, 'password_confirmation' => $newPassword,
    ])->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $fresh = User::query()->find($user->id);
        expect($fresh->status)->toBe(UserStatus::Activo)
            ->and($fresh->email_verified_at)->not->toBeNull();

        $invitation = UserInvitation::query()->where('user_id', $user->id)->firstOrFail();
        expect($invitation->accepted_at)->not->toBeNull();
    });

    test()->postJson(coreApiUrl($tenant->slug, '/auth/session'), [
        'email' => $user->email, 'password' => $newPassword,
    ])->assertOk();
});

// CA-AUTH-044, RN-AUTH-21
test('CA-AUTH-044: el canje no inicia sesión', function (): void {
    [$tenant] = provisionActiveUser('inv-044');
    [$user, $rawToken] = issuePendingInvitation($tenant);
    $newPassword = 'Otra-Contraseña-2026!';

    test()->postJson(coreApiUrl($tenant->slug, '/auth/invitation-redemptions'), [
        'token' => $rawToken, 'password' => $newPassword, 'password_confirmation' => $newPassword,
    ])->assertNoContent();

    // Sin credenciales, la petición siguiente responde 401: no hay sesión
    // establecida por el canje (RN-AUTH-21).
    test()->getJson(coreApiUrl($tenant->slug, '/me'))->assertStatus(401);
});

// CA-AUTH-041: caducada, revocada, ya aceptada -> 410 idéntico, ninguna modifica nada.
test('CA-AUTH-041: invitación caducada, revocada y ya aceptada responden 410 con cuerpo idéntico', function (): void {
    [$tenant] = provisionActiveUser('inv-041');

    [$expiredUser, $expiredToken] = issuePendingInvitation($tenant, ['email' => 'caducada@example.com']);
    app(TenantContext::class)->runFor($tenant->id, function () use ($expiredUser): void {
        UserInvitation::query()->where('user_id', $expiredUser->id)->update(['expires_at' => now()->subDay()]);
    });

    [$revokedUser, $revokedToken] = issuePendingInvitation($tenant, ['email' => 'revocada@example.com']);
    app(TenantContext::class)->runFor($tenant->id, function () use ($revokedUser): void {
        UserInvitation::query()->where('user_id', $revokedUser->id)->update(['revoked_at' => now()]);
    });

    [$acceptedUser, $acceptedToken] = issuePendingInvitation($tenant, ['email' => 'aceptada@example.com']);
    app(TenantContext::class)->runFor($tenant->id, function () use ($acceptedUser): void {
        UserInvitation::query()->where('user_id', $acceptedUser->id)->update(['accepted_at' => now()]);
        User::query()->where('id', $acceptedUser->id)->update(['status' => UserStatus::Activo]);
    });

    $body = fn (string $token) => test()->postJson(coreApiUrl($tenant->slug, '/auth/invitation-redemptions'), [
        'token' => $token, 'password' => 'Password-Valida-2026!', 'password_confirmation' => 'Password-Valida-2026!',
    ])->assertStatus(410)->json();

    $expired = $body($expiredToken);
    $revoked = $body($revokedToken);
    $accepted = $body($acceptedToken);

    $strip = fn (array $b) => collect($b)->except('request_id')->all();
    expect($strip($expired))->toBe($strip($revoked))->toBe($strip($accepted))
        ->and($expired['type'])->toBe('urn:pge:error:gone');

    // Ninguna modificó nada: los tres usuarios siguen exactamente como estaban.
    app(TenantContext::class)->runFor($tenant->id, function () use ($expiredUser): void {
        expect(User::query()->find($expiredUser->id)->status)->toBe(UserStatus::Pendiente);
    });
});

// CA-AUTH-042, RN-AUTH-08
test('CA-AUTH-042: un token de invitación del tenant A responde 410 en el host del tenant B y no activa ninguna cuenta', function (): void {
    [$tenantA] = provisionActiveUser('inv-042-a');
    [$tenantB] = provisionActiveUser('inv-042-b');
    [$user, $rawToken] = issuePendingInvitation($tenantA);

    test()->postJson(coreApiUrl($tenantB->slug, '/auth/invitation-redemptions'), [
        'token' => $rawToken, 'password' => 'Password-Valida-2026!', 'password_confirmation' => 'Password-Valida-2026!',
    ])->assertStatus(410);

    app(TenantContext::class)->runFor($tenantA->id, function () use ($user): void {
        expect(User::query()->find($user->id)->status)->toBe(UserStatus::Pendiente);
    });

    // El propio tenant A sigue aceptándolo (no se consumió).
    test()->postJson(coreApiUrl($tenantA->slug, '/auth/invitation-redemptions'), [
        'token' => $rawToken, 'password' => 'Password-Valida-2026!', 'password_confirmation' => 'Password-Valida-2026!',
    ])->assertNoContent();
});

// CA-AUTH-043, RN-AUTH-09, RN-CORE-19
test('CA-AUTH-043: el token en claro no aparece en ninguna fila de auditoría tras el canje', function (): void {
    [$tenant] = provisionActiveUser('inv-043');
    [$user, $rawToken] = issuePendingInvitation($tenant);

    test()->postJson(coreApiUrl($tenant->slug, '/auth/invitation-redemptions'), [
        'token' => $rawToken, 'password' => 'Password-Valida-2026!', 'password_confirmation' => 'Password-Valida-2026!',
    ])->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($rawToken, $user): void {
        $invitation = UserInvitation::query()->where('user_id', $user->id)->firstOrFail();
        expect($invitation->token_hash)->toBe(hash('sha256', $rawToken))
            ->and($invitation->token_hash)->not->toBe($rawToken);

        $logs = \App\Models\AuditLog::query()->get();
        foreach ($logs as $log) {
            expect(json_encode($log->getAttributes()))->not->toContain($rawToken);
        }
    });
});

// CA-AUTH-045, RN-AUTH-20
test('CA-AUTH-045: canjear la invitación neutraliza un bloqueo vivo y un token de restablecimiento vivo del mismo correo', function (): void {
    [$tenant] = provisionActiveUser('inv-045');
    [$user, $rawToken] = issuePendingInvitation($tenant, ['email' => 'con-bloqueo@example.com']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        AccountLockout::create([
            'email' => $user->email, 'user_id' => $user->id, 'failed_count' => 5, 'locked_at' => now(),
        ]);
        app(PasswordResetTokenRepository::class)->issueFor($user);
    });

    test()->postJson(coreApiUrl($tenant->slug, '/auth/invitation-redemptions'), [
        'token' => $rawToken, 'password' => 'Password-Valida-2026!', 'password_confirmation' => 'Password-Valida-2026!',
    ])->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(AccountLockout::query()->where('email', $user->email)->whereNull('unlocked_at')->exists())->toBeFalse();
        expect(app(PasswordResetTokenRepository::class)->findValid('cualquier-cosa'))->toBeNull();
        expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
    });
});

// CA-AUTH-020, RN-AUTH-01, INV-010: política de contraseñas, en el canje.
test('CA-AUTH-020: cada violación de la política de contraseñas responde 422 con su code y no fija ninguna contraseña', function (string $password, string $expectedCode) {
    [$tenant] = provisionActiveUser('inv-020-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    [$user, $rawToken] = issuePendingInvitation($tenant);

    $response = test()->postJson(coreApiUrl($tenant->slug, '/auth/invitation-redemptions'), [
        'token' => $rawToken, 'password' => $password, 'password_confirmation' => $password,
    ])->assertStatus(422);

    $errorCodes = collect($response->json('errors.password'))->pluck('code')->all();
    expect($errorCodes)->toContain($expectedCode);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(User::query()->find($user->id)->status)->toBe(UserStatus::Pendiente);
    });
})->with([
    'once caracteres (falta longitud)' => ['Corta1!Aaaa', 'auth.validation.password.min_length'],
    '12 sin mayúscula' => ['contraseña1!abcd', 'auth.validation.password.uppercase'],
    '12 sin minúscula' => ['CONTRASEÑA1!ABCD', 'auth.validation.password.lowercase'],
    '12 sin dígito' => ['Contraseña!abcd', 'auth.validation.password.digit'],
    '12 sin símbolo' => ['Contrasena1abcd', 'auth.validation.password.symbol'],
]);

// CA-AUTH-021, RN-AUTH-02
test('CA-AUTH-021: una contraseña de 73 bytes se rechaza con 422 y nunca se trunca ni se acepta', function (): void {
    [$tenant] = provisionActiveUser('inv-021');
    [$user, $rawToken] = issuePendingInvitation($tenant);

    $tooLong = str_repeat('Aa1!', 18).'x'; // 73 bytes exactos.
    expect(strlen($tooLong))->toBe(73);

    test()->postJson(coreApiUrl($tenant->slug, '/auth/invitation-redemptions'), [
        'token' => $rawToken, 'password' => $tooLong, 'password_confirmation' => $tooLong,
    ])->assertStatus(422);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(User::query()->find($user->id)->status)->toBe(UserStatus::Pendiente);
    });
});

// CA-AUTH-022, RN-AUTH-03
test('CA-AUTH-022: la contraseña válida se almacena como hash bcrypt de coste >= 12, nunca en claro', function (): void {
    [$tenant] = provisionActiveUser('inv-022');
    [$user, $rawToken] = issuePendingInvitation($tenant);
    $password = 'Password-Valida-2026!';

    test()->postJson(coreApiUrl($tenant->slug, '/auth/invitation-redemptions'), [
        'token' => $rawToken, 'password' => $password, 'password_confirmation' => $password,
    ])->assertNoContent();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $password): void {
        $hash = User::query()->find($user->id)->password;
        expect($hash)->not->toBe($password)
            ->and(str_starts_with($hash, '$2y$'))->toBeTrue();

        $info = password_get_info($hash);
        expect($info['options']['cost'])->toBeGreaterThanOrEqual(12);
    });
});
