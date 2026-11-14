<?php

use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadPeriodHelpers as Periods;
use Tests\Support\AcadTimetableHelpers as Tt;

/**
 * Concurrencia REAL de REQ-ACAD (1.11-b): `CA-ACAD-004` (RN-ACAD-01); y de
 * 1.11d-a: `CA-ACAD-069` (RN-ACAD-54, -58, -59) y, con la validación real de
 * `acad`, `CA-CURSO-115`, `-120` (dos procesos) y `-122` (tres). Dos
 * procesos PHP distintos, cada uno con su propia conexión y su propia
 * transacción, confirmados de verdad (`ConcurrentTestCase`, sin transacción
 * envolvente). Mismo método que `CursoConcurrencyTest`: un bloqueo retenido
 * por el test hace que ambos hijos pasen la comprobación previa (un `SELECT`)
 * y se queden esperando para insertar, de modo que la carrera sobre el índice
 * único parcial es determinista.
 */
afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');

    foreach (DB::connection('pgsql_platform')->table('tenants')->pluck('id') as $tenantId) {
        $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
        // El propietario está exento del bloqueo de cursos cerrados (ADR-057 §5.2).
        foreach (['facility_bookings', 'timetable_sessions', 'teaching_assignments', 'group_tutors', 'time_slots', 'timetable_frames', 'timetables', 'facilities', 'grading_scale_values', 'grading_scales', 'academic_periods', 'group_subjects', 'subgroups', 'groups', 'subjects', 'courses', 'education_levels'] as $table) {
            $owner->table($table)->delete();
        }
    }

    $owner->statement("select set_config('app.tenant_id', '', false)");
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function acadPdo(): PDO
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
function acadSpawn(int $tenantId, string $operation, string ...$args): array
{
    $output = tempnam(sys_get_temp_dir(), 'acad-');
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/acad-worker.php', (string) $tenantId, $operation, ...$args],
        [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
        $pipes,
    );
    fclose($pipes[0]);

    return ['process' => $process, 'output' => $output];
}

/**
 * Espera (acotada a 60 s) a que haya exactamente `$count` peticiones de
 * bloqueo sin conceder del tipo `relation`.
 *
 * @param  list<array{process: resource, output: string}>  $children
 */
function acadWaitBlocked(int $count, array $children): void
{
    $pdo = acadPdo();
    $deadline = microtime(true) + 60;
    $statement = $pdo->prepare("select count(*) from pg_locks where not granted and locktype = 'relation'");

    while (true) {
        $statement->execute();

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
function acadCollect(array $child): array
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

test('CA-ACAD-004 RN-ACAD-01: dos altas simultáneas de un grupo con el mismo código bajo el mismo curso de etapa: exactamente una 201 y la otra 422 code_taken, nunca un 500', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-004c');
    $tree = Api::tree($tenant, $admin, $year);

    $holder = acadPdo();
    $holder->beginTransaction();
    // Mientras se retiene SHARE ROW EXCLUSIVE, ambos hijos pasan la comprobación
    // previa y se quedan esperando para INSERTAR: la carrera real sobre el índice único parcial.
    $holder->exec('lock table groups in share row exclusive mode');

    $a = acadSpawn($tenant->id, 'create-group', $tree['course'], 'CARRERA');
    $b = acadSpawn($tenant->id, 'create-group', $tree['course'], 'CARRERA');

    try {
        acadWaitBlocked(2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    $results = [acadCollect($a), acadCollect($b)];
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('acad.validation.code_taken');

    $count = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('groups')->where('code', 'CARRERA')->whereNull('deleted_at')->count());

    expect($count)->toBe(1);
});

test('CA-ACAD-084 RN-ACAD-71: dos altas simultáneas de una escala con el mismo código en el mismo curso académico: exactamente una 201 y la otra 422 code_taken, nunca un 500', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-084c');

    $holder = acadPdo();
    $holder->beginTransaction();
    // Como CA-ACAD-004: ambos hijos pasan la comprobación previa y esperan para INSERTAR.
    $holder->exec('lock table grading_scales in share row exclusive mode');

    $a = acadSpawn($tenant->id, 'create-scale', $year->public_id, 'CARRERA');
    $b = acadSpawn($tenant->id, 'create-scale', $year->public_id, 'CARRERA');

    try {
        acadWaitBlocked(2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    $results = [acadCollect($a), acadCollect($b)];
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('acad.validation.code_taken');

    $count = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('grading_scales')->where('code', 'CARRERA')->whereNull('deleted_at')->count());

    expect($count)->toBe(1);
});

// ───────────────────────── 1.11d-a: períodos lectivos y cambio de fechas del curso ─────────────────────────

/** Bloqueo consultivo de sesión que el test retiene para detener a un hijo en un punto exacto. */
function acadGate(int $key): PDO
{
    $gate = acadPdo();
    $gate->prepare('select pg_advisory_lock(?)')->execute([$key]);

    return $gate;
}

function acadRelease(PDO $gate, int $key): void
{
    $gate->prepare('select pg_advisory_unlock(?)')->execute([$key]);
}

/** @param  array{process: resource, output: string}  $child */
function acadIsRunning(array $child): bool
{
    return proc_get_status($child['process'])['running'];
}

/**
 * Espera (acotada a 60 s) a que haya exactamente `$count` peticiones de
 * bloqueo sin conceder, de cualquier tipo (`advisory`, `relation`,
 * `transactionid`, `tuple`): hijos esperando una fila, la tabla o la puerta.
 *
 * @param  list<array{process: resource, output: string}>  $children
 */
function acadWaitBlockedAny(int $count, array $children): void
{
    $pdo = acadPdo();
    $deadline = microtime(true) + 60;
    $statement = $pdo->prepare("select count(*) from pg_locks where not granted and locktype in ('relation', 'transactionid', 'tuple', 'advisory')");

    while (true) {
        $statement->execute();

        if ((int) $statement->fetchColumn() === $count) {
            return;
        }

        foreach ($children as $i => $child) {
            if (! acadIsRunning($child)) {
                throw new RuntimeException("El proceso hijo {$i} terminó antes de bloquearse: ".file_get_contents($child['output']));
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException("No hay {$count} sesiones bloqueadas tras 60 s.");
        }

        usleep(20_000);
    }
}

/** @return array{string, string, string} fechas del curso y estado, leídas por el propietario del test */
function acadYearState(object $tenant, int $id): array
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($id): array {
        $year = AcademicYear::query()->findOrFail($id);

        return [$year->starts_on->toDateString(), $year->ends_on->toDateString(), $year->status->value];
    });
}

/** @return list<array{string, string}> períodos vivos (inicio, fin) de todo el centro */
function acadLivePeriods(object $tenant): array
{
    return app(TenantContext::class)->runFor($tenant->id, fn (): array => AcademicPeriod::query()->orderBy('starts_on')->get()->map(fn (AcademicPeriod $p): array => [$p->starts_on->toDateString(), $p->ends_on->toDateString()])->all());
}

/** Centro con un curso de prueba en planificación (2026-09-01 a 2027-08-31), `acad` contratado y una etapa. */
function acadPeriodFixture(string $slug, int $levels = 1): array
{
    [$tenant, $admin, $year] = Api::setup($slug, AcademicYearStatus::Planificacion, '2027-08-31');
    $ids = [];

    for ($i = 0; $i < $levels; $i++) {
        $ids[] = Periods::level($tenant, $year, 'ETAPA'.$i);
    }

    return [$tenant, $year, $ids];
}

test('CA-ACAD-069 RN-ACAD-54 RN-ACAD-58: dos altas simultáneas de períodos que solapan en la misma etapa: exactamente una 201 y la otra 422 period_overlap, nunca dos 201 ni un 500', function (): void {
    [$tenant, $year, [$level]] = acadPeriodFixture('acd-069a');
    $key = random_int(1_000, 2_000_000_000);
    $gate = acadGate($key);

    // El primero valida y se detiene antes de insertar, con la etapa bloqueada (FOR UPDATE); el segundo espera la etapa.
    $first = acadSpawn($tenant->id, 'create-period', $level->public_id, '2026-09-08', '2026-12-22', '-', (string) $key);
    acadWaitBlockedAny(1, [$first]);
    $second = acadSpawn($tenant->id, 'create-period', $level->public_id, '2026-12-01', '2027-03-26', '-', '-');
    acadWaitBlockedAny(2, [$first, $second]);

    expect(acadIsRunning($second))->toBeTrue();

    acadRelease($gate, $key);
    $results = [acadCollect($first), acadCollect($second)];

    expect($results[0])->toMatchArray(['outcome' => 'ok'])
        ->and($results[1])->toMatchArray(['outcome' => 'api_error', 'status' => 422, 'code' => 'acad.validation.period_overlap'])
        ->and(acadLivePeriods($tenant))->toBe([['2026-09-08', '2026-12-22']]);
});

test('CA-ACAD-069 RN-ACAD-54 RN-ACAD-59: dos altas simultáneas de tipos distintos, no solapadas, en la misma etapa: una 201 y la otra 422 period_type_mismatch', function (): void {
    [$tenant, $year, [$level]] = acadPeriodFixture('acd-069b');
    $key = random_int(1_000, 2_000_000_000);
    $gate = acadGate($key);

    $first = acadSpawn($tenant->id, 'create-period', $level->public_id, '2026-09-08', '2026-12-22', 'trimestre', (string) $key);
    acadWaitBlockedAny(1, [$first]);
    $second = acadSpawn($tenant->id, 'create-period', $level->public_id, '2027-01-10', '2027-06-20', 'semestre', '-');
    acadWaitBlockedAny(2, [$first, $second]);

    acadRelease($gate, $key);
    $results = [acadCollect($first), acadCollect($second)];

    expect($results[0])->toMatchArray(['outcome' => 'ok'])
        ->and($results[1])->toMatchArray(['outcome' => 'api_error', 'status' => 422, 'code' => 'acad.validation.period_type_mismatch'])
        ->and(acadLivePeriods($tenant))->toBe([['2026-09-08', '2026-12-22']]);
});

test('CA-ACAD-069 RN-ACAD-54 RN-ACAD-61: un alta de período y el borrado de su etapa simultáneos: o el borrado da 409 has_dependents o el alta 422 parent_not_found, nunca ambos con éxito', function (): void {
    // Corrida 1: el alta toma la etapa primero; el borrado espera y, al verla con un período vivo, responde 409.
    [$tenant, $year, [$level]] = acadPeriodFixture('acd-069c');
    $key = random_int(1_000, 2_000_000_000);
    $gate = acadGate($key);

    $create = acadSpawn($tenant->id, 'create-period', $level->public_id, '2026-09-08', '2026-12-22', '-', (string) $key);
    acadWaitBlockedAny(1, [$create]);
    $delete = acadSpawn($tenant->id, 'delete-level', $level->public_id, '-');
    acadWaitBlockedAny(2, [$create, $delete]);

    acadRelease($gate, $key);

    expect(acadCollect($create))->toMatchArray(['outcome' => 'ok'])
        ->and(acadCollect($delete))->toMatchArray(['outcome' => 'api_error', 'status' => 409, 'code' => 'acad.conflict.has_dependents'])
        ->and(acadLivePeriods($tenant))->toBe([['2026-09-08', '2026-12-22']]);

    // Corrida 2: el borrado toma la etapa primero; el alta espera y, al verla borrada, responde 422.
    [$tenant2, $year2, [$level2]] = acadPeriodFixture('acd-069d');
    $key2 = random_int(1_000, 2_000_000_000);
    $gate2 = acadGate($key2);

    $delete2 = acadSpawn($tenant2->id, 'delete-level', $level2->public_id, (string) $key2);
    acadWaitBlockedAny(1, [$delete2]);
    $create2 = acadSpawn($tenant2->id, 'create-period', $level2->public_id, '2026-09-08', '2026-12-22', '-', '-');
    acadWaitBlockedAny(2, [$delete2, $create2]);

    acadRelease($gate2, $key2);

    expect(acadCollect($delete2))->toMatchArray(['outcome' => 'ok'])
        ->and(acadCollect($create2))->toMatchArray(['outcome' => 'api_error', 'status' => 422, 'code' => 'acad.validation.parent_not_found'])
        ->and(acadLivePeriods($tenant2))->toBe([]);
});

test('CA-CURSO-115 CA-CURSO-120 RN-CURSO-58 RN-ACAD-54: un alta de período detenida después de leer las fechas del curso (FOR SHARE) hace esperar al PATCH de fechas; confirma 201 y el PATCH responde 409 con la entrada de acad; el curso no cambia', function (): void {
    [$tenant, $year, [$level]] = acadPeriodFixture('cur-120a');
    $key = random_int(1_000, 2_000_000_000);
    $gate = acadGate($key);

    $create = acadSpawn($tenant->id, 'create-period', $level->public_id, '2026-09-08', '2026-12-22', '-', (string) $key);
    acadWaitBlockedAny(1, [$create]);
    $patch = acadSpawn($tenant->id, 'patch-year', $year->public_id, '2026-09-10', '-', '-');
    acadWaitBlockedAny(2, [$create, $patch]);

    expect(acadIsRunning($patch))->toBeTrue()
        ->and(acadYearState($tenant, $year->id)[0])->toBe('2026-09-01');

    acadRelease($gate, $key);

    expect(acadCollect($create))->toMatchArray(['outcome' => 'ok'])
        ->and(acadCollect($patch))->toMatchArray(['outcome' => 'api_error', 'status' => 409, 'code' => 'acad.conflict.periods_outside_academic_year'])
        ->and(acadYearState($tenant, $year->id))->toBe(['2026-09-01', '2027-08-31', 'planificacion'])
        ->and(acadLivePeriods($tenant))->toBe([['2026-09-08', '2026-12-22']]);
});

test('CA-CURSO-115 CA-CURSO-120 RN-CURSO-58 RN-ACAD-57: con el PATCH de fechas ya dentro de la validación (FOR UPDATE del curso), el alta de período espera en lockForShareById, lee las fechas nuevas y responde 422 period_outside_academic_year; no se crea el período', function (): void {
    [$tenant, $year, [$level]] = acadPeriodFixture('cur-120b');
    $key = random_int(1_000, 2_000_000_000);
    $gate = acadGate($key);

    $patch = acadSpawn($tenant->id, 'patch-year', $year->public_id, '2026-09-10', '-', (string) $key);
    acadWaitBlockedAny(1, [$patch]);
    $create = acadSpawn($tenant->id, 'create-period', $level->public_id, '2026-09-08', '2026-12-22', '-', '-');
    acadWaitBlockedAny(2, [$patch, $create]);

    expect(acadIsRunning($create))->toBeTrue();

    acadRelease($gate, $key);

    expect(acadCollect($patch))->toMatchArray(['outcome' => 'ok'])
        ->and(acadCollect($create))->toMatchArray(['outcome' => 'api_error', 'status' => 422, 'code' => 'acad.validation.period_outside_academic_year'])
        ->and(acadYearState($tenant, $year->id)[0])->toBe('2026-09-10')
        ->and(acadLivePeriods($tenant))->toBe([]);
});

test('CA-CURSO-120 RN-CURSO-58 RN-ACAD-54 RN-ACAD-57: lo mismo con la edición de un período existente (PATCH /academic-periods) en las dos direcciones', function (): void {
    // a) La edición lee las fechas, se detiene antes de escribir; el PATCH del curso espera y responde 409.
    [$tenant, $year, [$level]] = acadPeriodFixture('cur-120c');
    $period = Periods::period($tenant, $year, $level, '2026-09-20', '2026-12-22');
    $key = random_int(1_000, 2_000_000_000);
    $gate = acadGate($key);

    $update = acadSpawn($tenant->id, 'update-period', $period->public_id, '2026-09-08', '-', (string) $key);
    acadWaitBlockedAny(1, [$update]);
    $patch = acadSpawn($tenant->id, 'patch-year', $year->public_id, '2026-09-10', '-', '-');
    acadWaitBlockedAny(2, [$update, $patch]);

    acadRelease($gate, $key);

    expect(acadCollect($update))->toMatchArray(['outcome' => 'ok'])
        ->and(acadCollect($patch))->toMatchArray(['outcome' => 'api_error', 'status' => 409, 'code' => 'acad.conflict.periods_outside_academic_year'])
        ->and(acadLivePeriods($tenant))->toBe([['2026-09-08', '2026-12-22']])
        ->and(acadYearState($tenant, $year->id)[0])->toBe('2026-09-01');

    // b) El PATCH del curso (09-10) retiene el FOR UPDATE dentro de su validación; la edición espera, lee 09-10 y responde 422.
    [$tenant2, $year2, [$level2]] = acadPeriodFixture('cur-120d');
    $period2 = Periods::period($tenant2, $year2, $level2, '2026-09-20', '2026-12-22');
    $key2 = random_int(1_000, 2_000_000_000);
    $gate2 = acadGate($key2);

    $patch2 = acadSpawn($tenant2->id, 'patch-year', $year2->public_id, '2026-09-10', '-', (string) $key2);
    acadWaitBlockedAny(1, [$patch2]);
    $update2 = acadSpawn($tenant2->id, 'update-period', $period2->public_id, '2026-09-08', '-', '-');
    acadWaitBlockedAny(2, [$patch2, $update2]);

    acadRelease($gate2, $key2);

    expect(acadCollect($patch2))->toMatchArray(['outcome' => 'ok'])
        ->and(acadCollect($update2))->toMatchArray(['outcome' => 'api_error', 'status' => 422, 'code' => 'acad.validation.period_outside_academic_year'])
        ->and(acadLivePeriods($tenant2))->toBe([['2026-09-20', '2026-12-22']])
        ->and(acadYearState($tenant2, $year2->id)[0])->toBe('2026-09-10');
});

test('CA-CURSO-122 RN-CURSO-58 RN-ACAD-54 RN-ACAD-57 (tres procesos, el borrado de la etapa retiene su FOR UPDATE): ningún proceso recibe 40P01, los tres terminan y el estado final es coherente: el alta responde 422 parent_not_found y el PATCH 200', function (): void {
    [$tenant, $year, [$level]] = acadPeriodFixture('cur-122a');
    $key = random_int(1_000, 2_000_000_000);
    $gate = acadGate($key);

    // (2) El proceso 2 toma FOR UPDATE de la etapa (borrado) y se detiene antes de escribir.
    $holder = acadSpawn($tenant->id, 'delete-level', $level->public_id, (string) $key);
    acadWaitBlockedAny(1, [$holder]);
    // (1) El proceso 1 lee el curso con FOR SHARE y queda esperando el FOR UPDATE de la etapa.
    $period = acadSpawn($tenant->id, 'create-period', $level->public_id, '2026-09-08', '2026-12-22', '-', '-');
    acadWaitBlockedAny(2, [$holder, $period]);
    // (3) El proceso 3 (PATCH de fechas) espera el FOR UPDATE del curso, bloqueado por el FOR SHARE del proceso 1.
    $patch = acadSpawn($tenant->id, 'patch-year', $year->public_id, '2026-09-10', '-', '-');
    acadWaitBlockedAny(3, [$holder, $period, $patch]);

    // Se suelta el proceso 2: su disparador pide FOR SHARE sobre el curso con el PATCH ya esperando.
    acadRelease($gate, $key);
    $results = ['holder' => acadCollect($holder), 'period' => acadCollect($period), 'patch' => acadCollect($patch)];

    foreach ($results as $name => $result) {
        expect($result['outcome'])->not->toBe('sql_error', "{$name}: ".json_encode($result));
    }

    expect($results['holder'])->toMatchArray(['outcome' => 'ok'])
        ->and($results['period'])->toMatchArray(['outcome' => 'api_error', 'status' => 422, 'code' => 'acad.validation.parent_not_found'])
        ->and($results['patch'])->toMatchArray(['outcome' => 'ok'])
        ->and(acadLivePeriods($tenant))->toBe([])
        ->and(acadYearState($tenant, $year->id)[0])->toBe('2026-09-10');
});

test('CA-CURSO-122 RN-CURSO-58 RN-ACAD-54 RN-ACAD-57 (tres procesos, el alta de un curso de etapa retiene su FOR SHARE de la etapa): ningún proceso recibe 40P01, los tres terminan y ningún período vivo queda fuera de las fechas confirmadas', function (): void {
    [$tenant, $year, [$level]] = acadPeriodFixture('cur-122b');
    $key = random_int(1_000, 2_000_000_000);
    $gate = acadGate($key);

    $holder = acadSpawn($tenant->id, 'create-course', $level->public_id, '1ESO', (string) $key);
    acadWaitBlockedAny(1, [$holder]);
    $period = acadSpawn($tenant->id, 'create-period', $level->public_id, '2026-09-08', '2026-12-22', '-', '-');
    acadWaitBlockedAny(2, [$holder, $period]);
    $patch = acadSpawn($tenant->id, 'patch-year', $year->public_id, '2026-09-10', '-', '-');
    acadWaitBlockedAny(3, [$holder, $period, $patch]);

    acadRelease($gate, $key);
    $results = ['holder' => acadCollect($holder), 'period' => acadCollect($period), 'patch' => acadCollect($patch)];

    foreach ($results as $name => $result) {
        expect($result['outcome'])->not->toBe('sql_error', "{$name}: ".json_encode($result));
    }

    // El curso de etapa se crea; el período se crea (la etapa sigue viva); el PATCH, que habría dejado el período fuera, responde 409.
    expect($results['holder'])->toMatchArray(['outcome' => 'ok'])
        ->and($results['period'])->toMatchArray(['outcome' => 'ok'])
        ->and($results['patch'])->toMatchArray(['outcome' => 'api_error', 'status' => 409, 'code' => 'acad.conflict.periods_outside_academic_year'])
        ->and(acadLivePeriods($tenant))->toBe([['2026-09-08', '2026-12-22']])
        ->and(acadYearState($tenant, $year->id)[0])->toBe('2026-09-01');
});

// ───────────────────────── 1.12-a: asignación docente y sesiones de horario ─────────────────────────

test('CA-ACAD-110 RN-ACAD-94: dos altas simultáneas de la misma asignación docente: exactamente una 201 y la otra 422 assignment_exists, nunca un 500', function (): void {
    $s = Tt::scenario('acad-110c');

    $holder = acadPdo();
    $holder->beginTransaction();
    // Ambos hijos pasan la comprobación previa y esperan para INSERTAR: carrera real sobre el índice único.
    $holder->exec('lock table teaching_assignments in share row exclusive mode');

    $a = acadSpawn($s['tenant']->id, 'create-assignment', $s['t1']->public_id, $s['api']['group'], $s['api']['subject']);
    $b = acadSpawn($s['tenant']->id, 'create-assignment', $s['t1']->public_id, $s['api']['group'], $s['api']['subject']);

    try {
        acadWaitBlocked(2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    $results = [acadCollect($a), acadCollect($b)];
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('acad.validation.assignment_exists')
        ->and(Tt::count($s['tenant'], 'teaching_assignments'))->toBe(1);
});

/**
 * Dos hijos crean a la vez una sesión en la misma franja. El test retiene un
 * bloqueo de tabla sobre `timetable_sessions` que solo detiene el INSERT: el
 * primer hijo toma el ancla (`FOR UPDATE`), lee y se queda esperando para
 * insertar; el segundo espera el ancla. Sin la serialización de `RN-ACAD-125`
 * ambos leerían antes de que el otro escribiera y los dos darían 201.
 */
function acadSessionRace(array $s, string $assignmentA, ?string $facilityA, string $assignmentB, ?string $facilityB): array
{
    $holder = acadPdo();
    $holder->beginTransaction();
    $holder->exec('lock table timetable_sessions in share row exclusive mode');

    $a = acadSpawn($s['tenant']->id, 'create-session', $s['slots'][0], $assignmentA, $facilityA ?? '-');
    $b = acadSpawn($s['tenant']->id, 'create-session', $s['slots'][0], $assignmentB, $facilityB ?? '-');

    try {
        acadWaitBlockedAny(2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    return [acadCollect($a), acadCollect($b)];
}

test('CA-ACAD-126 RN-ACAD-125 RN-ACAD-123: dos procesos reales crean a la vez dos sesiones que chocan por profesor (grupos distintos): exactamente una 201 y la otra 422 timetable_conflict, nunca dos 201 ni un 500', function (): void {
    $s = Tt::scenario('acad-126a');
    $groupB = Tt::send($s['tenant'], $s['admin'], 'POST', '/groups', ['course' => $s['api']['course'], 'code' => 'B', 'name' => 'Grupo B'])->assertCreated()->json('public_id');
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$groupB}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    $one = Tt::postAssignment($s['tenant'], $s['admin'], $s['t1'], $s['api']['group'], $s['api']['subject'])->json('public_id');
    $two = Tt::postAssignment($s['tenant'], $s['admin'], $s['t1'], $groupB, $s['api']['subject'])->json('public_id');

    $results = acadSessionRace($s, $one, null, $two, null);
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('acad.validation.timetable_conflict')
        ->and(Tt::count($s['tenant'], 'timetable_sessions'))->toBe(1);
});

test('CA-ACAD-126 RN-ACAD-125 RN-ACAD-123: dos procesos reales crean a la vez dos sesiones que chocan por aula (profesores y grupos distintos): exactamente una 201 y la otra 422 timetable_conflict', function (): void {
    $s = Tt::scenario('acad-126b');
    $groupB = Tt::send($s['tenant'], $s['admin'], 'POST', '/groups', ['course' => $s['api']['course'], 'code' => 'B', 'name' => 'Grupo B'])->assertCreated()->json('public_id');
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$groupB}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    $one = Tt::postAssignment($s['tenant'], $s['admin'], $s['t1'], $s['api']['group'], $s['api']['subject'])->json('public_id');
    $two = Tt::postAssignment($s['tenant'], $s['admin'], $s['t2'], $groupB, $s['api']['subject'])->json('public_id');

    $results = acadSessionRace($s, $one, $s['facility'], $two, $s['facility']);
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('acad.validation.timetable_conflict')
        ->and(Tt::count($s['tenant'], 'timetable_sessions'))->toBe(1);
});

// ───────────────────────── 1.12-b: reservas y marcos especiales ─────────────────────────

test('CA-ACAD-138 RN-ACAD-125 RN-ACAD-141: dos procesos reales reservan a la vez el mismo recurso, fecha e intervalo solapado: exactamente una 201 y la otra 422 booking_conflict, nunca dos 201 ni un 500', function (): void {
    $s = Tt::scenario('acad-138a');
    $resource = Tt::postFacility($s['tenant'], $s['admin'], ['facility_type' => 'recurso', 'code' => 'CARRO', 'name' => 'Carro de portátiles'])->assertCreated()->json('public_id');

    // Bloqueo de tabla que solo detiene el INSERT: el primer hijo toma el ancla (FOR UPDATE), lee y espera
    // para insertar; el segundo espera el ancla. Sin la serialización de RN-ACAD-125 ambos darían 201.
    $holder = acadPdo();
    $holder->beginTransaction();
    $holder->exec('lock table facility_bookings in share row exclusive mode');

    $a = acadSpawn($s['tenant']->id, 'create-booking', $s['year']->public_id, $resource, '2026-11-12', '10:00', '11:00');
    $b = acadSpawn($s['tenant']->id, 'create-booking', $s['year']->public_id, $resource, '2026-11-12', '10:30', '11:30');

    try {
        acadWaitBlockedAny(2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    $results = [acadCollect($a), acadCollect($b)];
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('acad.validation.booking_conflict')
        ->and(Tt::count($s['tenant'], 'facility_bookings'))->toBe(1);
});

test('CA-ACAD-136 RN-ACAD-111 RN-ACAD-125: dos procesos reales crean a la vez dos marcos especiales de fechas solapadas en la misma etapa: exactamente uno 201 y el otro 422 frame_overlap, nunca dos 201 ni un 500', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-136a');
    $level = Api::level($tenant, $admin, $year);

    $holder = acadPdo();
    $holder->beginTransaction();
    $holder->exec('lock table timetable_frames in share row exclusive mode');

    $a = acadSpawn($tenant->id, 'create-special-frame', $level, '2027-06-01', '2027-06-20');
    $b = acadSpawn($tenant->id, 'create-special-frame', $level, '2027-06-10', '2027-06-25');

    try {
        acadWaitBlockedAny(2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    $results = [acadCollect($a), acadCollect($b)];
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('acad.validation.frame_overlap')
        ->and(Tt::count($tenant, 'timetable_frames'))->toBe(1);
});

test('CA-ACAD-154 RN-ACAD-123 (#446-d, db-reviewer B3): la migración del único (franja, asignación) se niega con un mensaje claro si ya hay una pareja repetida, y construye el índice cuando se corrige', function (): void {
    $s = Tt::scenario('acad-154');
    $one = Tt::postAssignment($s['tenant'], $s['admin'], $s['t1'], $s['api']['group'], $s['api']['subject'])->assertCreated()->json('public_id');
    $groupB = Tt::send($s['tenant'], $s['admin'], 'POST', '/groups', ['course' => $s['api']['course'], 'code' => 'B', 'name' => 'Grupo B'])->assertCreated()->json('public_id');
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$groupB}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    $two = Tt::postAssignment($s['tenant'], $s['admin'], $s['t2'], $groupB, $s['api']['subject'])->assertCreated()->json('public_id');
    Tt::postSession($s['tenant'], $s['admin'], $s['slots'][0], $one)->assertCreated();
    Tt::postSession($s['tenant'], $s['admin'], $s['slots'][1], $two)->assertCreated();

    $owner = DB::connection('pgsql_owner');
    $migration = require base_path('app/Modules/Acad/Database/migrations/2026_10_20_100200_add_session_slot_assignment_unique.php');
    $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $s['tenant']->id]);
    $owner->statement('DROP INDEX IF EXISTS timetable_sessions_tenant_slot_assignment_unique');

    try {
        // Fuerza la pareja repetida por SQL a mano: la segunda sesión pasa a la franja y asignación de la primera.
        $first = $owner->selectOne('select time_slot_id, teaching_assignment_id from timetable_sessions order by id limit 1');
        $owner->update('update timetable_sessions set time_slot_id = ?, teaching_assignment_id = ? where id = (select max(id) from timetable_sessions)', [$first->time_slot_id, $first->teaching_assignment_id]);
        $owner->statement("select set_config('app.tenant_id', '', false)");

        $message = null;

        try {
            $migration->up();
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        expect($message)->toContain('misma franja y la misma asignación')->and($message)->toContain("tenant {$s['tenant']->id}");

        // Corregida la pareja, la migración construye el índice válido.
        $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $s['tenant']->id]);
        $owner->delete('delete from timetable_sessions where id = (select max(id) from timetable_sessions)');
        $owner->statement("select set_config('app.tenant_id', '', false)");
        $migration->up();

        $valid = $owner->selectOne("select i.indisvalid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = 'timetable_sessions_tenant_slot_assignment_unique'");

        expect($valid->indisvalid)->toBeTrue();
    } finally {
        $owner->statement("select set_config('app.tenant_id', '', false)");
        $migration->up();
    }
});
