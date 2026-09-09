<?php

use App\Modules\Backoffice\Domain\Models\AdminActionLog;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaFactor;
use App\Modules\Backoffice\Domain\Models\PlatformAdminRole;
use App\Modules\Backoffice\Domain\Models\PlatformIpAllowlistEntry;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use App\Modules\Backoffice\Http\Resources\AdminActionLogResource;
use App\Modules\Backoffice\Http\Resources\PlatformAdminResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * REQ-BO-007, api.md §3.6/CLAUDE.md §7. CA-BO-070 a CA-BO-074:
 * comprobaciones transversales a todo el módulo — denegación por
 * defecto, `public_id` en vez de clave interna, formato RFC 9457,
 * cuatro idiomas y ausencia de datos personales de los centros.
 */
function ttAllowCurrentTestIp(): void
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
function ttCreateEnrolledAdmin(string $role = 'superadministrador'): array
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
    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('platform_admin_mfa_factors')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_roles')->delete();
    DB::connection('pgsql_platform')->table('platform_admin_invitations')->delete();
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
    DB::connection('pgsql_platform')->table('platform_ip_allowlist')->delete();
});

// CA-BO-070: sin sesión, 401; sin la capacidad requerida, 403. Ninguno
// devuelve datos.
test('CA-BO-070: sin sesión de plataforma, 401 y ningún dato', function (): void {
    ttAllowCurrentTestIp();

    $response = $this->getJson('http://'.config('backoffice.host').'/api/platform/v1/admins');

    $response->assertStatus(401);
    expect($response->json('data'))->toBeNull();
});

test('CA-BO-070: con sesión pero sin la capacidad requerida, 403 y ningún dato', function (): void {
    ttAllowCurrentTestIp();
    [$admin] = ttCreateEnrolledAdmin('comercial');

    $this->actingAs($admin, 'platform');

    $response = $this->getJson('http://'.config('backoffice.host').'/api/platform/v1/admins');

    $response->assertStatus(403);
    expect($response->json('data'))->toBeNull();
});

// CA-BO-071: los recursos expuestos se identifican por public_id ULID,
// nunca por la clave interna bigint.
test('CA-BO-071: PlatformAdminResource y AdminActionLogResource nunca exponen la clave interna id', function (): void {
    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'x',
        'password' => 'hash',
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);

    $adminArray = (new PlatformAdminResource($admin))->toArray(request());

    expect($adminArray)->not->toHaveKey('id');
    expect($adminArray)->toHaveKey('public_id');
    expect(Str::isUlid((string) $admin->public_id))->toBeTrue();

    $log = AdminActionLog::create([
        'public_id' => (string) Str::ulid(),
        'occurred_at' => now(),
        'actor_type' => 'system',
        'subject_type' => 'platform',
        'action' => 'acceso.concedido',
    ]);

    $logArray = (new AdminActionLogResource($log))->toArray(request());

    expect($logArray)->not->toHaveKey('id');
    expect($logArray)->toHaveKey('public_id');

    DB::connection('pgsql_owner')->statement('TRUNCATE admin_action_logs');
    DB::connection('pgsql_platform')->table('platform_admins')->delete();
});

// CA-BO-072: toda respuesta de error sigue RFC 9457, con type en la
// forma urn:pge:error:<slug> y request_id presente.
test('CA-BO-072: una respuesta de error del backoffice sigue RFC 9457 con type y request_id', function (): void {
    ttAllowCurrentTestIp();

    $response = $this->getJson('http://'.config('backoffice.host').'/api/platform/v1/admins');

    $response->assertStatus(401);
    $response->assertJsonStructure(['type', 'title', 'status', 'request_id']);
    expect($response->json('type'))->toStartWith('urn:pge:error:');
    expect($response->json('request_id'))->not->toBeNull();
});

// CA-BO-073: todo mensaje visible existe en los cuatro idiomas
// obligatorios, con la misma forma de claves (INV-009).
test('CA-BO-073: lang/{es,en,de,fr}/bo.php declaran exactamente las mismas claves', function (): void {
    $flatten = function (array $array, string $prefix = ''): array {
        $keys = [];
        $walk = function (array $array, string $prefix) use (&$walk, &$keys): void {
            foreach ($array as $key => $value) {
                $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
                if (is_array($value)) {
                    $walk($value, $path);
                } else {
                    $keys[] = $path;
                    expect($value)->toBeString()->not->toBe('', "{$path}: literal vacío en lang/*/bo.php");
                }
            }
        };
        $walk($array, $prefix);
        sort($keys);

        return $keys;
    };

    $locales = ['es', 'en', 'de', 'fr'];
    $keysByLocale = [];

    foreach ($locales as $locale) {
        $path = base_path("lang/{$locale}/bo.php");
        expect(file_exists($path))->toBeTrue("falta lang/{$locale}/bo.php");
        $keysByLocale[$locale] = $flatten(require $path);
    }

    foreach ($locales as $locale) {
        expect($keysByLocale[$locale])->toBe($keysByLocale['es'], "lang/{$locale}/bo.php: claves distintas de lang/es/bo.php");
    }
});

// CA-BO-074: ninguna respuesta del backoffice implementado en 1.6
// contiene datos personales de alumnos, familias ni personal de los
// centros (RN-BO-33) — el backoffice de 1.6 no expone ninguna entidad
// de tenant salvo el registro de auditoría, que ya está reducido a
// metadatos por el GRANT de columnas (ADR-047).
test('CA-BO-074: los recursos del backoffice solo exponen metadatos de la propia plataforma, nunca datos de personas del centro', function (): void {
    $forbiddenFields = ['document_number', 'birth_date', 'contact_email', 'given_name', 'family_name', 'address', 'phone'];

    $admin = PlatformAdmin::create([
        'email' => Str::random(10).'@example.com',
        'name' => 'x',
        'password' => 'hash',
        'status' => PlatformAdminStatus::Activo,
        'password_changed_at' => now(),
    ]);

    $adminArray = (new PlatformAdminResource($admin))->toArray(request());

    foreach ($forbiddenFields as $field) {
        expect($adminArray)->not->toHaveKey($field);
    }

    DB::connection('pgsql_platform')->table('platform_admins')->delete();
});
