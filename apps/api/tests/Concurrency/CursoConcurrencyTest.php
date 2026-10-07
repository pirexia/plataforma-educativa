<?php

use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;
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
test('CA-CURSO-046 CA-057-08 RN-CURSO-32: una escritura que llega con el cierre ya en curso espera, ve el curso cerrado y recibe CY001; no queda ninguna fila escrita', function (): void {
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
        ->and(cursoCollect($writer))->toMatchArray(['outcome' => 'sql_error', 'sqlstate' => 'CY001']);

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
    expect(cursoCollect($late))->toMatchArray(['outcome' => 'sql_error', 'sqlstate' => 'CY001']);
});
