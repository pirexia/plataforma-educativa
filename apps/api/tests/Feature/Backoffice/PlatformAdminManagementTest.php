<?php

use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaFactor;
use App\Modules\Backoffice\Domain\Models\PlatformAdminRole;
use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

// currentTotpCode(), withSessionCookie() y sessionCookieValue() están
// definidas globalmente en tests/Pest.php (REQ-AUTH-003).

/**
 * REQ-BO-007, api.md §2.1, §2.2, §4. CA-BO-002, CA-BO-006, CA-BO-007,
 * CA-BO-012: auditoría del acceso rechazado, ausencia de mecanismo de
 * exención de MFA, reautenticación en operaciones sensibles y
 * restablecimiento de MFA reservado al superadministrador.
 */
function boPlatformHost(): string
{
    return (string) config('backoffice.host');
}

function boAllowCurrentTestIp(): void
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
function boCreateEnrolledAdmin(string $role = 'superadministrador'): array
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

/**
 * Reautentica de verdad (contraseña + TOTP) sobre una petición HTTP real
 * y devuelve la cookie de la sesión resultante, ya marcada como
 * reautenticada. `session()->put(...)` antes de la petición no sirve
 * aquí: la sesión de plataforma la arranca `configure-platform-session`/
 * `start-session` DURANTE la petición (datos.md §2.6), no la que exista
 * de antemano en el proceso de test. No usa `withSessionCookie()`
 * (llamaría a `resetSessionState()`, que olvida los guards de Auth —
 * `tests/Pest.php` — y perdería el `actingAs()` ya fijado): adjunta la
 * cookie a mano, sin tocar el guard.
 */
function boReauthenticatedSessionCookie(PlatformAdmin $admin, string $secret): string
{
    $response = test()->postJson('http://'.boPlatformHost().'/api/platform/v1/auth/reauthenticate', [
        'password' => 'contraseña-larga-de-prueba',
        'code' => currentTotpCode($secret),
    ]);

    $response->assertNoContent();

    return sessionCookieValue($response);
}

function boWithReauthenticatedCookie(string $cookie): mixed
{
    return test()->withCredentials()->withUnencryptedCookie(config('backoffice.session_cookie'), $cookie);
}

afterEach(function (): void {
    // admin_action_logs es de solo-anexión permanente (RN-BO-29):
    // TRUNCATE es el único camino de limpieza en tests, antes de borrar
    // platform_admins por la FK.
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('platform_admin_sessions')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_challenges')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_recovery_codes')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
});

// CA-BO-002: un intento rechazado de acceso deja entrada en
// admin_action_logs con IP, user_agent y request_id.
test('CA-BO-002: un intento de acceso rechazado queda auditado con IP, user_agent y request_id', function (): void {
    boAllowCurrentTestIp();

    $response = $this->postJson('http://'.boPlatformHost().'/api/platform/v1/auth/session', [
        'email' => 'nadie-real@example.com',
        'password' => 'lo-que-sea',
    ], ['User-Agent' => 'Suite de tests CA-BO-002']);

    $response->assertStatus(401);

    $entry = AdminActionLog::query()->where('action', 'acceso.rechazado')->latest('occurred_at')->first();

    expect($entry)->not->toBeNull();
    expect($entry->ip_address)->not->toBeNull();
    expect($entry->user_agent)->toBe('Suite de tests CA-BO-002');
    expect($entry->request_id)->not->toBeNull();
});

// CA-BO-006: no existe ninguna ruta, tabla ni columna que permita eximir
// a un platform_admin de MFA — a diferencia de las excepciones
// temporales de 1.3b (user_mfa_exemptions).
test('CA-BO-006: no existe ningún mecanismo de exención de MFA para platform_admins', function (): void {
    expect(Schema::connection('pgsql_owner')->hasTable('platform_admin_mfa_exemptions'))->toBeFalse();

    foreach (['mfa_exempt', 'mfa_exempt_until', 'mfa_grace_period_ends_at'] as $column) {
        expect(Schema::connection('pgsql_owner')->hasColumn('platform_admins', $column))->toBeFalse();
        expect(Schema::connection('pgsql_owner')->hasColumn('platform_admin_mfa_factors', $column))->toBeFalse();
    }

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/platform/')) {
            continue;
        }

        expect($route->uri())->not->toContain('exempt', "{$route->uri()}: sugiere una vía de exención de MFA, prohibido por RN-BO-05");
    }
});

// CA-BO-007: una operación sensible sin reautenticación viva responde
// 403 con urn:pge:error:reauthentication-required.
test('CA-BO-007: operación sensible sin reautenticación viva responde reauthentication-required', function (): void {
    boAllowCurrentTestIp();
    [$admin] = boCreateEnrolledAdmin();

    $this->actingAs($admin, 'platform');

    $response = $this->postJson('http://'.boPlatformHost().'/api/platform/v1/admins', [
        'email' => 'nuevo@example.com',
        'name' => 'Nuevo Admin',
        'locale' => 'es-ES',
        'role' => 'soporte',
    ]);

    $response->assertStatus(403);
    $response->assertJson(['type' => 'urn:pge:error:reauthentication-required']);
});

test('CA-BO-007: tras reautenticar dentro de la ventana, la operación sensible se completa', function (): void {
    boAllowCurrentTestIp();
    [$admin, $secret] = boCreateEnrolledAdmin();

    $this->actingAs($admin, 'platform');
    $cookie = boReauthenticatedSessionCookie($admin, $secret);

    $response = boWithReauthenticatedCookie($cookie)->postJson('http://'.boPlatformHost().'/api/platform/v1/admins', [
        'email' => 'nuevo2@example.com',
        'name' => 'Nuevo Admin 2',
        'locale' => 'es-ES',
        'role' => 'soporte',
    ]);

    $response->assertStatus(201);
});

// CA-BO-012: solo un superadministrador restablece el MFA de otro
// platform_admin. Nunca autoservicio, nunca por correo.
test('CA-BO-012: el rol operaciones no puede restablecer el MFA de otro administrador', function (): void {
    boAllowCurrentTestIp();
    [$operaciones] = boCreateEnrolledAdmin('operaciones');
    [$victim] = boCreateEnrolledAdmin('soporte');

    $this->actingAs($operaciones, 'platform');

    // Sin reautenticar: la capacidad se comprueba antes que la
    // reautenticación en esta ruta (routes.php), así que 403 llega por
    // falta de `admin.mfa.restablecer`, no por falta de reautenticación
    // — el propio 403 (y no un 401/403 de otro tipo) ya lo demuestra.
    $response = $this->deleteJson('http://'.boPlatformHost()."/api/platform/v1/admins/{$victim->public_id}/mfa");

    $response->assertStatus(403);
});

test('CA-BO-012: un superadministrador no puede restablecerse el MFA a sí mismo (RN-BO-10)', function (): void {
    boAllowCurrentTestIp();
    [$admin, $secret] = boCreateEnrolledAdmin();

    $this->actingAs($admin, 'platform');
    $cookie = boReauthenticatedSessionCookie($admin, $secret);

    $response = boWithReauthenticatedCookie($cookie)->deleteJson('http://'.boPlatformHost()."/api/platform/v1/admins/{$admin->public_id}/mfa");

    $response->assertStatus(409);
});

test('CA-BO-012: un superadministrador restablece el MFA de otro administrador, con auditoría', function (): void {
    boAllowCurrentTestIp();
    [$admin, $secret] = boCreateEnrolledAdmin();
    [$victim] = boCreateEnrolledAdmin('soporte');

    $this->actingAs($admin, 'platform');
    $cookie = boReauthenticatedSessionCookie($admin, $secret);

    $response = boWithReauthenticatedCookie($cookie)->deleteJson('http://'.boPlatformHost()."/api/platform/v1/admins/{$victim->public_id}/mfa");

    $response->assertNoContent();
    expect(PlatformAdminMfaFactor::query()->where('platform_admin_id', $victim->id)->exists())->toBeFalse();
    expect(AdminActionLog::query()->where('action', 'admin.mfa_restablecido')->where('subject_public_id', $victim->public_id)->exists())->toBeTrue();
});
