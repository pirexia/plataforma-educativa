<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

/**
 * Concurrencia REAL de REQ-FAM-UNIT (1.14-a): `CA-FAM-UNIT-022` (RN-FAM-UNIT-31, índice único parcial del contacto de
 * emergencia), `CA-FAM-UNIT-002` (RN-FAM-UNIT-01, código), `CA-FAM-UNIT-004` (RN-FAM-UNIT-11, una faceta de tutor) y
 * `RN-FAM-UNIT-30` (un vínculo vivo por par). Dos procesos PHP distintos, cada uno con su propia conexión y su propia
 * transacción, confirmados de verdad (`ConcurrentTestCase`, sin transacción envolvente). Mismo método que
 * `AcadConcurrencyTest`: un bloqueo de tabla retenido por el test hace que ambos hijos pasen la comprobación previa
 * (un `SELECT`) y se queden esperando para insertar, de modo que la carrera sobre el índice único es determinista.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function famPdo(): PDO
{
    $cfg = config('database.connections.pgsql_owner');

    return new PDO(
        "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}",
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/** @return array{process: resource, output: string} */
function famSpawn(int $tenantId, string $operation, string ...$args): array
{
    $output = tempnam(sys_get_temp_dir(), 'famunit-');
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/famunit-worker.php', (string) $tenantId, $operation, ...$args],
        [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
        $pipes,
    );
    fclose($pipes[0]);

    return ['process' => $process, 'output' => $output];
}

/**
 * Espera (acotada a 60 s) a que haya exactamente `$count` peticiones de bloqueo sin conceder del tipo `$type`.
 *
 * @param  list<array{process: resource, output: string}>  $children
 */
function famWaitBlocked(int $count, array $children, string $type = 'relation'): void
{
    $pdo = famPdo();
    $deadline = microtime(true) + 60;
    $statement = $pdo->prepare('select count(*) from pg_locks where not granted and locktype = ?');

    while (true) {
        $statement->execute([$type]);

        if ((int) $statement->fetchColumn() === $count) {
            return;
        }

        foreach ($children as $i => $child) {
            if (! proc_get_status($child['process'])['running']) {
                throw new RuntimeException("El proceso hijo {$i} terminó antes de bloquearse: ".file_get_contents($child['output']));
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException("No hay {$count} sesiones bloqueadas tras 60 s.");
        }

        usleep(20_000);
    }
}

/**
 * @param  array{process: resource, output: string}  $child
 * @return array<string, mixed>
 */
function famCollect(array $child): array
{
    $deadline = microtime(true) + 60;

    while (proc_get_status($child['process'])['running']) {
        if (microtime(true) > $deadline) {
            proc_terminate($child['process'], 9);
            throw new RuntimeException('El proceso hijo no terminó en 60 s.');
        }

        usleep(20_000);
    }

    proc_close($child['process']);

    $raw = trim((string) file_get_contents($child['output']));
    @unlink($child['output']);
    $lines = explode("\n", $raw);
    $decoded = json_decode((string) end($lines), true);

    expect($decoded)->toBeArray("Salida inesperada del proceso hijo: {$raw}");

    return $decoded;
}

/**
 * Lanza dos hijos con el mismo bloqueo de tabla retenido, espera a que ambos estén detenidos en el INSERT y lo suelta.
 *
 * @param  array{0: string, 1: list<string>}  $first
 * @param  array{0: string, 1: list<string>}  $second
 * @return list<array<string, mixed>>
 */
function famRace(object $tenant, string $table, array $first, array $second): array
{
    $holder = famPdo();
    $holder->beginTransaction();
    $holder->exec("lock table {$table} in share row exclusive mode");

    $a = famSpawn($tenant->id, $first[0], ...$first[1]);
    $b = famSpawn($tenant->id, $second[0], ...$second[1]);

    try {
        famWaitBlocked(2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    return [famCollect($a), famCollect($b)];
}

test('CA-FAM-UNIT-022 RN-FAM-UNIT-31: dos vínculos simultáneos marcados contacto de emergencia del mismo alumno: exactamente uno 201 y el otro 422 emergency_contact_taken, nunca un 500', function (): void {
    [$tenant, $admin] = Fam::setup('fam-conc-022');
    $unit = Fam::unitId($tenant, $admin);
    [, $guardianA] = Fam::familyUser($tenant, $admin, 'tuta', $unit);
    [, $guardianB] = Fam::familyUser($tenant, $admin, 'tutb', $unit);
    $student = Fam::person($tenant);

    $results = famRace($tenant, 'guardian_student_links', ['create-link', [$guardianA, $student->public_id, '1']], ['create-link', [$guardianB, $student->public_id, '1']]);

    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('fam_unit.link.emergency_contact_taken');

    $count = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('guardian_student_links')->where('is_emergency_contact', true)->whereNull('deleted_at')->count());

    expect($count)->toBe(1);
});

test('RN-FAM-UNIT-30: dos vínculos simultáneos del mismo par (tutor, alumno): exactamente uno 201 y el otro 422 already_exists, nunca un 500', function (): void {
    [$tenant, $admin] = Fam::setup('fam-conc-030');
    $unit = Fam::unitId($tenant, $admin);
    [, $guardian] = Fam::familyUser($tenant, $admin, 'tut', $unit);
    $student = Fam::person($tenant);

    $results = famRace($tenant, 'guardian_student_links', ['create-link', [$guardian, $student->public_id, '0']], ['create-link', [$guardian, $student->public_id, '0']]);

    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('fam_unit.link.already_exists');

    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('guardian_student_links')->whereNull('deleted_at')->count()))->toBe(1);
});

test('CA-FAM-UNIT-002 RN-FAM-UNIT-01: dos altas simultáneas de una unidad con el mismo código: exactamente una 201 y la otra 422 code_taken, nunca un 500', function (): void {
    [$tenant] = Fam::setup('fam-conc-002');

    $results = famRace($tenant, 'family_units', ['create-unit', ['CARRERA']], ['create-unit', ['CARRERA']]);

    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('fam_unit.family_unit.code_taken');

    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('family_units')->where('code', 'CARRERA')->whereNull('deleted_at')->count()))->toBe(1);
});

test('funcional.md §15 D-1: dos altas simultáneas sin código reciben dos códigos distintos y consecutivos (el bloqueo consultivo serializa la generación)', function (): void {
    [$tenant] = Fam::setup('fam-conc-gen');

    $holder = famPdo();
    $holder->beginTransaction();
    $holder->exec('lock table family_units in share row exclusive mode');

    $a = famSpawn($tenant->id, 'create-unit', '-');
    $b = famSpawn($tenant->id, 'create-unit', '-');

    try {
        // Uno espera para insertar (tabla bloqueada) y el otro espera el bloqueo consultivo del centro.
        famWaitBlocked(1, [$a, $b]);
        famWaitBlocked(1, [$a, $b], 'advisory');
    } finally {
        $holder->rollBack();
    }

    $results = [famCollect($a), famCollect($b)];
    $codes = array_column($results, 'code');
    sort($codes);

    expect(array_column($results, 'outcome'))->toBe(['ok', 'ok'])
        ->and($codes)->toBe(['UF-000001', 'UF-000002']);
});

test('CA-FAM-UNIT-004 RN-FAM-UNIT-11: dos altas simultáneas de tutor para la misma persona: exactamente una 201 y la otra 422 already_exists, nunca un 500', function (): void {
    [$tenant, $admin] = Fam::setup('fam-conc-004');
    $unit = Fam::unitId($tenant, $admin);
    $other = Fam::unitId($tenant, $admin, 'OTRA');
    $user = Fam::userWith($tenant, $admin, 'tutor', ['familia.leer' => 'todos']);

    $results = famRace($tenant, 'guardians', ['create-guardian', [$user->public_id, $unit]], ['create-guardian', [$user->public_id, $other]]);

    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('fam_unit.guardian.already_exists');

    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('guardians')->whereNull('deleted_at')->count()))->toBe(1);
});

test('CA-FAM-UNIT-058 RN-FAM-UNIT-44 RN-FAM-UNIT-45: dos representantes legales que registran a la vez decisiones sobre el mismo alumno y nivel dejan las dos filas — sin bloqueo de fila ni recálculo — y el estado es el que dicta la regla de combinación sobre ambas', function (): void {
    $f = Fam::consentFamily('fam-conc-058', frozen: false);
    // occurred_at y legal_guardian_verified_at tienen precisión de segundos: las decisiones han de ser posteriores.
    sleep(2);

    $results = famRace($f['tenant'], 'image_consent_decisions',
        ['record-decision', [$f['r1']->public_id, $f['student']->public_id, 'web', 'autorizo']],
        ['record-decision', [$f['r2']->public_id, $f['student']->public_id, 'web', 'no_autorizo']],
    );

    expect(array_column($results, 'outcome'))->toBe(['ok', 'ok']);

    $rows = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('image_consent_decisions')->orderBy('id')->get(['guardian_id', 'decision', 'level'])->all());
    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('decision')->sort()->values()->all())->toBe(['autorizo', 'no_autorizo']);

    // Autorizo de R1 y no_autorizo de R2, en el orden que sea: no_autorizo (RN-FAM-UNIT-45, fila f).
    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('no_autorizo');

    // Cada hijo calculó su propio estado tras escribir; ninguno vio el estado del otro en un bloqueo: son filas independientes.
    foreach ($results as $result) {
        expect(['no_autorizo', 'pendiente', 'autorizo'])->toContain($result['state']);
    }
});

test('CA-FAM-UNIT-099 RN-FAM-UNIT-60 RN-FAM-UNIT-73: dos publicaciones simultáneas de borradores del mismo tipo se serializan — ninguna 500, al final exactamente una publicada y la otra retirada — y el índice único lo garantiza', function (): void {
    [$tenant, $admin] = Fam::setup('fam-conc-099');
    $first = Fam::draftText($tenant, $admin, 'web');
    $second = Fam::draftText($tenant, $admin, 'web');

    $holder = famPdo();
    $holder->beginTransaction();
    $holder->exec('lock table terms_versions in share row exclusive mode');

    $a = famSpawn($tenant->id, 'publish-text', $first);
    $b = famSpawn($tenant->id, 'publish-text', $second);

    try {
        // Uno espera para escribir (tabla bloqueada) y el otro espera el bloqueo consultivo del centro y el tipo.
        famWaitBlocked(1, [$a, $b]);
        famWaitBlocked(1, [$a, $b], 'advisory');
    } finally {
        $holder->rollBack();
    }

    $results = [famCollect($a), famCollect($b)];

    expect(array_column($results, 'outcome'))->toBe(['ok', 'ok']);

    $statuses = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('terms_versions')->where('kind', 'consentimiento_imagen_web')->orderBy('version_number')->pluck('status')->all());
    expect(collect($statuses)->sort()->values()->all())->toBe(['publicada', 'retirada']);

    // Y el índice único es la red de seguridad: una segunda publicada del mismo tipo es imposible aunque el servicio fallara.
    $owner = famPdo();
    $owner->exec("select set_config('app.tenant_id', '{$tenant->id}', false)");
    $draft = Fam::draftText($tenant, $admin, 'web');
    $failure = null;

    try {
        $owner->exec("update terms_versions set status = 'publicada', published_at = now() where public_id = '{$draft}'");
    } catch (PDOException $e) {
        $failure = $e->getCode();
    }

    expect($failure)->toBe('23505');
});

test('datos.md §1.6 §1.8 ADR-066 §5.8 (db-reviewer B-3): el down() de image_consent_decisions y de terms_versions se niega a borrar la tabla si tiene filas (evidencia del consentimiento) y deja todo intacto', function (): void {
    $f = Fam::consentFamily('fam-conc-down', twoGuardians: false);
    Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'no_autorizo')->assertCreated();

    foreach (['2026_10_27_100300_create_image_consent_decisions_table', '2026_10_27_100200_create_terms_versions_table'] as $name) {
        $migration = require app_path("Modules/FamUnit/Database/migrations/{$name}.php");

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidencia');
    }

    $count = fn (string $table) => app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table($table)->count());
    expect($count('image_consent_decisions'))->toBe(1)->and($count('terms_versions'))->toBeGreaterThanOrEqual(2);
});
