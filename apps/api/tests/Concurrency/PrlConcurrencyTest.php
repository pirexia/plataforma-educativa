<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PrlTestHelpers as Prl;

/**
 * Concurrencia REAL de REQ-PRL (1.14b-b): `CA-PRL-005` (RN-PRL-09, `INV-011`: la misma `Idempotency-Key` de dos peticiones
 * simultáneas deja una sola fila, la que pierde la carrera relee la ganadora y nunca hay un 500) y el `down()` de las dos
 * migraciones (evidencia, `db-reviewer`). Dos procesos PHP distintos, cada uno con su propia conexión y su propia transacción,
 * confirmados de verdad (`ConcurrentTestCase`, sin transacción envolvente). Mismo método que `FamUnitConcurrencyTest`: un
 * bloqueo de tabla retenido por el test hace que ambos hijos pasen la comprobación previa (un `SELECT`) y se queden esperando
 * para insertar, de modo que la carrera sobre el índice único es determinista.
 *
 * `CA-PRL-003` (recomprobación dentro de la transacción) se prueba en `tests/Feature/Prl/StudentMovementsApiTest.php`: la
 * persona figuraba al abrir la lista y deja de figurar antes de registrar.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function prlPdo(): PDO
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
function prlSpawn(int $tenantId, string $operation, string ...$args): array
{
    $output = tempnam(sys_get_temp_dir(), 'prl-');
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/prl-worker.php', (string) $tenantId, $operation, ...$args],
        [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
        $pipes,
    );
    fclose($pipes[0]);

    return ['process' => $process, 'output' => $output];
}

/**
 * @param  list<array{process: resource, output: string}>  $children
 */
function prlWaitBlocked(int $count, array $children): void
{
    $pdo = prlPdo();
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
function prlCollect(array $child): array
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
function prlRace(object $tenant, string $table, array $first, array $second): array
{
    $holder = prlPdo();
    $holder->beginTransaction();
    $holder->exec("lock table {$table} in share row exclusive mode");

    $a = prlSpawn($tenant->id, $first[0], ...$first[1]);
    $b = prlSpawn($tenant->id, $second[0], ...$second[1]);

    try {
        prlWaitBlocked(2, [$a, $b]);
    } finally {
        $holder->rollBack();
    }

    return [prlCollect($a), prlCollect($b)];
}

test('CA-PRL-005 RN-PRL-09 INV-011: dos salidas simultáneas con la misma Idempotency-Key dejan una sola fila; una crea y la otra relee la original — los dos resultados apuntan al mismo movimiento, nunca un 500', function (): void {
    $f = Prl::doorFamily('prl-conc-005');
    // Los hijos son procesos distintos con el reloj real: la familia debe estar comprometida (sin transacción envolvente).
    $args = [$f['conserje']->public_id, $f['student']->public_id, $f['abuelo']['person'], 'toque-doble-tableta'];

    $results = prlRace($f['tenant'], 'student_movements', ['record-exit', $args], ['record-exit', $args]);

    expect(array_column($results, 'outcome'))->toBe(['ok', 'ok'])
        ->and($results[0]['public_id'])->toBe($results[1]['public_id'])
        ->and(collect($results)->where('replayed', true)->count())->toBe(1);

    expect(app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('student_movements')->count()))->toBe(1);
});

test('CA-PRL-005 RN-PRL-09: dos alertas simultáneas con la misma Idempotency-Key dejan una sola fila y los dos resultados apuntan a ella', function (): void {
    $f = Prl::doorFamily('prl-conc-alert');
    $args = [$f['conserje']->public_id, $f['student']->public_id, 'alerta-doble'];

    $results = prlRace($f['tenant'], 'pickup_alerts', ['raise-alert', $args], ['raise-alert', $args]);

    expect(array_column($results, 'outcome'))->toBe(['ok', 'ok'])
        ->and($results[0]['public_id'])->toBe($results[1]['public_id'])
        ->and(collect($results)->where('replayed', true)->count())->toBe(1)
        ->and(app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_alerts')->count()))->toBe(1);
});

test('datos.md §8.4 §8.5 (db-reviewer): el down() de student_movements y de pickup_alerts se niega a borrar la tabla si tiene filas (evidencia) y deja todo intacto', function (): void {
    $f = Prl::doorFamily('prl-conc-down');
    Prl::exit($f)->assertCreated();
    Prl::call($f['tenant'], $f['conserje'], 'post', '/pickup-alerts', ['student_person' => $f['student']->public_id, 'presenter_description' => 'x'])->assertCreated();

    foreach (['2026_11_02_100100_create_student_movements_table', '2026_11_02_100200_create_pickup_alerts_table'] as $name) {
        $migration = require app_path("Modules/Prl/Database/migrations/{$name}.php");

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'evidencia');
    }

    $count = fn (string $table) => app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table($table)->count());
    expect($count('student_movements'))->toBe(1)->and($count('pickup_alerts'))->toBe(1);
});
