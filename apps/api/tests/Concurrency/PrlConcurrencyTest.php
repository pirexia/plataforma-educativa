<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

test('CA-PRL-034 datos.md §8.9: aplicar y revertir la migración sobre datos válidos no pierde ni cambia nada, es repetible, y down() deja el esquema como antes, incluido el GRANT DELETE', function (): void {
    $f = Prl::doorFamily('prlh-roundtrip');
    Prl::exit($f)->assertCreated();
    app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::connection('pgsql')->table('pickup_alerts')->insert([
        'tenant_id' => $f['tenant']->id, 'public_id' => (string) Str::ulid(), 'student_person_id' => $f['student']->id, 'kind' => 'persona_no_autorizada',
        'presenter_description' => 'Persona sin identificar', 'status' => 'cerrada', 'raised_by' => $f['conserje']->id, 'raised_at' => now(), 'resolution' => 'no_entregado',
        'resolved_at' => now(), 'resolved_by' => $f['direccion']->id, 'created_at' => now(), 'updated_at' => now(),
    ]));

    $owner = DB::connection('pgsql_owner');
    $snapshot = fn (): array => [
        'movements' => $owner->selectOne('SELECT count(*) AS n, md5(string_agg(m::text, \',\' ORDER BY id)) AS h FROM student_movements m')->h,
        'alerts' => $owner->selectOne('SELECT count(*) AS n, md5(string_agg(a::text, \',\' ORDER BY id)) AS h FROM pickup_alerts a')->h,
    ];
    $schema = fn (): array => [
        'trigger' => (bool) $owner->selectOne("SELECT 1 AS x FROM pg_trigger WHERE tgrelid = 'pickup_alerts'::regclass AND tgname = 'pickup_alerts_closed_immutable'"),
        'function' => (bool) $owner->selectOne("SELECT 1 AS x FROM pg_proc WHERE proname = 'assert_pickup_alert_not_closed'"),
        'constraints' => (int) $owner->selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conname IN ('student_movements_reason_requires_off_schedule_check', 'student_movements_corrects_same_student_foreign')")->n,
        'indexes' => (int) $owner->selectOne("SELECT count(*) AS n FROM pg_indexes WHERE indexname IN ('student_movements_tenant_student_id_unique', 'student_movements_tenant_student_corrects_idx')")->n,
        'delete' => (bool) $owner->selectOne("SELECT has_table_privilege('plataforma_app', 'pickup_alerts', 'DELETE') AS ok")->ok,
    ];

    $migration = require app_path('Modules/Prl/Database/migrations/2026_11_04_100100_harden_pickup_alerts_and_student_movements.php');
    $before = $snapshot();
    $hardened = $schema();

    expect($hardened)->toBe(['trigger' => true, 'function' => true, 'constraints' => 2, 'indexes' => 2, 'delete' => false]);

    try {
        $migration->down();

        expect($schema())->toBe(['trigger' => false, 'function' => false, 'constraints' => 0, 'indexes' => 0, 'delete' => true])
            ->and($snapshot())->toBe($before);

        // down() repetido no falla (IF EXISTS) y up() se puede reintentar.
        $migration->down();
        $migration->up();
        $migration->up();
    } finally {
        // Deja siempre el esquema endurecido para el resto de la suite.
        $migration->up();
    }

    expect($schema())->toBe($hardened)->and($snapshot())->toBe($before);
});

test('CA-PRL-032 RN-PRL-17 OPEN-PRL-05: con datos confirmados, ningún rol — aplicación, plataforma ni propietario — hace UPDATE de una alerta cerrada (cualquier columna, también deleted_at), y una abierta se cierra', function (): void {
    $f = Prl::doorFamily('prl-conc-closed');
    $tenantId = $f['tenant']->id;
    $base = [
        'tenant_id' => $tenantId, 'student_person_id' => $f['student']->id, 'kind' => 'persona_no_autorizada', 'presenter_description' => 'Persona sin identificar',
        'raised_by' => $f['conserje']->id, 'raised_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ];
    $closed = ['status' => 'cerrada', 'resolution' => 'no_entregado', 'resolved_at' => now(), 'resolved_by' => $f['direccion']->id];
    $ids = app(TenantContext::class)->runFor($tenantId, fn () => [
        DB::connection('pgsql')->table('pickup_alerts')->insertGetId($base + $closed + ['public_id' => (string) Str::ulid(), 'status' => 'cerrada']),
        DB::connection('pgsql')->table('pickup_alerts')->insertGetId($base + ['public_id' => (string) Str::ulid(), 'status' => 'abierta']),
        DB::connection('pgsql')->table('pickup_alerts')->insertGetId($base + ['public_id' => (string) Str::ulid(), 'status' => 'abierta']),
        DB::connection('pgsql')->table('pickup_alerts')->insertGetId($base + ['public_id' => (string) Str::ulid(), 'status' => 'abierta']),
    ]);

    $attempt = function (string $connection, Closure $work) use ($tenantId): ?string {
        $db = DB::connection($connection);

        try {
            $db->transaction(function () use ($db, $tenantId, $work): void {
                $db->selectOne("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenantId]);
                $work($db);
            });
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? 'sin-sqlstate';
        }

        return null;
    };

    foreach (['pgsql', 'pgsql_platform', 'pgsql_owner'] as $connection) {
        foreach ([['resolution_notes' => 'cambio'], ['deleted_at' => now()], ['status' => 'cerrada']] as $change) {
            expect($attempt($connection, fn ($db) => $db->table('pickup_alerts')->where('id', $ids[0])->update($change)))->toBe('23001', "{$connection} actualizó una alerta cerrada");
        }
    }

    // Una abierta se cierra con cada rol (ids 1, 2 y 3).
    foreach (['pgsql', 'pgsql_platform', 'pgsql_owner'] as $i => $connection) {
        expect($attempt($connection, fn ($db) => expect($db->table('pickup_alerts')->where('id', $ids[$i + 1])->update($closed))->toBe(1)))->toBeNull("{$connection} no pudo cerrar una alerta abierta");
    }

    expect(DB::connection('pgsql_platform')->table('pickup_alerts')->where('id', $ids[0])->value('resolution_notes'))->toBeNull();
});
