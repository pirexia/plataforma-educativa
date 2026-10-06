<?php

/**
 * Prepara (y retira) un centro de pruebas para los tests e2e contra la API
 * real (`apps/web/e2e/core-roles.spec.ts`, CA-PERM-133, `E2E_REAL_API=1`).
 * No es un test ni código de aplicación: es soporte de pruebas, como
 * `tests/Concurrency/worker.php`.
 *
 * Uso (dentro del contenedor de la API, que es quien tiene la base):
 *   php tests/Support/e2e-real-tenant.php setup  [slug]   # imprime una línea JSON
 *   php tests/Support/e2e-real-tenant.php teardown [slug]
 *
 * El slug por defecto es `demo`, el centro para el que está configurado el
 * entorno de desarrollo (`VITE_API_URL=http://demo.plataforma.test:8000`).
 *
 * Seguridad: solo datos sintéticos (`@example.com`, ADR-030, REQ-SEED-005) y
 * solo contra una base de desarrollo o de test. `teardown` borra el centro
 * y su cascada, así que se niega a tocar un centro que no haya creado este
 * script (el nombre lleva la marca de abajo).
 */

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\UserMfaExemption;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantInitialSettings;
use App\Modules\Core\Domain\TenantProvisioner;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const E2E_TENANT_MARK = 'Centro ficticio e2e (CA-PERM-133)';
const E2E_ADMIN_EMAIL = 'e2e-admin@example.com';
const E2E_TARGET_EMAIL = 'e2e-docente@example.com';

$mode = $argv[1] ?? '';
$slug = $argv[2] ?? 'demo';

if (! in_array($mode, ['setup', 'teardown'], true)) {
    fwrite(STDERR, "Uso: php tests/Support/e2e-real-tenant.php setup|teardown [slug]\n");
    exit(2);
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Escribe y borra de verdad: solo contra una base de desarrollo o de test.
$database = DB::connection()->selectOne('select current_database() as name')->name;

if (! in_array($app->environment(), ['local', 'testing'], true) || ! in_array($database, ['plataforma', 'plataforma_test'], true)) {
    fwrite(STDERR, "e2e-real-tenant aborta: entorno «{$app->environment()}» y base «{$database}», se esperaba local/testing y plataforma/plataforma_test.\n");
    exit(2);
}

$platform = DB::connection('pgsql_platform');
$existing = $platform->table('tenants')->where('slug', $slug)->first();

if ($mode === 'teardown') {
    if ($existing === null) {
        echo json_encode(['outcome' => 'absent', 'slug' => $slug]), "\n";
        exit(0);
    }

    if ($existing->name !== E2E_TENANT_MARK) {
        fwrite(STDERR, "teardown aborta: el centro «{$slug}» no lo creó este script, no se toca.\n");
        exit(2);
    }

    $platform->table('tenants')->where('slug', $slug)->delete();
    Cache::forget("tenant-resolution:{$slug}");
    echo json_encode(['outcome' => 'removed', 'slug' => $slug]), "\n";
    exit(0);
}

if ($existing !== null) {
    fwrite(STDERR, "setup aborta: ya existe un centro «{$slug}»; no se reutiliza ni se toca. Ejecuta teardown si lo creó este script.\n");
    exit(2);
}

Artisan::call('platform:sync-registry');

$tenant = Tenant::query()->create(['slug' => $slug, 'name' => E2E_TENANT_MARK, 'status' => TenantStatus::Activo]);
Cache::forget("tenant-resolution:{$slug}");

app(TenantProvisioner::class)->provision(
    $tenant,
    TenantInitialSettings::defaults(),
    new TenantAdministrator(E2E_ADMIN_EMAIL, 'Ana', 'Pérez'),
);

$password = 'E2e-'.Str::random(20);

app(TenantContext::class)->runFor($tenant->id, function () use ($password): void {
    // El administrador aprovisionado queda invitado (sin contraseña) y su rol
    // obliga a MFA: se activa con contraseña conocida y una excepción de MFA
    // viva (REQ-AUTH-003) para que el login real no pida inscribir un factor.
    $admin = User::query()->where('email', E2E_ADMIN_EMAIL)->firstOrFail();
    $admin->forceFill(['password' => $password, 'status' => UserStatus::Activo, 'email_verified_at' => now()])->save();

    UserMfaExemption::create([
        'user_id' => $admin->id,
        'reason' => 'Test e2e CA-PERM-133 con datos sintéticos.',
        'expires_at' => now()->addDays(7),
        'granted_by' => $admin->id,
    ]);

    // Usuario al que el test asigna el rol «Revisión propia».
    User::factory()->for(Person::factory()->create(['given_name' => 'Daniel', 'family_name_1' => 'Docente']))->create([
        'email' => E2E_TARGET_EMAIL,
        'status' => UserStatus::Activo,
    ]);
});

echo json_encode([
    'outcome' => 'created',
    'slug' => $slug,
    'admin_email' => E2E_ADMIN_EMAIL,
    'admin_password' => $password,
    'target_email' => E2E_TARGET_EMAIL,
    'target_name' => 'Daniel Docente',
]), "\n";
