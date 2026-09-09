<?php

use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaFactor;
use App\Modules\Backoffice\Domain\Models\PlatformAdminRole;
use App\Modules\Backoffice\Domain\Models\PlatformAdminSession;
use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

// currentTotpCode() está definida globalmente en tests/Pest.php (REQ-AUTH-003).

/**
 * REQ-BO-007, api.md §1, §2.1. Extremo a extremo, sobre el host real de
 * plataforma configurado en `.env.testing`/`phpunit.xml`
 * (`config('backoffice.host')`).
 */
function platformHost(): string
{
    return (string) config('backoffice.host');
}

function allowCurrentTestIp(): PlatformIpAllowlistEntry
{
    return PlatformIpAllowlistEntry::create([
        'cidr' => '127.0.0.1/32',
        'description' => 'Suite de tests',
        'enabled' => true,
    ]);
}

function createEnrolledPlatformAdmin(string $role = 'superadministrador'): array
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

afterEach(function (): void {
    // admin_action_logs es de solo-anexión permanente (RN-BO-29, ADR-047
    // §4.3): FORCE ROW LEVEL SECURITY sin política de escritura bloquea
    // hasta a pgsql_owner con DML fila a fila — TRUNCATE es el único
    // camino de limpieza en tests, y va antes de borrar platform_admins
    // por la FK.
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('platform_admin_sessions')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
});

// CA-BO-016: host distinto de BACKOFFICE_HOST -> 404, antes de sesión y credenciales.
test('CA-BO-016: un host que no es el de plataforma responde 404', function (): void {
    $response = $this->get('http://otro-host.test/api/platform/v1/csrf-cookie');

    $response->assertStatus(404);
});

// CA-BO-003/CA-BO-004: sin la IP en la lista blanca (vacía o no
// coincidente), 403 antes de comprobar credenciales.
test('CA-BO-003/CA-BO-004: lista blanca vacía deniega cualquier acceso', function (): void {
    $response = $this->postJson('http://'.platformHost().'/api/platform/v1/auth/session', [
        'email' => 'nadie@example.com',
        'password' => 'lo-que-sea',
    ]);

    $response->assertStatus(403);
    $response->assertJson(['type' => 'urn:pge:error:ip-not-allowed']);

    $entry = AdminActionLog::query()->where('action', 'acceso.rechazado_por_ip')->first();
    expect($entry)->not->toBeNull();
});

// CA-BO-001: un usuario de tenant nunca alcanza el backoffice — sin
// endpoint que acepte su cookie de tenant.
test('CA-BO-001: la ruta de /me de plataforma no acepta una petición sin sesión de plataforma', function (): void {
    allowCurrentTestIp();

    $response = $this->getJson('http://'.platformHost().'/api/platform/v1/me');

    $response->assertStatus(401);
});

test('login completo: credenciales, desafío MFA y sesión, con auditoría', function (): void {
    allowCurrentTestIp();
    [$admin, $secret] = createEnrolledPlatformAdmin();

    $step1 = $this->postJson('http://'.platformHost().'/api/platform/v1/auth/session', [
        'email' => $admin->email,
        'password' => 'contraseña-larga-de-prueba',
    ]);

    $step1->assertOk();
    $step1->assertJson(['mfa_required' => true]);

    $code = currentTotpCode($secret);

    // El cliente de test no reenvía la cookie de sesión sola entre
    // peticiones (tests/Pest.php `openMfaChallengeFor()`/
    // `loginWithTotpFor()`): sin reenviarla, el paso 2 llegaría con una
    // sesión distinta y el desafío no se encontraría.
    $step2 = withSessionCookie(sessionCookieValue($step1))
        ->postJson('http://'.platformHost().'/api/platform/v1/auth/session/mfa', [
            'code' => $code,
        ]);

    $step2->assertOk();

    $me = withSessionCookie(sessionCookieValue($step2))
        ->getJson('http://'.platformHost().'/api/platform/v1/me');
    $me->assertOk();
    $me->assertJsonPath('email', $admin->email);

    expect(PlatformAdminSession::query()->where('platform_admin_id', $admin->id)->whereNull('ended_at')->exists())->toBeTrue();
    expect(AdminActionLog::query()->where('action', 'acceso.concedido')->exists())->toBeTrue();
});

// CA-BO-005/CA-BO-006: sin factor confirmado, solo /mfa/*.
test('CA-BO-005: sin segundo factor confirmado, solo se alcanzan las rutas de alta de MFA', function (): void {
    allowCurrentTestIp();

    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'Sin MFA',
        'password' => Hash::make('contraseña-larga-de-prueba'),
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);
    PlatformAdminRole::create(['platform_admin_id' => $admin->id, 'role' => PlatformRole::Superadministrador]);

    $login = $this->postJson('http://'.platformHost().'/api/platform/v1/auth/session', [
        'email' => $admin->email,
        'password' => 'contraseña-larga-de-prueba',
    ]);
    $login->assertOk();
    $login->assertJson(['mfa_enrollment_required' => true]);

    // Sin reenviar la cookie, la segunda petición llegaría sin sesión
    // (401 por RequirePlatformCapability) y no probaría nada sobre
    // RequirePlatformMfa, que es lo que este CA comprueba (403).
    $ipAllowlist = withSessionCookie(sessionCookieValue($login))
        ->getJson('http://'.platformHost().'/api/platform/v1/ip-allowlist');
    $ipAllowlist->assertStatus(403);
});

// CA-BO-010: el único superadministrador no puede suspenderse a sí mismo.
test('CA-BO-010: el actor no puede cambiar su propio estado (RN-BO-10)', function (): void {
    allowCurrentTestIp();
    [$admin] = createEnrolledPlatformAdmin();

    $this->actingAs($admin, 'platform');

    $response = $this->postJson('http://'.platformHost()."/api/platform/v1/admins/{$admin->public_id}/status", [
        'status' => 'suspendido',
        'reason' => 'prueba',
    ]);

    $response->assertStatus(409);
});

// CA-BO-008: soporte no puede escribir.
test('CA-BO-008: el rol soporte no puede gestionar la lista blanca de IP', function (): void {
    allowCurrentTestIp();
    [$admin] = createEnrolledPlatformAdmin('soporte');

    $this->actingAs($admin, 'platform');

    $response = $this->postJson('http://'.platformHost().'/api/platform/v1/ip-allowlist', [
        'cidr' => '10.0.0.0/24',
        'description' => 'prueba',
    ]);

    $response->assertStatus(403);
});
