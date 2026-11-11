<?php

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// REQ-ALUM-003 (1.15-c, issue #602), datos.md §1.3 y §3, INV-015: la migración
// `2026_11_11_100100_add_enrollments_tenant_year_id_unique` es idempotente, reintenta por `lock_timeout` (55P03),
// descarta un índice inválido de un intento anterior y reutiliza uno válido. Espejo de `AcadGroupUniquesMigrationTest`,
// con base de datos real y una segunda sesión que retiene un bloqueo de verdad.

const ENR_TABLE = 'enrollments';
const ENR_NAME = 'enrollments_tenant_year_id_unique';
const ENR_FK = 'group_change_requests_tenant_year_enrollment_foreign';

function enrMigration(): object
{
    return require base_path('app/Modules/Alum/Database/migrations/2026_11_11_100100_add_enrollments_tenant_year_id_unique.php');
}

/** @param  array<string, mixed>  $props */
function enrTune(object $migration, array $props): void
{
    foreach ($props as $name => $value) {
        (new ReflectionProperty($migration, $name))->setValue($migration, $value);
    }
}

function enrCall(object $migration, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($migration, $method))->invoke($migration, ...$args);
}

function enrOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function enrConstraintExists(): bool
{
    return enrOwner()->selectOne('SELECT 1 AS ok FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [ENR_NAME, ENR_TABLE]) !== null;
}

/** @return object{indisvalid: bool}|null */
function enrIndexState(): ?object
{
    return enrOwner()->selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND i.indrelid = ?::regclass', [ENR_NAME, ENR_TABLE]);
}

/** @return array{process: resource, pipes: array<int, resource>} */
function enrHoldLock(int $seconds, string $sql = 'LOCK TABLE facilities IN ACCESS EXCLUSIVE MODE'): array
{
    $cfg = config('database.connections.pgsql_owner');
    $code = <<<'PHP'
        [$host, $port, $db, $user, $pass, $sql, $seconds] = array_slice($argv, 1);
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        $pdo->exec($sql);
        fwrite(STDOUT, "locked\n");
        fflush(STDOUT);
        sleep((int) $seconds);
        $pdo->commit();
        PHP;

    $process = proc_open(
        [PHP_BINARY, '-r', $code, '--', (string) $cfg['host'], (string) $cfg['port'], (string) $cfg['database'], (string) $cfg['username'], (string) $cfg['password'], $sql, (string) $seconds],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect(trim((string) fgets($pipes[1])))->toBe('locked');

    return ['process' => $process, 'pipes' => $pipes];
}

/** @param  array{process: resource, pipes: array<int, resource>}  $holder */
function enrRelease(array $holder): void
{
    foreach ($holder['pipes'] as $pipe) {
        fclose($pipe);
    }

    proc_close($holder['process']);
}

/**
 * La FK compuesta de `group_change_requests` depende de la restricción: para poder retirarla en el test se suelta antes y
 * se restituye en `afterEach` con su definición original.
 */
function enrDetachFk(): void
{
    $owner = enrOwner();
    $def = $owner->selectOne('SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [ENR_FK, 'group_change_requests']);
    $GLOBALS['enr_fk_def'] ??= $def?->d;
    $owner->statement('ALTER TABLE group_change_requests DROP CONSTRAINT IF EXISTS '.ENR_FK);
}

afterEach(function (): void {
    // Deja el esquema como lo necesitan el resto de tests: restricción y FK presentes.
    $owner = enrOwner();

    if (! enrConstraintExists()) {
        $owner->statement('DROP INDEX IF EXISTS '.ENR_NAME);
        enrMigration()->up();
    }

    $hasFk = $owner->selectOne('SELECT 1 AS ok FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [ENR_FK, 'group_change_requests']) !== null;

    if (! $hasFk && isset($GLOBALS['enr_fk_def'])) {
        $owner->statement('ALTER TABLE group_change_requests ADD CONSTRAINT '.ENR_FK.' '.$GLOBALS['enr_fk_def']);
    }
});

it('CA-ALUM-018c RN-ALUM-47: la restricción existe, es idempotente (un segundo up() no hace nada) y el homónimo en otra tabla no cuenta', function (): void {
    $migration = enrMigration();

    expect(enrConstraintExists())->toBeTrue();

    $migration->up();

    expect(enrConstraintExists())->toBeTrue()
        ->and(enrOwner()->selectOne('SELECT 1 AS ok FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [ENR_NAME, 'facilities']))->toBeNull();
});

it('CA-ALUM-018c: down() falla mientras group_change_requests depende de la restricción; sin esa dependencia la retira y es repetible, y up() la crea', function (): void {
    $migration = enrMigration();

    expect(fn () => $migration->down())->toThrow(QueryException::class);
    expect(enrConstraintExists())->toBeTrue();

    enrDetachFk();
    $migration->down();
    expect(enrConstraintExists())->toBeFalse();
    $migration->down();

    $migration->up();
    expect(enrConstraintExists())->toBeTrue()
        ->and(enrIndexState()?->indisvalid)->toBeTrue();
});

it('CA-ALUM-018c: un índice válido de un intento anterior se reutiliza (no se recrea) y se promueve a restricción', function (): void {
    $owner = enrOwner();
    $migration = enrMigration();
    enrDetachFk();
    $migration->down();
    $owner->statement('CREATE UNIQUE INDEX '.ENR_NAME.' ON '.ENR_TABLE.' (tenant_id, academic_year_id, id)');
    $before = $owner->selectOne('SELECT c.oid::int AS oid FROM pg_class c WHERE c.relname = ?', [ENR_NAME])->oid;

    $migration->up();

    $after = $owner->selectOne('SELECT c.oid::int AS oid FROM pg_class c WHERE c.relname = ?', [ENR_NAME])->oid;
    expect(enrConstraintExists())->toBeTrue()
        ->and($after)->toBe($before);
});

it('CA-ALUM-018c: un índice inválido de un intento anterior se descarta y se recrea válido', function (): void {
    $owner = enrOwner();
    $migration = enrMigration();
    enrDetachFk();
    $migration->down();
    // Un `CREATE INDEX CONCURRENTLY` cortado a mitad deja un índice inválido, como un corte real: otra sesión con una
    // instantánea antigua lo hace esperar hasta que vence `statement_timeout`.
    $holder = enrHoldLock(3, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ; SELECT count(*) FROM '.ENR_TABLE);

    try {
        $owner->statement("SET statement_timeout = '500ms'");
        $owner->statement('CREATE UNIQUE INDEX CONCURRENTLY '.ENR_NAME.' ON '.ENR_TABLE.' (tenant_id, academic_year_id, id)');
    } catch (QueryException) {
        // esperado: cancelado por tiempo
    } finally {
        $owner->statement('RESET statement_timeout');
        enrRelease($holder);
    }

    expect(enrIndexState()?->indisvalid)->toBeFalse();

    $migration->up();

    expect(enrConstraintExists())->toBeTrue()
        ->and(enrIndexState()?->indisvalid)->toBeTrue();
});

it('CA-ALUM-018c: reintenta con lock_timeout hasta que el bloqueo ajeno se libera y restablece lock_timeout', function (): void {
    $owner = enrOwner();
    $migration = enrMigration();
    enrTune($migration, ['lockTimeout' => '300ms', 'retryPauseMs' => 100, 'maxAttempts' => 20]);
    $holder = enrHoldLock(2);
    $attempts = 0;

    try {
        enrCall($migration, 'withLockTimeout', $owner, function () use ($owner, &$attempts): void {
            $attempts++;
            $owner->statement('DO $$ BEGIN LOCK TABLE facilities IN ACCESS EXCLUSIVE MODE; END $$');
        });
    } finally {
        enrRelease($holder);
    }

    expect($attempts)->toBeGreaterThan(1)
        ->and($owner->selectOne('SHOW lock_timeout')->lock_timeout)->toBe('0');
});

it('CA-ALUM-018c: agotados los intentos propaga 55P03 y lock_timeout queda restablecido; un error ajeno no se reintenta', function (): void {
    $owner = enrOwner();
    $migration = enrMigration();
    enrTune($migration, ['lockTimeout' => '200ms', 'retryPauseMs' => 10, 'maxAttempts' => 2]);
    $holder = enrHoldLock(3);
    $attempts = 0;
    $state = null;

    try {
        enrCall($migration, 'withLockTimeout', $owner, function () use ($owner, &$attempts): void {
            $attempts++;
            $owner->statement('DO $$ BEGIN LOCK TABLE facilities IN ACCESS EXCLUSIVE MODE; END $$');
        });
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    } finally {
        enrRelease($holder);
    }

    expect($state)->toBe('55P03')->and($attempts)->toBe(2)
        ->and($owner->selectOne('SHOW lock_timeout')->lock_timeout)->toBe('0');

    $attempts = 0;
    $state = null;

    try {
        enrCall($migration, 'withLockTimeout', $owner, function () use ($owner, &$attempts): void {
            $attempts++;
            $owner->statement('SELECT 1/0');
        });
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('22012')->and($attempts)->toBe(1);
});
