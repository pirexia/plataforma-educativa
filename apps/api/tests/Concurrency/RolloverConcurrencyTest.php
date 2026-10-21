<?php

use App\Models\UserStatus;
use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Modules\Curso\Infrastructure\RepeatableReadTransaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CursoTestHelpers;
use Tests\Support\RolloverTestHelpers as R;

/**
 * Concurrencia REAL de la apertura del curso siguiente (REQ-CURSO-002, 1.12b): `CA-CURSO-131` (RN-CURSO-67),
 * `CA-CURSO-132` (RN-CURSO-70, -71, -72, -69) y `CA-CURSO-158` (RN-CURSO-86). Participan procesos PHP distintos,
 * cada uno con su propia conexión y su propia transacción, confirmados de verdad (`ConcurrentTestCase`, sin
 * transacción envolvente: aquí SÍ rige la semántica de `REPEATABLE READ`, que los tests de `tests/Feature/`, dentro de
 * la transacción del test, no pueden probar). Mismo método que `CursoConcurrencyTest`: un bloqueo consultivo retenido
 * por el test detiene a los hijos en el punto exacto, y el test espera, consultando `pg_locks`, a que cada uno esté
 * bloqueado en lo que debe antes de liberarlo.
 */
afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');

    foreach (DB::connection('pgsql_platform')->table('tenants')->pluck('id') as $tenantId) {
        $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
        // El propietario está exento del bloqueo de cursos cerrados (ADR-057 §5.2).
        foreach (['year_rollover_items', 'year_rollovers', 'facility_bookings', 'timetable_sessions', 'teaching_assignments', 'group_tutors', 'time_slots', 'timetable_frames', 'timetables', 'facilities', 'grading_scale_values', 'grading_scales', 'academic_periods', 'group_subjects', 'subgroups', 'groups', 'subjects', 'courses', 'education_levels'] as $table) {
            $owner->table($table)->delete();
        }
    }

    $owner->statement("select set_config('app.tenant_id', '', false)");
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function rolloverPdo(): PDO
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
function rolloverSpawn(string $script, int $tenantId, string $operation, string ...$args): array
{
    $output = tempnam(sys_get_temp_dir(), 'rollover-');
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/'.$script, (string) $tenantId, $operation, ...$args],
        [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
        $pipes,
    );
    fclose($pipes[0]);

    return ['process' => $process, 'output' => $output];
}

/**
 * Espera (acotada a 60 s) a que haya exactamente `$count` peticiones de bloqueo sin conceder del tipo `$lockType`
 * (`advisory`, `relation`, `transactionid`, `tuple`), o de cualquiera de ellos si es `null`.
 *
 * @param  list<array{process: resource, output: string}>  $children
 */
function rolloverWaitBlocked(?string $lockType, int $count, array $children): void
{
    $pdo = rolloverPdo();
    $deadline = microtime(true) + 60;
    $statement = $pdo->prepare("select count(*) from pg_locks where not granted and locktype in ('relation', 'transactionid', 'tuple', 'advisory') and (?::text is null or locktype = ?)");

    while (true) {
        $statement->execute([$lockType, $lockType]);

        if ((int) $statement->fetchColumn() === $count) {
            return;
        }

        foreach ($children as $i => $child) {
            if (! proc_get_status($child['process'])['running']) {
                throw new RuntimeException("El proceso hijo {$i} terminó antes de bloquearse: ".file_get_contents($child['output']));
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException("No hay {$count} sesiones bloqueadas en «{$lockType}» tras 60 s.");
        }

        usleep(20_000);
    }
}

/**
 * @param  array{process: resource, output: string}  $child
 * @return array<string, mixed>
 */
function rolloverCollect(array $child): array
{
    $deadline = microtime(true) + 90;

    while (proc_get_status($child['process'])['running']) {
        if (microtime(true) > $deadline) {
            proc_terminate($child['process'], 9);
            throw new RuntimeException('El proceso hijo no terminó en 90 s.');
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

/** Retiene el bloqueo consultivo `$key` en una conexión propia y devuelve esa conexión. */
function rolloverHoldGate(int $key): PDO
{
    $pdo = rolloverPdo();
    $pdo->query("select pg_advisory_lock({$key})");

    return $pdo;
}

function rolloverReleaseGate(PDO $pdo, int $key): void
{
    $pdo->query("select pg_advisory_unlock({$key})");
}

test('CA-CURSO-131 RN-CURSO-67: dos solicitudes simultáneas hacia el mismo destino: exactamente una 202 y la otra 409 rollover_exists, nunca un 500', function (): void {
    $s = R::bare('rcc-131');
    $key = 7_131_001;
    $gate = rolloverHoldGate($key);

    $children = [
        rolloverSpawn('rollover-worker.php', $s['tenant']->id, 'request', (string) $s['admin']->id, $s['target']->public_id, $s['source']->public_id, (string) $key),
        rolloverSpawn('rollover-worker.php', $s['tenant']->id, 'request', (string) $s['admin']->id, $s['target']->public_id, $s['source']->public_id, (string) $key),
    ];

    // Ambos han pasado la comprobación de «copia viva» y esperan dentro de preview() con el FOR SHARE del destino.
    rolloverWaitBlocked('advisory', 2, $children);
    rolloverReleaseGate($gate, $key);

    $results = array_map('rolloverCollect', $children);
    $outcomes = array_map(fn (array $r): string => $r['outcome'] === 'ok' ? 'ok' : "{$r['status']}:{$r['code']}", $results);
    sort($outcomes);

    expect($outcomes)->toBe(['409:curso.conflict.rollover_exists', 'ok'])
        ->and(app(TenantContext::class)->runFor($s['tenant']->id, fn () => YearRollover::query()->where('academic_year_id', $s['target']->id)->count()))->toBe(1);
});

/**
 * Centro con `acad`, estructura real en el origen (cerrado, para poder activar el destino) y una copia `pendiente` de
 * etapas, períodos y un elemento de prueba, lista para que un proceso la ejecute.
 *
 * @return array<string, mixed>
 */
function rolloverReadyScenario(string $slug): array
{
    $s = R::scenario($slug);
    R::setUserStatus($s['tenant'], $s['admin'], UserStatus::Activo);
    CursoTestHelpers::setStatus($s['tenant'], $s['source'], AcademicYearStatus::Cerrado);
    $s['rollover'] = R::rollover($s['tenant'], $s['target'], $s['source'], YearRolloverStatus::Pendiente, $s['admin'], ['acad.education_levels', 'acad.academic_periods', 'curso.alpha']);

    return $s;
}

test('CA-CURSO-132 RN-CURSO-70 RN-CURSO-71: con la copia detenida dentro de copy() (FOR SHARE del destino tomado), la activación del destino espera a que confirme y después procede', function (): void {
    $s = rolloverReadyScenario('rcc-132a');
    $key = 7_132_001;
    $gate = rolloverHoldGate($key);

    $copy = rolloverSpawn('rollover-worker.php', $s['tenant']->id, 'run', (string) $s['rollover']->id, (string) $key, '0');
    rolloverWaitBlocked('advisory', 1, [$copy]);

    $activation = rolloverSpawn('curso-worker.php', $s['tenant']->id, 'activate', $s['target']->public_id);
    // La activación pide FOR UPDATE sobre la fila del curso y espera al FOR SHARE de la copia (RN-CURSO-70).
    rolloverWaitBlocked(null, 2, [$copy, $activation]);

    rolloverReleaseGate($gate, $key);
    $copyResult = rolloverCollect($copy);
    $activationResult = rolloverCollect($activation);

    expect($copyResult)->toMatchArray(['outcome' => 'ok', 'status' => 'completado'])
        ->and($activationResult['outcome'])->toBe('ok')
        ->and(app(TenantContext::class)->runFor($s['tenant']->id, fn () => AcademicYear::query()->findOrFail($s['target']->id)->status))->toBe(AcademicYearStatus::Activo);
});

test('CA-CURSO-132 RN-CURSO-70 RN-CURSO-72 RN-ACAD-62: un PATCH de fechas del destino que dejaría fuera un período copiado espera a la copia y después responde 409 date_change_checks_failed', function (): void {
    $s = rolloverReadyScenario('rcc-132b');
    $key = 7_132_002;
    $gate = rolloverHoldGate($key);

    $copy = rolloverSpawn('rollover-worker.php', $s['tenant']->id, 'run', (string) $s['rollover']->id, (string) $key, '0');
    rolloverWaitBlocked('advisory', 1, [$copy]);

    // Acorta el destino a 2028-03-01: los períodos copiados acaban en 2028-06-21.
    $patch = rolloverSpawn('acad-worker.php', $s['tenant']->id, 'patch-year', $s['target']->public_id, '-', '2028-03-01', '-');
    rolloverWaitBlocked(null, 2, [$copy, $patch]);

    rolloverReleaseGate($gate, $key);

    expect(rolloverCollect($copy))->toMatchArray(['outcome' => 'ok', 'status' => 'completado']);

    $patchResult = rolloverCollect($patch);

    expect($patchResult)->toMatchArray(['outcome' => 'api_error', 'status' => 409])
        ->and($patchResult['code'])->toBe('acad.conflict.periods_outside_academic_year');
});

test('CA-CURSO-132 RN-CURSO-69: una edición del origen confirmada mientras la copia está en curso no entra: lo copiado es la foto del origen al empezar la transacción (REPEATABLE READ), nunca una mezcla', function (): void {
    $s = rolloverReadyScenario('rcc-132c');
    $key = 7_132_003;
    $gate = rolloverHoldGate($key);

    // El participante de prueba va ANTES que `acad`: `acad` lee el origen después de la espera.
    $copy = rolloverSpawn('rollover-worker.php', $s['tenant']->id, 'run', (string) $s['rollover']->id, (string) $key, '1');
    rolloverWaitBlocked('advisory', 1, [$copy]);

    // El proceso 2 (este test) edita y confirma el origen — el curso está cerrado: la tabla es del propietario.
    $edited = DB::connection('pgsql_owner');
    $edited->statement("select set_config('app.tenant_id', ?, false)", [(string) $s['tenant']->id]);
    $edited->table('education_levels')->where('academic_year_id', $s['source']->id)->where('code', 'ESO')->update(['name_translations' => json_encode(['es-ES' => 'Editada durante la copia'])]);
    $edited->table('academic_periods')->where('academic_year_id', $s['source']->id)->update(['deleted_at' => now()]);
    $edited->statement("select set_config('app.tenant_id', '', false)");

    rolloverReleaseGate($gate, $key);
    $result = rolloverCollect($copy);

    expect($result)->toMatchArray(['outcome' => 'ok', 'status' => 'completado']);

    app(TenantContext::class)->runFor($s['tenant']->id, function () use ($s): void {
        // La foto: el nombre original y los tres períodos (el borrado confirmado después no entra).
        expect(EducationLevel::query()->where('academic_year_id', $s['target']->id)->where('code', 'ESO')->firstOrFail()->name_translations)->toBe(['es-ES' => 'Etapa ESO'])
            ->and(AcademicPeriod::query()->where('academic_year_id', $s['target']->id)->count())->toBe(3);
    });
});

/** Un QueryException de PostgreSQL con el SQLSTATE dado. */
function rolloverSqlState(string $state): QueryException
{
    $pdo = new PDOException('simulado');
    $pdo->errorInfo = [$state, 7, 'simulado'];

    return new QueryException('pgsql', 'select 1', [], $pdo);
}

test('CA-CURSO-158 RN-CURSO-86: el ayudante exige no tener transacción abierta, fija REPEATABLE READ como primera sentencia, repite ante 40001 hasta 3 intentos y después propaga el error', function (): void {
    config(['curso.rollover.allow_nested_transaction' => false]);
    $helper = app(RepeatableReadTransaction::class);

    // Con una transacción ya abierta: LogicException y ninguna consulta.
    DB::beginTransaction();
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        expect(fn () => $helper->run(fn () => 'nunca'))->toThrow(LogicException::class);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::rollBack();
    }

    // Sin transacción: el aislamiento dentro de la función es repeatable read.
    expect($helper->run(fn () => DB::selectOne('show transaction_isolation')->transaction_isolation))->toBe('repeatable read');

    // 40001 dos veces y luego termina: tres ejecuciones y confirma.
    $calls = 0;
    $value = $helper->run(function () use (&$calls): string {
        $calls++;

        if ($calls < 3) {
            throw rolloverSqlState('40001');
        }

        return 'hecho';
    });

    expect($value)->toBe('hecho')->and($calls)->toBe(3);

    // 40001 siempre: tres ejecuciones y propaga el error.
    $calls = 0;

    expect(function () use ($helper, &$calls): void {
        $helper->run(function () use (&$calls): void {
            $calls++;

            throw rolloverSqlState('40001');
        });
    })->toThrow(QueryException::class)
        ->and($calls)->toBe(3);

    // Otro error no se reintenta.
    $calls = 0;

    expect(function () use ($helper, &$calls): void {
        $helper->run(function () use (&$calls): void {
            $calls++;

            throw rolloverSqlState('23505');
        });
    })->toThrow(QueryException::class)
        ->and($calls)->toBe(1);
});
