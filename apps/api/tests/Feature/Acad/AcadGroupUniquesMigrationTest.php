<?php

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// REQ-ACAD-002 (1.12-a), issue #446 (db-reviewer a y b): la migración
// `2026_10_18_100100_add_group_uniques_for_teaching_assignments` busca su
// restricción por nombre Y por tabla, y hace el DDL con `lock_timeout` y un
// reintento acotado. Se prueba con base de datos real y una segunda sesión que
// retiene un bloqueo de verdad. INV-015.

function groupUniquesMigration(): object
{
    return require base_path('app/Modules/Acad/Database/migrations/2026_10_18_100100_add_group_uniques_for_teaching_assignments.php');
}

/** @param  array<string, mixed>  $props */
function tune(object $migration, array $props): void
{
    foreach ($props as $name => $value) {
        $property = new ReflectionProperty($migration, $name);
        $property->setValue($migration, $value);
    }
}

function callPrivate(object $migration, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($migration, $method))->invoke($migration, ...$args);
}

function ownerConnection(): Connection
{
    return DB::connection('pgsql_owner');
}

it('CA-ACAD-108 (#446-b): la idempotencia busca la restricción por tabla, no solo por nombre', function (): void {
    $owner = ownerConnection();
    $migration = groupUniquesMigration();

    // El nombre existe en `group_subjects` y en `subgroups`, y solo en ellas.
    expect(callPrivate($migration, 'constraintExists', $owner, 'group_subjects', 'group_subjects_tenant_group_id_unique'))->toBeTrue()
        ->and(callPrivate($migration, 'constraintExists', $owner, 'subgroups', 'subgroups_tenant_group_id_unique'))->toBeTrue()
        // Mismo nombre, otra tabla: antes la comprobación por `conname` a secas lo daba por aplicado.
        ->and(callPrivate($migration, 'constraintExists', $owner, 'subgroups', 'group_subjects_tenant_group_id_unique'))->toBeFalse()
        ->and(callPrivate($migration, 'constraintExists', $owner, 'facilities', 'subgroups_tenant_group_id_unique'))->toBeFalse();

    // Un segundo `up()` con todo aplicado no hace nada ni falla.
    $migration->up();

    expect(callPrivate($migration, 'constraintExists', $owner, 'subgroups', 'subgroups_tenant_group_id_unique'))->toBeTrue();
});

it('CA-ACAD-108 (#446-a): reintenta con lock_timeout hasta que el bloqueo ajeno se libera y restablece lock_timeout', function (): void {
    $owner = ownerConnection();
    $migration = groupUniquesMigration();
    tune($migration, ['lockTimeout' => '300ms', 'retryPauseMs' => 100, 'maxAttempts' => 20]);

    $holder = holdAccessExclusiveLock('facilities', seconds: 2);

    $attempts = 0;

    try {
        callPrivate($migration, 'withLockTimeout', $owner, function () use ($owner, &$attempts): void {
            $attempts++;
            // Pide el mismo bloqueo que retiene la otra sesión: vence por `lock_timeout` hasta que ésta confirma.
            $owner->statement('DO $$ BEGIN LOCK TABLE facilities IN ACCESS EXCLUSIVE MODE; END $$');
        });
    } finally {
        releaseHolder($holder);
    }

    expect($attempts)->toBeGreaterThan(1)
        ->and($owner->selectOne('SHOW lock_timeout')->lock_timeout)->toBe('0');
});

it('CA-ACAD-108 (#446-a): agotados los intentos propaga el error de bloqueo, y lock_timeout queda restablecido', function (): void {
    $owner = ownerConnection();
    $migration = groupUniquesMigration();
    tune($migration, ['lockTimeout' => '200ms', 'retryPauseMs' => 10, 'maxAttempts' => 2]);

    $holder = holdAccessExclusiveLock('facilities', seconds: 3);
    $attempts = 0;
    $state = null;

    try {
        callPrivate($migration, 'withLockTimeout', $owner, function () use ($owner, &$attempts): void {
            $attempts++;
            $owner->statement('DO $$ BEGIN LOCK TABLE facilities IN ACCESS EXCLUSIVE MODE; END $$');
        });
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    } finally {
        releaseHolder($holder);
    }

    expect($state)->toBe('55P03')
        ->and($attempts)->toBe(2)
        ->and($owner->selectOne('SHOW lock_timeout')->lock_timeout)->toBe('0');
});

it('CA-ACAD-108 (#446-a): un error que no es de bloqueo no se reintenta', function (): void {
    $owner = ownerConnection();
    $migration = groupUniquesMigration();
    tune($migration, ['retryPauseMs' => 10]);

    $attempts = 0;
    $state = null;

    try {
        callPrivate($migration, 'withLockTimeout', $owner, function () use ($owner, &$attempts): void {
            $attempts++;
            $owner->statement('SELECT 1/0');
        });
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('22012')->and($attempts)->toBe(1);
});

/**
 * Lanza un proceso PHP que abre otra sesión, toma ACCESS EXCLUSIVE sobre la tabla y la retiene unos segundos antes de
 * confirmar. Espera a que confirme por la salida estándar que ya tiene el bloqueo.
 *
 * @return array{process: resource, pipes: array<int, resource>}
 */
function holdAccessExclusiveLock(string $table, int $seconds): array
{
    $cfg = config('database.connections.pgsql_owner');
    $code = <<<'PHP'
        [$host, $port, $db, $user, $pass, $table, $seconds] = array_slice($argv, 1);
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        $pdo->exec("LOCK TABLE $table IN ACCESS EXCLUSIVE MODE");
        fwrite(STDOUT, "locked\n");
        fflush(STDOUT);
        sleep((int) $seconds);
        $pdo->commit();
        PHP;

    $process = proc_open(
        [PHP_BINARY, '-r', $code, '--', (string) $cfg['host'], (string) $cfg['port'], (string) $cfg['database'], (string) $cfg['username'], (string) $cfg['password'], $table, (string) $seconds],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    expect(trim((string) fgets($pipes[1])))->toBe('locked');

    return ['process' => $process, 'pipes' => $pipes];
}

/** @param  array{process: resource, pipes: array<int, resource>}  $holder */
function releaseHolder(array $holder): void
{
    foreach ($holder['pipes'] as $pipe) {
        fclose($pipe);
    }

    proc_close($holder['process']);
}
