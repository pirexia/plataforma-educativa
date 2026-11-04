<?php

use App\Models\Person;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\AuthorizedPickupList;
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

// ─── 1.14b-d1 T2: recomprobación con bloqueo (RN-PRL-12 a -16; CA-PRL-026 a -030) ───

/** Una conexión del propietario con una transacción abierta y el centro fijado (la tabla fuerza RLS). */
function prlHolder(int $tenantId): PDO
{
    $pdo = prlPdo();
    $pdo->beginTransaction();
    $pdo->prepare("select set_config('app.tenant_id', ?, true)")->execute([(string) $tenantId]);

    return $pdo;
}

/** Espera a que haya al menos una sesión esperando un bloqueo de fila (`pg_locks`, no concedido: `transactionid` o `tuple`). */
function prlWaitLockWaiter(array $children = []): void
{
    $pdo = prlPdo();
    $deadline = microtime(true) + 60;
    $statement = $pdo->prepare("select count(*) from pg_locks where not granted and locktype in ('transactionid', 'tuple')");

    while (true) {
        $statement->execute();

        if ((int) $statement->fetchColumn() >= 1) {
            return;
        }

        foreach ($children as $i => $child) {
            if (! proc_get_status($child['process'])['running']) {
                throw new RuntimeException("El proceso hijo {$i} terminó sin esperar ningún bloqueo: ".file_get_contents($child['output']));
            }
        }

        if (microtime(true) > $deadline) {
            throw new RuntimeException('Ninguna sesión esperó un bloqueo en 60 s.');
        }

        usleep(20_000);
    }
}

/** @return array<string, int> filas de las dos tablas de `prl` del centro */
function prlCounts(object $tenant): array
{
    return app(TenantContext::class)->runFor($tenant->id, fn () => [
        'movements' => DB::table('student_movements')->count(),
        'alerts' => DB::table('pickup_alerts')->count(),
    ]);
}

test('CA-PRL-026 RN-PRL-12 RN-PRL-13: con la revocación de (P, A) sin confirmar, la salida de A con P espera; al confirmar responde 422 person_not_authorized y no hay fila en student_movements', function (): void {
    $f = Prl::doorFamily('prl-conc-026');
    $holder = prlHolder($f['tenant']->id);
    $holder->prepare("update guardian_student_links set status = 'revocado', revocation_reason = 'resolucion_judicial', revoked_at = now(), revoked_by = ?, is_emergency_contact = false where public_id = ?")->execute([$f['admin']->id, $f['lo']]);
    $padre = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['other']->person_id)->public_id);

    $child = prlSpawn($f['tenant']->id, 'record-exit', $f['conserje']->public_id, $f['student']->public_id, $padre, '-');

    try {
        prlWaitLockWaiter([$child]);
        // Mientras B no confirma, A sigue esperando: no ha respondido.
        expect(proc_get_status($child['process'])['running'])->toBeTrue();
    } finally {
        $holder->commit();
    }

    $result = prlCollect($child);

    expect($result)->toMatchArray(['outcome' => 'api_error', 'status' => 422, 'code' => 'prl.movement.person_not_authorized'])
        ->and(prlCounts($f['tenant'])['movements'])->toBe(0);
});

test('CA-PRL-030 RN-PRL-16 OPEN-PRL-04: con la revocación sin confirmar más allá de lock_timeout_ms, la salida responde 503 prl.errors.busy en menos de lock_timeout_ms + 1 s sin escribir nada; con la misma Idempotency-Key, liberado el bloqueo, se procesa como petición nueva', function (): void {
    $f = Prl::doorFamily('prl-conc-030');
    $holder = prlHolder($f['tenant']->id);
    $holder->prepare('update pickup_authorizations set withdrawn_at = now(), withdrawn_by = ? where public_id = ?')->execute([$f['admin']->id, $f['abuelo']['authorization']]);
    $args = [$f['conserje']->public_id, $f['student']->public_id, $f['abuelo']['person'], 'reintento-puerta'];

    $child = prlSpawn($f['tenant']->id, 'record-exit', ...$args);

    try {
        prlWaitLockWaiter([$child]);
        $started = microtime(true);
        $busy = prlCollect($child);
        $waited = microtime(true) - $started;
    } finally {
        $holder->rollBack();
    }

    expect($busy)->toMatchArray(['outcome' => 'api_error', 'status' => 503, 'code' => 'prl.errors.busy'])
        ->and($waited)->toBeLessThan(config('prl.lock_timeout_ms') / 1000 + 1)
        ->and(prlCounts($f['tenant'])['movements'])->toBe(0);

    // Sin resultado original: la repetición con la misma clave y el mismo cuerpo es una petición nueva.
    $retry = prlCollect(prlSpawn($f['tenant']->id, 'record-exit', ...$args));

    expect($retry)->toMatchArray(['outcome' => 'ok', 'replayed' => false])
        ->and(prlCounts($f['tenant'])['movements'])->toBe(1);
});

test('CA-PRL-028 RN-PRL-12 RN-PRL-13 OPEN-FAM-UNIT-51: con el borrado lógico de la persona del alumno sin confirmar, POST /pickup-alerts espera y responde 404 sin fila, y la salida responde 422 student_not_found sin fila', function (): void {
    $f = Prl::doorFamily('prl-conc-028');

    foreach (['raise-alert' => [[$f['conserje']->public_id, $f['student']->public_id, '-'], 404, null], 'record-exit' => [[$f['conserje']->public_id, $f['student']->public_id, $f['abuelo']['person'], '-'], 422, 'prl.validation.student_not_found']] as $operation => [$args, $status, $code]) {
        $holder = prlHolder($f['tenant']->id);
        $holder->prepare('update people set deleted_at = now() where public_id = ?')->execute([$f['student']->public_id]);
        $child = prlSpawn($f['tenant']->id, $operation, ...$args);

        try {
            prlWaitLockWaiter([$child]);
            expect(proc_get_status($child['process'])['running'])->toBeTrue();
        } finally {
            $holder->rollBack();
        }

        // Ronda de control: B revierte, A ve al alumno vivo y registra.
        $result = prlCollect($child);
        expect($result['outcome'])->toBe('ok', "{$operation}: tras revertir B, el alumno sigue vivo y la operación se registra");

        // Ahora la carrera de verdad: B confirma el borrado mientras A espera.
        $holder = prlHolder($f['tenant']->id);
        $holder->prepare('update people set deleted_at = now() where public_id = ?')->execute([$f['student']->public_id]);
        $child = prlSpawn($f['tenant']->id, $operation, ...$args);

        try {
            prlWaitLockWaiter([$child]);
        } finally {
            $holder->commit();
        }

        $result = prlCollect($child);
        expect($result)->toMatchArray(['outcome' => 'api_error', 'status' => $status, 'code' => $code]);

        // Restaura al alumno para la siguiente vuelta y deja contado lo escrito: solo la fila de la ronda de control.
        $restore = prlHolder($f['tenant']->id);
        $restore->exec('update people set deleted_at = null where deleted_at is not null');
        $restore->commit();
    }

    // Cada operación dejó una sola fila: la de la ronda en la que el borrado se revirtió.
    expect(prlCounts($f['tenant']))->toBe(['movements' => 1, 'alerts' => 1]);
});

test('CA-PRL-027 RN-PRL-12 RN-PRL-15: con la salida de T registrada y sin confirmar, retirar la autorización, dar de baja la autorización o la fotografía de T o borrar lógicamente a T espera (visible en pg_locks) hasta que A confirma; el resultado es la salida y después el cambio', function (): void {
    // El SQL de cada cambio de B (los ids son del propio test, no de ninguna entrada externa).
    $changes = [
        'retirada de la autorización' => fn (array $f): string => sprintf("update pickup_authorizations set withdrawn_at = now(), withdrawn_by = %d where public_id = '%s'", $f['admin']->id, $f['abuelo']['authorization']),
        'baja lógica de la autorización' => fn (array $f): string => sprintf("update pickup_authorizations set deleted_at = now() where public_id = '%s'", $f['abuelo']['authorization']),
        'baja de la fotografía' => fn (array $f): string => sprintf("update pickup_photos set deleted_at = now() where deleted_at is null and person_id = (select id from people where public_id = '%s')", $f['abuelo']['person']),
        'borrado lógico de la persona' => fn (array $f): string => sprintf("update people set deleted_at = now() where public_id = '%s'", $f['abuelo']['person']),
    ];

    foreach (array_keys($changes) as $i => $name) {
        $f = Prl::doorFamily("prl-conc-027-{$i}");
        $signal = tempnam(sys_get_temp_dir(), 'prl-sig-');
        @unlink($signal);
        $a = prlSpawn($f['tenant']->id, 'record-exit-hold', $f['conserje']->public_id, $f['student']->public_id, $f['abuelo']['person'], $signal, '6');
        $deadline = microtime(true) + 60;

        while (! file_exists($signal)) {
            if (microtime(true) > $deadline || ! proc_get_status($a['process'])['running']) {
                throw new RuntimeException("«{$name}»: A no llegó a bloquear: ".file_get_contents($a['output']));
            }

            usleep(20_000);
        }

        // B, en su propia conexión asíncrona: debe quedar esperando mientras A no confirma.
        $cfg = config('database.connections.pgsql_owner');
        $b = pg_connect("host={$cfg['host']} port={$cfg['port']} dbname={$cfg['database']} user={$cfg['username']} password={$cfg['password']}");
        pg_query($b, "select set_config('app.tenant_id', '{$f['tenant']->id}', false)");
        expect($b)->not->toBeFalse();
        pg_send_query($b, $changes[$name]($f));

        prlWaitLockWaiter([$a]);
        // Mientras A no confirma: B sin terminar y la salida invisible desde fuera.
        expect(pg_connection_busy($b))->toBeTrue("{$name}: B no esperó");
        $before = prlCounts($f['tenant']);

        $resultA = prlCollect($a);
        $resultB = pg_get_result($b);

        expect($resultA['outcome'])->toBe('ok')
            ->and(pg_result_error($resultB))->toBe('', "{$name}: B falló")
            ->and(pg_affected_rows($resultB))->toBeGreaterThanOrEqual(1)
            ->and($before['movements'])->toBe(0)
            ->and(prlCounts($f['tenant'])['movements'])->toBe(1, "{$name}: la salida debe quedar registrada");

        pg_close($b);
    }
});

test('CA-PRL-029 RN-PRL-14 RN-PRL-15: fuera de una transacción abierta, lockLiveByPublicIds y grantForLocked lanzan LogicException (fallo en cerrado)', function (): void {
    $f = Prl::doorFamily('prl-conc-029');

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        expect(DB::transactionLevel())->toBe(0);
        expect(fn () => app(PersonDirectory::class)->lockLiveByPublicIds([$f['student']->public_id]))->toThrow(LogicException::class)
            ->and(fn () => app(AuthorizedPickupList::class)->grantForLocked($f['student']->id, $f['student']->id))->toThrow(LogicException::class);
    });
});
