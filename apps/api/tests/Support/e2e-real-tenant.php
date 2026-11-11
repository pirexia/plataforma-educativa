<?php

/**
 * Prepara (y retira) un centro de pruebas para los tests e2e contra la API
 * real (`apps/web/e2e/core-roles.spec.ts`, CA-PERM-133, `E2E_REAL_API=1`).
 * No es un test ni código de aplicación: es soporte de pruebas, como
 * `tests/Concurrency/worker.php`.
 *
 * Uso (dentro del contenedor de la API, que es quien tiene la base):
 *   php tests/Support/e2e-real-tenant.php setup  [slug] [door]   # imprime una línea JSON
 *   php tests/Support/e2e-real-tenant.php teardown [slug]
 *
 * Con `door` (1.14b-b, `REQ-PRL-004`, `apps/web/scripts/e2e-real-prl.sh`) el centro contrata además `fam_unit` y `prl` y deja dos
 * usuarios sintéticos activos con los roles predefinidos `conserjeria_pas` y `secretaria` (sin MFA) y una contraseña común.
 *
 * Con `alum` (1.15-a, `REQ-ALUM`, `apps/web/scripts/e2e-real-alum.sh`) contrata `alum` y `fam_unit` y deja un tutor sintético con el rol `tutor_legal`.
 *
 * Con `showcase` (landing, ADR-074 §4.4, `apps/web/scripts/landing-shots.sh`) crea el centro de escaparate de la landing: nombre visible
 * ficticio y legible (no la marca técnica de abajo), slug `demo` (el único origen que la CORS de desarrollo permite, `CORS_ALLOWED_ORIGINS`), `acad`, `fam_unit`, `prl` y `alum` contratados, el
 * administrador y tres docentes activos con nombres fijos e inventados y correos `@example.com`. El resto de datos los crea el *spec* por la API.
 * Su marca de seguridad NO es solo el nombre visible: `teardown` solo retira un centro de escaparate si coinciden las TRES cosas (slug `demo`,
 * nombre fijo de este fichero y el administrador de escaparate dentro de ese centro); un centro real con ese slug o con ese nombre no se toca.
 *
 * El slug por defecto es `demo`, el centro para el que está configurado el
 * entorno de desarrollo (`VITE_API_URL=http://demo.plataforma.test:8000`).
 *
 * Seguridad: solo datos sintéticos (`@example.com`, ADR-030, REQ-SEED-005).
 * Escribe y borra de verdad, así que exige a la vez: ejecución por CLI,
 * `E2E_ALLOW_DESTRUCTIVE=1` (lo pasa `apps/web/scripts/e2e-real-api.sh`),
 * entorno `local`/`testing` y base `plataforma`/`plataforma_test`. `teardown`
 * solo borra la fila de `tenants` de un centro que lleve la marca de abajo;
 * las filas del centro NO tienen cascada (31 tablas con `tenant_id`, sin
 * `ON DELETE CASCADE`) y el rol de plataforma no puede borrar de las tablas
 * de solo anexar: las purga `e2e-real-api.sh` con el superusuario del
 * contenedor de PostgreSQL de desarrollo, a partir del `tenant_id` que este
 * script devuelve. El `tenant_id` y el slug se validan antes de usarse.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

use App\Models\ModuleSubscription;
use App\Models\Person;
use App\Models\Role;
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
const E2E_CONSERJE_EMAIL = 'e2e-conserje@example.com';
const E2E_SECRETARIA_EMAIL = 'e2e-secretaria@example.com';
const E2E_TUTOR_EMAIL = 'e2e-tutor@example.com';
const E2E_DOCENTE2_EMAIL = 'e2e-docente2@example.com';
const E2E_DIRECCION_EMAIL = 'e2e-direccion@example.com';

// Centro de escaparate de la landing (ADR-074 §4.4). Nombre visible ficticio; marca de seguridad = slug + nombre + administrador.
const SHOWCASE_SLUG = 'demo';
const SHOWCASE_NAME = 'Colegio Ejemplo Los Álamos (ficticio)';
const SHOWCASE_ADMIN_EMAIL = 'elena.marin@example.com';
/** Docentes de escaparate: correo, nombre, apellido 1, apellido 2 (inventados, `REQ-SEED-005`). */
const SHOWCASE_TEACHERS = [
    ['carmen.ibarra@example.com', 'Carmen', 'Ibarra', 'Solano'],
    ['javier.lorca@example.com', 'Javier', 'Lorca', 'Brea'],
    ['lucia.navarro@example.com', 'Lucía', 'Navarro', 'Peña'],
];

$mode = $argv[1] ?? '';
$slug = $argv[2] ?? 'demo';
$door = ($argv[3] ?? '') === 'door';
$alum = ($argv[3] ?? '') === 'alum';
$showcase = ($argv[3] ?? '') === 'showcase';

if (! in_array($mode, ['setup', 'teardown'], true) || preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $slug) !== 1) {
    fwrite(STDERR, "Uso: php tests/Support/e2e-real-tenant.php setup|teardown [slug] (slug: minúsculas, dígitos y guiones, hasta 40)\n");
    exit(2);
}

if ($showcase && $slug !== SHOWCASE_SLUG) {
    fwrite(STDERR, 'e2e-real-tenant aborta: el modo showcase solo crea el slug «'.SHOWCASE_SLUG."».\n");
    exit(2);
}

if (getenv('E2E_ALLOW_DESTRUCTIVE') !== '1') {
    fwrite(STDERR, "e2e-real-tenant aborta: falta E2E_ALLOW_DESTRUCTIVE=1 (lo define apps/web/scripts/e2e-real-api.sh).\n");
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

    // Centro de escaparate: marca = slug + nombre fijo + su administrador dentro de ese mismo centro (el nombre visible solo no basta).
    $isShowcase = $slug === SHOWCASE_SLUG
        && $existing->name === SHOWCASE_NAME
        && $platform->table('users')->where('tenant_id', $existing->id)->where('email', SHOWCASE_ADMIN_EMAIL)->exists();

    if ($existing->name !== E2E_TENANT_MARK && ! $isShowcase) {
        fwrite(STDERR, "teardown aborta: el centro «{$slug}» no lo creó este script, no se toca.\n");
        exit(2);
    }

    $platform->table('tenants')->where('slug', $slug)->delete();
    Cache::forget("tenant-resolution:{$slug}");
    echo json_encode(['outcome' => 'removed', 'slug' => $slug, 'tenant_id' => (int) $existing->id]), "\n";
    exit(0);
}

if ($existing !== null) {
    fwrite(STDERR, "setup aborta: ya existe un centro «{$slug}»; no se reutiliza ni se toca. Ejecuta teardown si lo creó este script.\n");
    exit(2);
}

Artisan::call('platform:sync-registry');

$adminEmail = $showcase ? SHOWCASE_ADMIN_EMAIL : E2E_ADMIN_EMAIL;

$tenant = Tenant::query()->create(['slug' => $slug, 'name' => $showcase ? SHOWCASE_NAME : E2E_TENANT_MARK, 'status' => TenantStatus::Activo]);
Cache::forget("tenant-resolution:{$slug}");

app(TenantProvisioner::class)->provision(
    $tenant,
    TenantInitialSettings::defaults(),
    $showcase ? new TenantAdministrator($adminEmail, 'Elena', 'Marín') : new TenantAdministrator($adminEmail, 'Ana', 'Pérez'),
);

$password = 'E2e-'.Str::random(20);
$staffPassword = 'E2e-'.Str::random(20);

$students = [];
$tutorUser = null;
$alumUsers = [];

app(TenantContext::class)->runFor($tenant->id, function () use ($password, $door, $alum, $showcase, $adminEmail, $staffPassword, &$students, &$tutorUser, &$alumUsers): void {
    // El administrador aprovisionado queda invitado (sin contraseña) y su rol
    // obliga a MFA: se activa con contraseña conocida y una excepción de MFA
    // viva (REQ-AUTH-003) para que el login real no pida inscribir un factor.
    $admin = User::query()->where('email', $adminEmail)->firstOrFail();
    $admin->forceFill(['password' => $password, 'status' => UserStatus::Activo, 'email_verified_at' => now()])->save();

    UserMfaExemption::create([
        'user_id' => $admin->id,
        'reason' => 'Test e2e CA-PERM-133 con datos sintéticos.',
        'expires_at' => now()->addDays(7),
        'granted_by' => $admin->id,
    ]);

    // REQ-ACAD (1.11-b), CA-ACAD-053: `acad` es un módulo no esencial; el centro de pruebas lo
    // contrata (la suscripción solo la escribe la plataforma). El administrador ya trae sus
    // permisos de `estructura_academica` de `tenant:provision-defaults`.
    ModuleSubscription::on('pgsql_platform')->create([
        'module_code' => 'acad', 'enabled' => true, 'enabled_at' => now(), 'reason' => 'Test e2e CA-ACAD-053 con datos sintéticos.',
    ]);
    Cache::forget('modules:acad:enabled');

    // Escaparate de la landing (ADR-074 §4.4): `acad` ya está contratado más arriba; aquí `fam_unit`, `prl` y `alum`, y tres docentes activos de datos fijos. Las
    // altas de usuario por API quedan «pendientes» y la asignación docente exige un docente activo, igual que en el modo normal. Se
    // fijan TODOS los campos visibles de la persona (la fábrica los inventa al azar) y la contraseña es aleatoria y no se devuelve.
    if ($showcase) {
        $admin->person?->forceFill(['locale' => 'en'])->save();

        foreach (['fam_unit', 'prl', 'alum'] as $module) {
            ModuleSubscription::on('pgsql_platform')->create([
                'module_code' => $module, 'enabled' => true, 'enabled_at' => now(), 'reason' => 'Escaparate de la landing con datos sintéticos.',
            ]);
            Cache::forget("modules:{$module}:enabled");
        }

        foreach (SHOWCASE_TEACHERS as [$email, $given, $family1, $family2]) {
            User::factory()->for(Person::factory()->create([
                'given_name' => $given, 'family_name_1' => $family1, 'family_name_2' => $family2, 'contact_email' => $email, 'locale' => 'en',
            ]))->create(['email' => $email, 'password' => 'Esc-'.Str::random(24), 'status' => UserStatus::Activo, 'email_verified_at' => now()]);
        }

        return;
    }

    // Usuario al que el test asigna el rol «Revisión propia».
    User::factory()->for(Person::factory()->create(['given_name' => 'Daniel', 'family_name_1' => 'Docente']))->create([
        'email' => E2E_TARGET_EMAIL,
        'status' => UserStatus::Activo,
    ]);

    // REQ-ACAD (1.12-c), CA-ACAD-135: segundo profesor sintético para el e2e de horarios.
    User::factory()->for(Person::factory()->create(['given_name' => 'Marta', 'family_name_1' => 'Docente']))->create([
        'email' => E2E_DOCENTE2_EMAIL,
        'status' => UserStatus::Activo,
    ]);

    // REQ-ALUM (1.15-a): `alum` y `fam_unit` contratados y un tutor sintético activo con el rol predefinido `tutor_legal`
    // (`alumno.leer` con ámbito `unidad_familiar`) y contraseña conocida; el alumnado y los vínculos los crea el test por la API.
    if ($alum) {
        foreach (['alum', 'fam_unit'] as $module) {
            ModuleSubscription::on('pgsql_platform')->create([
                'module_code' => $module, 'enabled' => true, 'enabled_at' => now(), 'reason' => 'Test e2e de alumnado con datos sintéticos.',
            ]);
            Cache::forget("modules:{$module}:enabled");
        }

        $tutorUser = User::factory()->for(Person::factory()->create(['given_name' => 'Tomás', 'family_name_1' => 'Tutor', 'family_name_2' => 'Ficticio']))->create([
            'email' => E2E_TUTOR_EMAIL,
            'password' => $staffPassword,
            'status' => UserStatus::Activo,
            'email_verified_at' => now(),
        ]);
        $tutorUser->roles()->attach(Role::where('code', 'tutor_legal')->firstOrFail()->id);

        // REQ-ALUM (1.15-d T3, CA-ALUM-072): personal con los roles predefinidos y contraseña conocida para el e2e de navegador. Los dos
        // docentes ya existen sin rol: ambos reciben `docente` (con `alumno.leer`/`matricula.leer` de ámbito `grupo`); el test asigna a
        // uno solo a un grupo. `direccion` aporta un segundo usuario con `matricula.aprobar`, distinto del solicitante.
        foreach ([[E2E_TARGET_EMAIL, 'docente'], [E2E_DOCENTE2_EMAIL, 'docente']] as [$email, $roleCode]) {
            $teacher = User::query()->where('email', $email)->firstOrFail();
            $teacher->forceFill(['password' => $staffPassword, 'email_verified_at' => now()])->save();
            $teacher->roles()->attach(Role::where('code', $roleCode)->firstOrFail()->id);
            $alumUsers[$email] = $teacher->public_id;
        }

        foreach ([[E2E_SECRETARIA_EMAIL, 'Sara', 'Secretaria', 'secretaria'], [E2E_DIRECCION_EMAIL, 'Diana', 'Direccion', 'direccion']] as [$email, $given, $family, $roleCode]) {
            $staff = User::factory()->for(Person::factory()->create(['given_name' => $given, 'family_name_1' => $family]))->create([
                'email' => $email,
                'password' => $staffPassword,
                'status' => UserStatus::Activo,
                'email_verified_at' => now(),
            ]);
            $staff->roles()->attach(Role::where('code', $roleCode)->firstOrFail()->id);
        }

        return;
    }

    if (! $door) {
        return;
    }

    // REQ-PRL (1.14b-b): la puerta necesita `fam_unit` y `prl` contratados y personal con los roles predefinidos.
    foreach (['fam_unit', 'prl'] as $module) {
        ModuleSubscription::on('pgsql_platform')->create([
            'module_code' => $module, 'enabled' => true, 'enabled_at' => now(), 'reason' => 'Test e2e de la puerta con datos sintéticos.',
        ]);
        Cache::forget("modules:{$module}:enabled");
    }

    foreach ([[E2E_CONSERJE_EMAIL, 'Carmen', 'Conserje', 'conserjeria_pas'], [E2E_SECRETARIA_EMAIL, 'Sara', 'Secretaria', 'secretaria']] as [$email, $given, $family, $roleCode]) {
        $user = User::factory()->for(Person::factory()->create(['given_name' => $given, 'family_name_1' => $family]))->create([
            'email' => $email,
            'password' => $staffPassword,
            'status' => UserStatus::Activo,
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail()->id);
    }

    // No hay alta de alumno por API hasta `1.15` (`CLAUDE.md §11`): dos alumnos sintéticos, de nombre explícitamente ficticio
    // (`REQ-SEED-005`), que el test añade a una unidad familiar por la API.
    foreach ([['Lucía', 'Ficticia'], ['Mateo', 'Ficticio']] as [$given, $family]) {
        $students[] = Person::factory()->create(['given_name' => $given, 'family_name_1' => $family, 'family_name_2' => 'Alumno'])->public_id;
    }
});

echo json_encode([
    'outcome' => 'created',
    'slug' => $slug,
    'tenant_id' => $tenant->id,
    'admin_email' => $adminEmail,
    'admin_password' => $password,
    ...($showcase ? ['teachers' => array_map(static fn (array $t): string => $t[0], SHOWCASE_TEACHERS)] : ['target_email' => E2E_TARGET_EMAIL, 'target_name' => 'Daniel Docente']),
    ...($alum ? ['staff_password' => $staffPassword, 'tutor_email' => E2E_TUTOR_EMAIL, 'tutor_user' => $tutorUser?->public_id,
        'secretaria_email' => E2E_SECRETARIA_EMAIL, 'direccion_email' => E2E_DIRECCION_EMAIL,
        'docente_email' => E2E_TARGET_EMAIL, 'docente_user' => $alumUsers[E2E_TARGET_EMAIL] ?? null,
        'docente2_email' => E2E_DOCENTE2_EMAIL, 'docente2_user' => $alumUsers[E2E_DOCENTE2_EMAIL] ?? null] : []),
    ...($door ? ['staff_password' => $staffPassword, 'conserje_email' => E2E_CONSERJE_EMAIL, 'secretaria_email' => E2E_SECRETARIA_EMAIL, 'student_1' => $students[0], 'student_2' => $students[1]] : []),
]), "\n";
