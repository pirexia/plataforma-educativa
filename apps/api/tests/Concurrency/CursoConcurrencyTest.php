<?php

use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Infrastructure\AcademicYearClosedTranslator;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcademicYearProbe;
use Tests\Support\CursoTestHelpers;

/**
 * Concurrencia REAL de REQ-CURSO (1.10): `CA-CURSO-009` (RN-CURSO-04),
 * `CA-CURSO-046` y `CA-057-08` (RN-CURSO-32, ADR-057 §5.4). Participan
 * procesos PHP distintos, cada uno con su propia conexión y su propia
 * transacción, confirmados de verdad (`ConcurrentTestCase`, sin transacción
 * envolvente). Precedente: `RealConcurrencyTest` (issue #351).
 *
 * Sincronización sin `sleep` frágil, con señales de la propia base:
 * bloqueos retenidos por el test y espera, consultando `pg_stat_activity`,
 * a que cada hijo esté bloqueado en lo que debe antes de liberarlo.
 */
afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');

    foreach (DB::connection('pgsql_platform')->table('tenants')->pluck('id') as $tenantId) {
        // El propietario está exento del bloqueo (ADR-057 §5.2): borra también filas de cursos cerrados.
        // Después, el borrado del tenant arrastra el resto en cascada.
        $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
        $owner->table(AcademicYearProbe::TABLE)->delete();
    }

    $owner->statement("select set_config('app.tenant_id', '', false)");
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function cursoPdo(): PDO
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
function cursoSpawn(int $tenantId, string $operation, string ...$args): array
{
    $output = tempnam(sys_get_temp_dir(), 'curso-');
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/curso-worker.php', (string) $tenantId, $operation, ...$args],
        [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
        $pipes,
    );
    fclose($pipes[0]);

    return ['process' => $process, 'output' => $output];
}

/**
 * Espera (acotada a 60 s) a que haya exactamente `$count` peticiones de
 * bloqueo sin conceder del tipo `$waitEvent` (`advisory`, `relation`,
 * `transactionid`, `tuple`), o de cualquiera de ellos si es `null` (el
 * segundo que espera una fila bloqueada espera un `tuple`, no un
 * `transactionid`).
 *
 * @param  list<array{process: resource, output: string}>  $children
 */
function cursoWaitBlocked(?string $waitEvent, int $count, array $children): void
{
    // Conexión propia y en autocommit. `pg_locks` (visible para cualquier rol)
    // y no `pg_stat_activity`: esta oculta a `plataforma_owner` la actividad de
    // los demás roles, y es una instantánea por transacción.
    $pdo = cursoPdo();
    $deadline = microtime(true) + 60;
    $statement = $pdo->prepare("select count(*) from pg_locks where not granted and locktype in ('relation', 'transactionid', 'tuple', 'advisory') and (?::text is null or locktype = ?)");

    while (true) {
        $statement->execute([$waitEvent, $waitEvent]);

        if ((int) $statement->fetchColumn() === $count) {
            return;
        }

        foreach ($children as $i => $child) {
            if (! proc_get_status($child['process'])['running']) {
                throw new RuntimeException("El proceso hijo {$i} terminó antes de bloquearse: ".file_get_contents($child['output']));
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException("No hay {$count} sesiones bloqueadas en «{$waitEvent}» tras 60 s.");
        }

        usleep(20_000);
    }
}

/**
 * @param  array{process: resource, output: string}  $child
 * @return array<string, mixed>
 */
function cursoCollect(array $child): array
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

// CA-CURSO-009, RN-CURSO-04
test('CA-CURSO-009 RN-CURSO-04: dos altas simultáneas con un centro sin curso en planificacion: una obtiene 201 y la otra 409 planning_exists, nunca un 500', function (): void {
    AcademicYearProbe::ensureTable();
    [$tenant] = provisionCoreTenant('cur-009');

    $holder = cursoPdo();
    $holder->beginTransaction();
    // Mientras se retiene SHARE ROW EXCLUSIVE, ambos hijos pasan la comprobación
    // previa (un SELECT) y se quedan esperando para INSERTAR: la carrera real
    // sobre el índice único parcial, determinista.
    $holder->exec('lock table academic_years in share row exclusive mode');

    $a = cursoSpawn($tenant->id, 'create', '2027-2028', '2027-09-01', '2028-06-30');
    $b = cursoSpawn($tenant->id, 'create', '2027-2028-b', '2028-09-01', '2029-06-30');

    try {
        cursoWaitBlocked('relation', 2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    $results = [cursoCollect($a), cursoCollect($b)];
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(409)
        ->and($conflicts[0]['code'])->toBe('curso.conflict.planning_exists');

    $count = app(TenantContext::class)->runFor($tenant->id, fn () => AcademicYear::query()->where('status', AcademicYearStatus::Planificacion)->count());

    expect($count)->toBe(1);
});

test('RN-CURSO-10 RN-CURSO-11: dos activaciones simultáneas del mismo curso: una se hace y la otra responde 409 invalid_transition', function (): void {
    [$tenant] = provisionCoreTenant('cur-act');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Planificacion);

    $holder = cursoPdo();
    $holder->beginTransaction();
    // Sin tenant fijado, RLS ocultaría la fila al propietario: se fija solo en esta sesión.
    $holder->exec("select set_config('app.tenant_id', '{$tenant->id}', false)");
    $holder->prepare('select id from academic_years where id = ? for update')->execute([$year->id]);

    $a = cursoSpawn($tenant->id, 'activate', $year->public_id);
    $b = cursoSpawn($tenant->id, 'activate', $year->public_id);

    try {
        cursoWaitBlocked(null, 2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    $results = [cursoCollect($a), cursoCollect($b)];

    expect(count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok')))->toBe(1);

    $failed = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] !== 'ok'))[0];

    expect($failed)->toMatchArray(['outcome' => 'api_error', 'status' => 409, 'code' => 'curso.conflict.invalid_transition']);
});

// CA-CURSO-046, CA-057-08, RN-CURSO-32, ADR-057 §5.4
test('CA-CURSO-046 CA-057-08 RN-CURSO-32: una escritura que llega con el cierre ya en curso espera, ve el curso cerrado y recibe YC001; no queda ninguna fila escrita', function (): void {
    AcademicYearProbe::ensureTable();
    [$tenant] = provisionCoreTenant('cur-046a');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo);
    $key = random_int(1_000, 2_000_000_000);

    $gate = cursoPdo();
    $gate->prepare('select pg_advisory_lock(?)')->execute([$key]);

    // El cierre toma FOR UPDATE sobre el curso y espera dentro de su validación.
    $closer = cursoSpawn($tenant->id, 'close', $year->public_id, (string) $key);
    cursoWaitBlocked('advisory', 1, [$closer]);

    // La escritura intenta insertar: el disparador pide FOR SHARE y espera al cierre.
    $writer = cursoSpawn($tenant->id, 'write', (string) $year->id, 'tarde');
    cursoWaitBlocked('transactionid', 1, [$closer, $writer]);

    $gate->prepare('select pg_advisory_unlock(?)')->execute([$key]);

    expect(cursoCollect($closer))->toMatchArray(['outcome' => 'ok'])
        ->and(cursoCollect($writer))->toMatchArray(['outcome' => 'sql_error', 'sqlstate' => AcademicYearClosedTranslator::SQLSTATE]);

    $state = app(TenantContext::class)->runFor($tenant->id, fn () => [
        AcademicYear::query()->firstOrFail()->status,
        DB::table(AcademicYearProbe::TABLE)->count(),
    ]);

    expect($state[0])->toBe(AcademicYearStatus::Cerrado)->and($state[1])->toBe(0);
});

test('CA-CURSO-046 CA-057-08 RN-CURSO-32: una escritura confirmada antes de que el cierre obtenga su bloqueo se conserva, y el cierre espera a que termine', function (): void {
    AcademicYearProbe::ensureTable();
    [$tenant] = provisionCoreTenant('cur-046b');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo);
    $key = random_int(1_000, 2_000_000_000);

    $gate = cursoPdo();
    $gate->prepare('select pg_advisory_lock(?)')->execute([$key]);

    // La escritura ya está dentro de su transacción (retiene FOR SHARE sobre el curso).
    $writer = cursoSpawn($tenant->id, 'write', (string) $year->id, 'a tiempo', (string) $key);
    cursoWaitBlocked('advisory', 1, [$writer]);

    // El cierre llega después: su FOR UPDATE espera a la escritura.
    $closeKey = $key + 1;
    $gate->prepare('select pg_advisory_lock(?)')->execute([$closeKey]);
    $closer = cursoSpawn($tenant->id, 'close', $year->public_id, (string) $closeKey);
    cursoWaitBlocked('transactionid', 1, [$writer, $closer]);

    // Se libera la escritura: confirma; el cierre obtiene su bloqueo, ve el curso y cierra.
    $gate->prepare('select pg_advisory_unlock(?)')->execute([$key]);
    expect(cursoCollect($writer))->toMatchArray(['outcome' => 'ok']);

    cursoWaitBlocked('advisory', 1, [$closer]);
    $gate->prepare('select pg_advisory_unlock(?)')->execute([$closeKey]);
    expect(cursoCollect($closer))->toMatchArray(['outcome' => 'ok']);

    $state = app(TenantContext::class)->runFor($tenant->id, fn () => [
        AcademicYear::query()->firstOrFail()->status,
        DB::table(AcademicYearProbe::TABLE)->where('name', 'a tiempo')->count(),
    ]);

    // Nunca una fila escrita DESPUÉS del cierre: la única que hay es anterior (confirmó antes de que el cierre obtuviera su bloqueo).
    expect($state[0])->toBe(AcademicYearStatus::Cerrado)->and($state[1])->toBe(1);

    // Y con el curso ya cerrado, cualquier escritura posterior falla.
    $late = cursoSpawn($tenant->id, 'write', (string) $year->id, 'posterior');
    expect(cursoCollect($late))->toMatchArray(['outcome' => 'sql_error', 'sqlstate' => AcademicYearClosedTranslator::SQLSTATE]);
});

test('RN-CURSO-23 #385 B-2: guardAcademicYearWrites() aborta con 55P03 (lock_timeout) si otra sesión retiene un bloqueo sobre la tabla', function (): void {
    $holder = cursoPdo();
    $table = 'ay_guard_lock_probe';
    $holder->exec("DROP TABLE IF EXISTS {$table}");
    $holder->exec("CREATE TABLE {$table} (id bigint PRIMARY KEY, academic_year_id bigint)");

    try {
        // Segunda conexión: retiene ACCESS EXCLUSIVE hasta su ROLLBACK.
        $holder->beginTransaction();
        $holder->exec("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");

        $owner = DB::connection('pgsql_owner');
        $owner->beginTransaction();

        try {
            $started = microtime(true);

            $message = null;

            try {
                TenantMigration::guardAcademicYearWrites($table);
            } catch (QueryException $e) {
                $message = $e->getMessage();
            }

            expect($message)->not->toBeNull('el CREATE TRIGGER no abortó')
                ->and($message)->toContain('55P03');
            expect(microtime(true) - $started)->toBeLessThan(30.0);
        } finally {
            $owner->rollBack();
        }
    } finally {
        if ($holder->inTransaction()) {
            $holder->rollBack();
        }
        $holder->exec("DROP TABLE IF EXISTS {$table}");
    }
});

// ───────────────────────── 1.11d-a: cambio de fechas y lectura con FOR SHARE ─────────────────────────

/** @param  array{process: resource, output: string}  $child */
function cursoIsRunning(array $child): bool
{
    return proc_get_status($child['process'])['running'];
}

/** Bloqueo consultivo de sesión que el test retiene para detener a un hijo en un punto exacto. */
function cursoGate(int $key): PDO
{
    $gate = cursoPdo();
    $gate->prepare('select pg_advisory_lock(?)')->execute([$key]);

    return $gate;
}

function cursoRelease(PDO $gate, int $key): void
{
    $gate->prepare('select pg_advisory_unlock(?)')->execute([$key]);
}

/** @return array{string, string, string} */
function cursoYearDates(object $tenant, int $id): array
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($id): array {
        $year = AcademicYear::query()->findOrFail($id);

        return [$year->starts_on->toDateString(), $year->ends_on->toDateString(), $year->status->value];
    });
}

test('CA-CURSO-111 RN-CURSO-51: el FOR UPDATE del curso se toma antes de invocar la validación: una escritura de la tabla sonda espera al PATCH', function (): void {
    AcademicYearProbe::ensureTable();
    [$tenant] = provisionCoreTenant('cur-111a');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-08-31');
    $key = random_int(1_000, 2_000_000_000);
    $gate = cursoGate($key);

    $patch = cursoSpawn($tenant->id, 'patch', $year->public_id, '2026-09-07', '-', (string) $key, 'ok');
    cursoWaitBlocked('advisory', 1, [$patch]);

    $writer = cursoSpawn($tenant->id, 'write', (string) $year->id, 'espera al patch');
    cursoWaitBlocked('transactionid', 1, [$patch, $writer]);

    expect(cursoIsRunning($writer))->toBeTrue();

    cursoRelease($gate, $key);

    expect(cursoCollect($patch))->toMatchArray(['outcome' => 'ok'])
        ->and(cursoCollect($writer))->toMatchArray(['outcome' => 'ok'])
        ->and(cursoYearDates($tenant, $year->id)[0])->toBe('2026-09-07');
});

test('CA-CURSO-111 RN-CURSO-51: un PATCH de fechas y la activación simultáneos: o el PATCH gana y se activa con las fechas nuevas, o responde 409 not_editable; nunca 500', function (): void {
    [$tenant] = provisionCoreTenant('cur-111b');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-08-31');

    $holder = cursoPdo();
    $holder->beginTransaction();
    $holder->exec("select set_config('app.tenant_id', '{$tenant->id}', false)");
    $holder->prepare('select id from academic_years where id = ? for update')->execute([$year->id]);

    $patch = cursoSpawn($tenant->id, 'patch', $year->public_id, '2026-09-07', '-', '-', 'ok');
    $activate = cursoSpawn($tenant->id, 'activate', $year->public_id);

    try {
        cursoWaitBlocked(null, 2, [$patch, $activate]);
    } finally {
        $holder->rollBack();
    }

    $patchResult = cursoCollect($patch);
    $activateResult = cursoCollect($activate);
    [$startsOn, , $status] = cursoYearDates($tenant, $year->id);

    expect($activateResult)->toMatchArray(['outcome' => 'ok'])
        ->and($status)->toBe('activo');

    if ($patchResult['outcome'] === 'ok') {
        expect($startsOn)->toBe('2026-09-07');
    } else {
        expect($patchResult)->toMatchArray(['outcome' => 'api_error', 'status' => 409, 'code' => 'curso.conflict.not_editable'])
            ->and($startsOn)->toBe('2026-09-01');
    }
});

test('CA-CURSO-117 RN-CURSO-55 RN-CURSO-56: lockForShareById retiene FOR SHARE hasta el final de la transacción (un FOR UPDATE ajeno no lo obtiene) y fuera de transacción lanza LogicException sin consultar', function (): void {
    [$tenant] = provisionCoreTenant('cur-117');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-08-31');
    $key = random_int(1_000, 2_000_000_000);
    $gate = cursoGate($key);

    $reader = cursoSpawn($tenant->id, 'sharelock', (string) $year->id, (string) $key);
    cursoWaitBlocked('advisory', 1, [$reader]);

    $other = cursoPdo();
    $other->beginTransaction();
    $other->exec("select set_config('app.tenant_id', '{$tenant->id}', false)");

    $sqlstate = null;

    try {
        $other->prepare('select id from academic_years where id = ? for update nowait')->execute([$year->id]);
    } catch (PDOException $e) {
        $sqlstate = $e->errorInfo[0] ?? null;
    } finally {
        $other->rollBack();
    }

    expect($sqlstate)->toBe('55P03');

    cursoRelease($gate, $key);
    expect(cursoCollect($reader))->toMatchArray(['outcome' => 'ok', 'found' => true, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);

    // Sin transacción abierta (esta clase no envuelve el test en una): excepción de lógica y ninguna consulta.
    app(TenantContext::class)->runFor($tenant->id, function () use ($year): void {
        expect(DB::transactionLevel())->toBe(0);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        expect(fn () => app(AcademicYearSharedLock::class)->lockForShareById($year->id))->toThrow(LogicException::class)
            ->and($queries)->toBe([]);
    });
});

test('CA-CURSO-118 RN-CURSO-57 INV-001: el FOR SHARE sobre un curso de otro centro devuelve null y no bloquea nada: el PATCH del otro centro no espera', function (): void {
    [$tenantA] = provisionCoreTenant('cur-118a');
    [$tenantB] = provisionCoreTenant('cur-118b');
    $yearB = CursoTestHelpers::year($tenantB, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-08-31');
    $key = random_int(1_000, 2_000_000_000);
    $gate = cursoGate($key);

    $reader = cursoSpawn($tenantA->id, 'sharelock', (string) $yearB->id, (string) $key);
    cursoWaitBlocked('advisory', 1, [$reader]);

    // Con la transacción de A abierta, B cambia las fechas de su curso sin esperar.
    $patch = cursoSpawn($tenantB->id, 'patch', $yearB->public_id, '2026-09-07', '-', '-', 'ok');
    expect(cursoCollect($patch))->toMatchArray(['outcome' => 'ok']);

    cursoRelease($gate, $key);

    expect(cursoCollect($reader))->toMatchArray(['outcome' => 'ok', 'found' => false])
        ->and(cursoYearDates($tenantB, $yearB->id)[0])->toBe('2026-09-07');
});

test('CA-CURSO-119 RN-CURSO-58: un PATCH de fechas espera a quien retiene lockForShareById y no termina hasta que confirma', function (): void {
    [$tenant] = provisionCoreTenant('cur-119a');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-08-31');
    $key = random_int(1_000, 2_000_000_000);
    $gate = cursoGate($key);

    $reader = cursoSpawn($tenant->id, 'sharelock', (string) $year->id, (string) $key);
    cursoWaitBlocked('advisory', 1, [$reader]);

    $patch = cursoSpawn($tenant->id, 'patch', $year->public_id, '2026-09-07', '-', '-', 'ok');
    cursoWaitBlocked('transactionid', 1, [$reader, $patch]);

    expect(cursoIsRunning($patch))->toBeTrue()
        ->and(cursoYearDates($tenant, $year->id)[0])->toBe('2026-09-01');

    cursoRelease($gate, $key);

    expect(cursoCollect($reader))->toMatchArray(['outcome' => 'ok', 'starts_on' => '2026-09-01'])
        ->and(cursoCollect($patch))->toMatchArray(['outcome' => 'ok'])
        ->and(cursoYearDates($tenant, $year->id)[0])->toBe('2026-09-07');
});

test('CA-CURSO-119 RN-CURSO-58: con un PATCH ya dentro de su validación, lockForShareById espera y lee las fechas nuevas si confirma o las anteriores si revierte', function (string $mode, string $expectedStartsOn, string $expectedPatchOutcome): void {
    [$tenant] = provisionCoreTenant('cur-119b-'.$mode);
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-08-31');
    $key = random_int(1_000, 2_000_000_000);
    $gate = cursoGate($key);

    $patch = cursoSpawn($tenant->id, 'patch', $year->public_id, '2026-09-07', '-', (string) $key, $mode);
    cursoWaitBlocked('advisory', 1, [$patch]);

    $reader = cursoSpawn($tenant->id, 'sharelock', (string) $year->id);
    cursoWaitBlocked('transactionid', 1, [$patch, $reader]);

    expect(cursoIsRunning($reader))->toBeTrue();

    cursoRelease($gate, $key);

    $patchResult = cursoCollect($patch);
    $readerResult = cursoCollect($reader);

    expect($patchResult['outcome'])->toBe($expectedPatchOutcome)
        ->and($readerResult)->toMatchArray(['outcome' => 'ok', 'starts_on' => $expectedStartsOn]);
})->with([
    'el PATCH confirma' => ['ok', '2026-09-07', 'ok'],
    'el PATCH revierte (validación que falla)' => ['fail', '2026-09-01', 'api_error'],
]);

test('CA-CURSO-119 RN-CURSO-58: la escritura de la tabla sonda (FOR SHARE del disparador) y lockForShareById no se esperan entre sí', function (): void {
    AcademicYearProbe::ensureTable();
    [$tenant] = provisionCoreTenant('cur-119c');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-08-31');

    // 1) Quien retiene lockForShareById no hace esperar a una escritura ordinaria.
    $key = random_int(1_000, 2_000_000_000);
    $gate = cursoGate($key);
    $reader = cursoSpawn($tenant->id, 'sharelock', (string) $year->id, (string) $key);
    cursoWaitBlocked('advisory', 1, [$reader]);

    expect(cursoCollect(cursoSpawn($tenant->id, 'write', (string) $year->id, 'con lectura retenida')))->toMatchArray(['outcome' => 'ok']);

    cursoRelease($gate, $key);
    expect(cursoCollect($reader))->toMatchArray(['outcome' => 'ok']);

    // 2) Una escritura que retiene el FOR SHARE del disparador no hace esperar a lockForShareById.
    $writeKey = random_int(1_000, 2_000_000_000);
    $writeGate = cursoGate($writeKey);
    $writer = cursoSpawn($tenant->id, 'write', (string) $year->id, 'con escritura retenida', (string) $writeKey);
    cursoWaitBlocked('advisory', 1, [$writer]);

    expect(cursoCollect(cursoSpawn($tenant->id, 'sharelock', (string) $year->id)))->toMatchArray(['outcome' => 'ok', 'starts_on' => '2026-09-01']);

    cursoRelease($writeGate, $writeKey);
    expect(cursoCollect($writer))->toMatchArray(['outcome' => 'ok']);
});

// CA-CURSO-103, RN-CURSO-41, RN-CURSO-46, ADR-059 §5.1 (1.11c)
test('CA-CURSO-103 RN-CURSO-41: una reapertura de X y la activación simultánea de Y: exactamente una gana y la otra recibe 409 active_exists, nunca un 500', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-103c');
    $closed = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Cerrado, '2025-09-01', '2026-06-30');
    $next = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-06-30');
    $key = random_int(1_000, 2_000_000_000);

    $gate = cursoPdo();
    $gate->prepare('select pg_advisory_lock(?)')->execute([$key]);

    // La reapertura ya pasó «no hay otro activo» y «es el más reciente»; espera dentro
    // de su validación con el FOR UPDATE de X retenido.
    $reopener = cursoSpawn($tenant->id, 'reopen', $closed->public_id, (string) $admin->id, (string) $key);
    cursoWaitBlocked('advisory', 1, [$reopener]);

    // La activación de Y (otra fila, otro bloqueo) no espera: confirma antes.
    $activator = cursoSpawn($tenant->id, 'activate', $next->public_id);
    expect(cursoCollect($activator))->toMatchArray(['outcome' => 'ok']);

    // Se libera la reapertura: su UPDATE choca con el índice único parcial del motor
    // y se traduce al 409 de siempre.
    $gate->prepare('select pg_advisory_unlock(?)')->execute([$key]);

    expect(cursoCollect($reopener))->toMatchArray(['outcome' => 'api_error', 'status' => 409, 'code' => 'curso.conflict.active_exists']);

    $state = app(TenantContext::class)->runFor($tenant->id, fn () => [
        AcademicYear::query()->whereKey($closed->id)->firstOrFail()->status,
        AcademicYear::query()->whereKey($next->id)->firstOrFail()->status,
        DB::table('academic_year_reopenings')->count(),
    ]);

    expect($state[0])->toBe(AcademicYearStatus::Cerrado)
        ->and($state[1])->toBe(AcademicYearStatus::Activo)
        ->and($state[2])->toBe(0);
});

test('CA-CURSO-103 RN-CURSO-46 CA-057-08: una escritura que llega con la reapertura ya en curso espera y, al confirmarse, se admite (el curso ya es activo); nunca se confirma con el curso cerrado', function (): void {
    AcademicYearProbe::ensureTable();
    [$tenant, $admin] = provisionCoreTenant('cur-103d');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Cerrado, '2025-09-01', '2026-06-30');
    $key = random_int(1_000, 2_000_000_000);

    $gate = cursoPdo();
    $gate->prepare('select pg_advisory_lock(?)')->execute([$key]);

    $reopener = cursoSpawn($tenant->id, 'reopen', $year->public_id, (string) $admin->id, (string) $key);
    cursoWaitBlocked('advisory', 1, [$reopener]);

    // La escritura toma FOR SHARE en el disparador: espera al FOR UPDATE de la reapertura.
    $writer = cursoSpawn($tenant->id, 'write', (string) $year->id, 'durante la reapertura');
    cursoWaitBlocked('transactionid', 1, [$reopener, $writer]);

    $gate->prepare('select pg_advisory_unlock(?)')->execute([$key]);

    expect(cursoCollect($reopener))->toMatchArray(['outcome' => 'ok'])
        ->and(cursoCollect($writer))->toMatchArray(['outcome' => 'ok']);

    $state = app(TenantContext::class)->runFor($tenant->id, fn () => [
        AcademicYear::query()->firstOrFail()->status,
        DB::table(AcademicYearProbe::TABLE)->where('name', 'durante la reapertura')->count(),
        DB::table('academic_year_reopenings')->count(),
    ]);

    expect($state[0])->toBe(AcademicYearStatus::Activo)->and($state[1])->toBe(1)->and($state[2])->toBe(1);
});
