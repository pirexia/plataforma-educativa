<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\AlumEnrollmentHelpers as En;

/**
 * Concurrencia REAL de REQ-ALUM-002 (1.15-b T2b): `CA-ALUM-022` (`RN-ALUM-21`) y `CA-ALUM-030` (`funcional.md §4.2`). Dos
 * procesos PHP distintos, cada uno con su propia conexión y su propia transacción, confirmados de verdad
 * (`ConcurrentTestCase`). Mismo método que `AcadConcurrencyTest` y `CursoConcurrencyTest`: un bloqueo retenido por el test
 * deja a los hijos esperando en el punto exacto de la carrera.
 */
afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');

    foreach (DB::connection('pgsql_platform')->table('tenants')->pluck('id') as $tenantId) {
        $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
        // El propietario está exento del bloqueo de cursos cerrados (ADR-057 §5.2).
        foreach (['enrollments', 'students', 'subgroups', 'group_subjects', 'groups', 'subjects', 'courses', 'education_levels'] as $table) {
            $owner->table($table)->delete();
        }
    }

    $owner->statement("select set_config('app.tenant_id', '', false)");
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function alumPdo(): PDO
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
function alumSpawn(int $tenantId, string $operation, string ...$args): array
{
    $output = tempnam(sys_get_temp_dir(), 'alum-');
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/alum-worker.php', (string) $tenantId, $operation, ...$args],
        [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
        $pipes,
    );
    fclose($pipes[0]);

    return ['process' => $process, 'output' => $output];
}

/**
 * Espera (acotada a 60 s) a que haya exactamente `$count` peticiones de bloqueo sin conceder del tipo `$waitEvent`
 * (`advisory`, `relation`, `transactionid`, `tuple`), o de cualquiera de ellos si es `null`.
 *
 * @param  list<array{process: resource, output: string}>  $children
 */
function alumWaitBlocked(?string $waitEvent, int $count, array $children): void
{
    $pdo = alumPdo();
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
function alumCollect(array $child): array
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

test('CA-ALUM-022 RN-ALUM-21: dos matrículas simultáneas del mismo alumno en el mismo curso: exactamente una ok y la otra 422 already_enrolled, nunca un 500; una sola fila', function (): void {
    $s = En::scenario('alum-022c');
    $student = $s['students'][0]['public_id'];

    $holder = alumPdo();
    $holder->beginTransaction();
    // Mientras se retiene SHARE ROW EXCLUSIVE, ambos hijos pasan la comprobación previa (un SELECT) y se quedan esperando
    // para INSERTAR: la carrera real sobre el índice único parcial (`RN-ALUM-21`), determinista.
    $holder->exec('lock table enrollments in share row exclusive mode');

    $a = alumSpawn($s['tenant']->id, 'enroll', $student, $s['year']->public_id, $s['api']['course'], '2026-09-10');
    $b = alumSpawn($s['tenant']->id, 'enroll', $student, $s['year']->public_id, $s['api']['course'], '2026-09-11');

    try {
        alumWaitBlocked('relation', 2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    $results = [alumCollect($a), alumCollect($b)];
    $ok = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error'));

    expect($ok)->toHaveCount(1)
        ->and($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['status'])->toBe(422)
        ->and($conflicts[0]['code'])->toBe('alum.validation.already_enrolled')
        ->and(En::liveCount($s['tenant']))->toBe(1);
});

test('CA-ALUM-030 funcional.md §4.2: un alumno borrado por otra conexión sin confirmar hace esperar a la matrícula; al confirmar, 422 student_not_found y ninguna fila', function (): void {
    $s = En::scenario('alum-030c');
    $student = $s['students'][0]['public_id'];
    $key = random_int(1_000, 2_000_000_000);

    $gate = alumPdo();
    $gate->prepare('select pg_advisory_lock(?)')->execute([$key]);

    // La baja ya borró la fila del alumno (la retiene bloqueada) y espera dentro de su transacción.
    $deleter = alumSpawn($s['tenant']->id, 'delete-student', $student, (string) $key);
    alumWaitBlocked('advisory', 1, [$deleter]);

    // La matrícula pide el alumno FOR SHARE y espera a que la baja confirme.
    $enroller = alumSpawn($s['tenant']->id, 'enroll', $student, $s['year']->public_id, $s['api']['course'], '2026-09-10');
    alumWaitBlocked('transactionid', 1, [$deleter, $enroller]);

    $gate->prepare('select pg_advisory_unlock(?)')->execute([$key]);

    expect(alumCollect($deleter))->toMatchArray(['outcome' => 'ok'])
        ->and(alumCollect($enroller))->toMatchArray(['outcome' => 'api_error', 'status' => 422, 'code' => 'alum.validation.student_not_found'])
        ->and(En::liveCount($s['tenant']))->toBe(0);
});
