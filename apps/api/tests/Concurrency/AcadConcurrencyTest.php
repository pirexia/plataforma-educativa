<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;

/**
 * Concurrencia REAL de REQ-ACAD (1.11-b): `CA-ACAD-004` (RN-ACAD-01). Dos
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
        foreach (['group_subjects', 'subgroups', 'groups', 'subjects', 'courses', 'education_levels'] as $table) {
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
