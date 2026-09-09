<?php

use App\Modules\Backoffice\Application\IssuePlatformAdminInvitation;
use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminInvitation;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaFactor;
use App\Modules\Backoffice\Domain\Models\PlatformAdminRole;
use App\Modules\Backoffice\Domain\Models\PlatformAdminSession;
use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use App\Modules\Backoffice\Infrastructure\Mail\PlatformAdminInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

// currentTotpCode(), withSessionCookie() y sessionCookieValue() están
// definidas globalmente en tests/Pest.php (REQ-AUTH-003).

/**
 * Issue #173: `CreateAdminCommand` generaba una contraseña descartada,
 * sin MFA y sin invitación — el chasis de 1.6 era inutilizable de
 * extremo a extremo. No existe ningún `CA-BO`/`RN-BO` numerado para el
 * mecanismo de invitación de `platform_admins` (hueco real de la
 * especificación, reportado en la resolución del issue, no inventado
 * aquí): estos tests referencian el issue de GitHub como identificador
 * trazable, siguiendo `INV-015` en su espíritu — hasta que
 * `docs/modulos/REQ-BO/funcional.md` incorpore el criterio de aceptación
 * correspondiente.
 */
function piPlatformHost(): string
{
    return (string) config('backoffice.host');
}

function piAllowCurrentTestIp(): void
{
    PlatformIpAllowlistEntry::create([
        'cidr' => '127.0.0.1/32',
        'description' => 'Suite de tests',
        'enabled' => true,
    ]);
}

/**
 * @return array{0: PlatformAdmin, 1: string}
 */
function piCreateEnrolledAdmin(string $role = 'superadministrador'): array
{
    $secret = (new Google2FA)->generateSecretKey();

    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'Admin de prueba',
        'password' => Hash::make('contraseña-larga-de-prueba'),
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
        'mfa_enrolled_at' => now(),
    ]);

    PlatformAdminRole::create(['platform_admin_id' => $admin->id, 'role' => PlatformRole::from($role)]);

    PlatformAdminMfaFactor::create([
        'platform_admin_id' => $admin->id,
        'type' => 'totp',
        'secret_encrypted' => $secret,
        'confirmed_at' => now(),
    ]);

    return [$admin, $secret];
}

function piReauthenticatedSessionCookie(PlatformAdmin $admin, string $secret): string
{
    $response = test()->postJson('http://'.piPlatformHost().'/api/platform/v1/auth/reauthenticate', [
        'password' => 'contraseña-larga-de-prueba',
        'code' => currentTotpCode($secret),
    ]);

    $response->assertNoContent();

    return sessionCookieValue($response);
}

function piWithReauthenticatedCookie(string $cookie): mixed
{
    return test()->withCredentials()->withUnencryptedCookie(config('backoffice.session_cookie'), $cookie);
}

/**
 * Captura el token en claro del correo despachado (`Mail::fake()` ya
 * activo): el token no se persiste nunca, así que sólo se puede leer
 * aquí, igual que en el precedente de `Core`.
 */
function piIssueAndCaptureToken(PlatformAdmin $admin): string
{
    app(IssuePlatformAdminInvitation::class)->issue($admin);

    $rawToken = null;

    Mail::assertSent(PlatformAdminInvitationMail::class, function (PlatformAdminInvitationMail $mail) use (&$rawToken): bool {
        $rawToken = Str::afterLast($mail->activationUrl, '/');

        return true;
    });

    return (string) $rawToken;
}

afterEach(function (): void {
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('platform_admin_sessions')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_invitations')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
});

// Issue #173: platform_admin_invitations es tabla de plataforma, sin
// tenant_id — mismo criterio que CA-BO-075 para las once tablas del
// chasis.
test('issue #173: platform_admin_invitations no tiene columna tenant_id', function (): void {
    $hasTenantId = DB::selectOne(
        "SELECT 1 AS found FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'platform_admin_invitations' AND column_name = 'tenant_id'"
    );

    expect($hasTenantId)->toBeNull();
});

// Issue #173: POST /admins crea sin contraseña utilizable y despacha de
// verdad la invitación en cola (api.md §2.2 dice "crea sin contraseña
// utilizable e invita" — antes de la corrección, no invitaba).
test('issue #173: POST /admins despacha la invitación y deja rastro en admin_action_logs', function (): void {
    Mail::fake();
    piAllowCurrentTestIp();
    [$actor, $secret] = piCreateEnrolledAdmin();

    test()->actingAs($actor, 'platform');
    $cookie = piReauthenticatedSessionCookie($actor, $secret);

    $response = piWithReauthenticatedCookie($cookie)->postJson('http://'.piPlatformHost().'/api/platform/v1/admins', [
        'email' => 'invitado@example.com',
        'name' => 'Invitado de prueba',
        'locale' => 'es-ES',
        'role' => 'soporte',
    ]);

    $response->assertStatus(201);

    $admin = PlatformAdmin::query()->where('email', 'invitado@example.com')->firstOrFail();

    expect(PlatformAdminInvitation::query()->where('platform_admin_id', $admin->id)->whereNull('accepted_at')->whereNull('revoked_at')->exists())->toBeTrue();
    expect(AdminActionLog::query()->where('action', 'admin.invitado')->where('subject_public_id', $admin->public_id)->exists())->toBeTrue();

    Mail::assertSent(PlatformAdminInvitationMail::class, fn (PlatformAdminInvitationMail $mail): bool => str_contains($mail->activationUrl, piPlatformHost().'/activar/'));
});

// Issue #173: bo:create-admin usa el mismo punto único que POST /admins
// (PlatformAdminManagementService::create()) y por tanto también invita
// — antes de la corrección, el comando duplicaba la lógica a mano y
// nunca despachaba nada, pese a que su propio docblock decía que debía.
test('issue #173: bo:create-admin emite una invitación real y queda auditado como console', function (): void {
    Mail::fake();

    $this->artisan('bo:create-admin', [
        '--email' => 'consola@example.com',
        '--name' => 'Alta por consola',
        '--role' => 'superadministrador',
    ])->assertSuccessful();

    $admin = PlatformAdmin::query()->where('email', 'consola@example.com')->firstOrFail();

    expect(PlatformAdminInvitation::query()->where('platform_admin_id', $admin->id)->whereNull('accepted_at')->exists())->toBeTrue();

    $created = AdminActionLog::query()->where('action', 'admin.creado')->where('subject_public_id', $admin->public_id)->firstOrFail();
    expect($created->actor_type->value)->toBe('console');

    $invited = AdminActionLog::query()->where('action', 'admin.invitado')->where('subject_public_id', $admin->public_id)->firstOrFail();
    expect($invited->actor_type->value)->toBe('console');

    Mail::assertSent(PlatformAdminInvitationMail::class);
});

// Issue #173: emitir una segunda invitación (p. ej. tras el fallo de
// entrega de la primera) revoca la viva — mismo criterio que RN-CORE-09
// en user_invitations. El token antiguo deja de servir para canjear.
test('issue #173: emitir una nueva invitación revoca la anterior', function (): void {
    Mail::fake();

    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'x',
        'locale' => 'es-ES',
        'password' => Str::password(40),
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);

    $issuer = app(IssuePlatformAdminInvitation::class);
    $first = $issuer->issue($admin);
    $issuer->issue($admin);

    expect($first->fresh()->revoked_at)->not->toBeNull();
    expect(PlatformAdminInvitation::query()->where('platform_admin_id', $admin->id)->whereNull('accepted_at')->whereNull('revoked_at')->count())->toBe(1);
});

// Issue #173, operacion.md §5 paso 6: el canje fija la contraseña y NO
// abre sesión — la sesión nace sólo de POST /auth/session, mismo
// criterio que RN-AUTH-21 en el tenant.
test('issue #173: el canje de la invitación fija la contraseña, audita y no abre sesión', function (): void {
    Mail::fake();

    piAllowCurrentTestIp();

    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'Nuevo admin',
        'locale' => 'es-ES',
        'password' => Str::password(40),
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);

    $rawToken = piIssueAndCaptureToken($admin);

    $response = $this->postJson('http://'.piPlatformHost().'/api/platform/v1/admin-invitation-redemptions', [
        'token' => $rawToken,
        'password' => 'Contraseña-Larga-123!',
        'password_confirmation' => 'Contraseña-Larga-123!',
    ]);

    $response->assertNoContent();

    $admin->refresh();
    expect(Hash::check('Contraseña-Larga-123!', $admin->password))->toBeTrue();

    $invitation = PlatformAdminInvitation::query()->where('platform_admin_id', $admin->id)->firstOrFail();
    expect($invitation->accepted_at)->not->toBeNull();

    expect(AdminActionLog::query()->where('action', 'admin.invitacion_canjeada')->where('subject_public_id', $admin->public_id)->exists())->toBeTrue();
    expect(PlatformAdminSession::query()->where('platform_admin_id', $admin->id)->exists())->toBeFalse();

    // A partir de aquí entra por POST /auth/session, como cualquier otro
    // administrador — sin MFA confirmado, sólo alcanza /mfa/* (RN-BO-05).
    $login = $this->postJson('http://'.piPlatformHost().'/api/platform/v1/auth/session', [
        'email' => $admin->email,
        'password' => 'Contraseña-Larga-123!',
    ]);
    $login->assertOk();
    $login->assertJson(['mfa_enrollment_required' => true]);
});

// Issue #173: token inválido, caducado, revocado o ya canjeado — 410
// genérico, sin distinguir el motivo por tiempo de respuesta ni por
// cuerpo (mismo criterio que el precedente de Auth, api.md §3 de
// REQ-AUTH).
test('issue #173: un token que no existe responde 410 y no modifica nada', function (): void {
    piAllowCurrentTestIp();

    $response = $this->postJson('http://'.piPlatformHost().'/api/platform/v1/admin-invitation-redemptions', [
        'token' => bin2hex(random_bytes(32)),
        'password' => 'Contraseña-Larga-123!',
        'password_confirmation' => 'Contraseña-Larga-123!',
    ]);

    $response->assertStatus(410);
});

test('issue #173: un token ya canjeado no puede volver a usarse', function (): void {
    Mail::fake();
    piAllowCurrentTestIp();

    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'x',
        'locale' => 'es-ES',
        'password' => Str::password(40),
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);

    $rawToken = piIssueAndCaptureToken($admin);

    $payload = ['token' => $rawToken, 'password' => 'Contraseña-Larga-123!', 'password_confirmation' => 'Contraseña-Larga-123!'];

    $this->postJson('http://'.piPlatformHost().'/api/platform/v1/admin-invitation-redemptions', $payload)->assertNoContent();
    $this->postJson('http://'.piPlatformHost().'/api/platform/v1/admin-invitation-redemptions', $payload)->assertStatus(410);
});

// Issue #173, INV-010: la política de contraseña se valida siempre en
// servidor — misma regla que RN-AUTH-01 (App\Modules\Auth\Domain\
// PasswordPolicy, consumida por interfaz, INV-007).
test('issue #173: una contraseña que incumple la política responde 422 y no fija nada', function (): void {
    Mail::fake();
    piAllowCurrentTestIp();

    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'x',
        'locale' => 'es-ES',
        'password' => Str::password(40),
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);

    $rawToken = piIssueAndCaptureToken($admin);

    $response = $this->postJson('http://'.piPlatformHost().'/api/platform/v1/admin-invitation-redemptions', [
        'token' => $rawToken,
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ]);

    $response->assertStatus(422);

    $invitation = PlatformAdminInvitation::query()->where('platform_admin_id', $admin->id)->firstOrFail();
    expect($invitation->accepted_at)->toBeNull();
});
