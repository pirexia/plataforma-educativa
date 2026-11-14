<?php

use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaFactor;
use App\Modules\Backoffice\Domain\Models\PlatformAdminRole;
use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\TestPasswordHash;

/*
|--------------------------------------------------------------------------
| Ayudantes compartidos de los tests de REQ-BO (ADR-060 §4.2.1)
|--------------------------------------------------------------------------
|
| Antes vivían dentro de PlatformAdminManagementTest.php y
| TenantLifecycleTest.php: un proceso de Pest que no cargaba ese fichero no
| los tenía (en serie funcionaba por el orden de carga). Se cargan desde
| tests/Pest.php, común a todos los procesos. Los ayudantes que usa un solo
| fichero se quedan en él.
|
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
        'password' => TestPasswordHash::of('contraseña-larga-de-prueba'),
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

/**
 * Reautentica y devuelve un cliente de test listo para una operación
 * sensible (api.md §4). Ver boReauthenticatedSessionCookie() para el
 * detalle de por qué es un round-trip HTTP real y no un `session()->put()`.
 */
function boSensitiveClient(PlatformAdmin $admin, string $secret): mixed
{
    $cookie = boReauthenticatedSessionCookie($admin, $secret);

    return boWithReauthenticatedCookie($cookie);
}
