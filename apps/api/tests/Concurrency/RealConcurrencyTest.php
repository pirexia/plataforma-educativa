<?php

use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Application\AdministrationCapacityGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Concurrencia REAL de `RN-PERM-47` (`CA-PERM-049`) y `RN-CORE-07`
 * (issue #351; `RN-CORE-07` serializada en #349).
 *
 * A diferencia de `ConcurrentSnapshotTest` y `SchoolAdministratorSerializationTest`
 * (que inyectan «el cambio del otro» con un listener de consultas, dentro de una
 * sola conexión), aquí participan dos procesos PHP distintos, cada uno con su
 * propia conexión a PostgreSQL y su propia transacción, ambos confirmados de
 * verdad (`ConcurrentTestCase`, sin transacción envolvente).
 *
 * Sincronización sin `sleep` frágil, con señales de la propia base:
 *
 * 1. El test toma el bloqueo por tenant (`pg_advisory_xact_lock`, el mismo
 *    de `AdministrationCapacityGuard`) en una sesión propia.
 * 2. Lanza los dos procesos hijo. Cada uno pasa su comprobación previa (que
 *    ve a los dos titulares) y se queda esperando el bloqueo.
 * 3. El test espera, consultando `pg_locks`, a que haya exactamente dos
 *    peticiones de bloqueo SIN conceder para ese tenant (espera acotada a
 *    60 s; si un hijo muere antes se informa de su salida, no se cuelga).
 * 4. Libera el bloqueo: los dos hijos se ejecutan uno detrás de otro, y el
 *    segundo debe ver lo que el primero confirmó.
 *
 * La carpeta `tests/Concurrency` usa `ConcurrentTestCase` (sin transacción
 * envolvente; `tests/Pest.php`). El hijo es `tests/Concurrency/worker.php`: llama al mismo controlador o
 * servicio que la ruta, con el actor ya autenticado. No pasa por HTTP.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * @return array{0: object, 1: User, 2: User, 3: User, 4: User} tenant, actor, B, C, propietario aprovisionado
 */
function rc351Setup(string $slug, string $holdersRole): array
{
    [$tenant, $owner] = provisionCoreTenant($slug);
    $tc = app(TenantContext::class);

    $adminRole = $tc->runFor($tenant->id, fn () => Role::query()->where('code', 'administrador_centro')->firstOrFail());

    resetSessionState();
    $cloneId = test()->actingAs($owner)->postJson(coreApiUrl($tenant->slug, '/roles'), [
        'clone_from' => $adminRole->public_id, 'code' => "clon_{$slug}", 'name' => 'Clon admin',
    ])->assertCreated()->json('public_id');

    $make = function (string $local, string $roleId) use ($tenant, $owner, $tc): User {
        resetSessionState();
        test()->actingAs($owner)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "{$local}@example.com",
            'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
            'send_invitation' => false,
            'role_ids' => [$roleId],
        ])->assertCreated();

        return $tc->runFor($tenant->id, function () use ($local): User {
            $user = User::query()->where('email', "{$local}@example.com")->firstOrFail();
            $user->status = UserStatus::Activo;
            $user->save();

            return $user;
        });
    };

    // El actor tiene siempre la capacidad completa (rol clonado, no `administrador_centro`);
    // B y C llevan el rol que decide la regla probada.
    $holders = $holdersRole === 'admin' ? $adminRole->public_id : $cloneId;

    $actor = $make("actor-{$slug}", $cloneId);
    $b = $make("b-{$slug}", $holders);
    $c = $make("c-{$slug}", $holders);

    return [$tenant, $actor, $b, $c, $owner];
}

/**
 * Lanza un proceso hijo por entrada de `$jobs` con el bloqueo por tenant
 * retenido, espera a que todos estén bloqueados en él, lo libera y recoge
 * los resultados.
 *
 * @param  list<array{operation: string, target: string}>  $jobs
 * @return list<array<string, mixed>>
 */
function rc351Race(object $tenant, User $actor, array $jobs): array
{
    $ruleKey = (new ReflectionClassConstant(AdministrationCapacityGuard::class, 'LOCK_RULE_KEY'))->getValue();
    $tenantKey = $tenant->id % 2_147_483_647;

    $cfg = config('database.connections.pgsql_owner');
    $holder = new PDO(
        "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}",
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $worker = __DIR__.'/worker.php';
    $processes = [];
    $outputs = [];
    $deadline = microtime(true) + 60;

    $kill = function () use (&$processes): void {
        foreach ($processes as $process) {
            $status = proc_get_status($process);

            if ($status['running']) {
                proc_terminate($process, 9);
            }

            proc_close($process);
        }

        $processes = [];
    };

    $holder->beginTransaction();
    $held = true;

    try {
        $holder->prepare('select pg_advisory_xact_lock(?, ?)')->execute([$ruleKey, $tenantKey]);

        foreach ($jobs as $i => $job) {
            $outputs[$i] = tempnam(sys_get_temp_dir(), 'rc351-');
            $processes[$i] = proc_open(
                [PHP_BINARY, '-d', 'memory_limit=512M', $worker, (string) $tenant->id, (string) $actor->public_id, $job['operation'], $job['target']],
                [0 => ['pipe', 'r'], 1 => ['file', $outputs[$i], 'w'], 2 => ['file', $outputs[$i], 'a']],
                $pipes,
            );
            fclose($pipes[0]);
        }

        $waiting = $holder->prepare(
            "select count(*) from pg_locks where locktype = 'advisory' and not granted and objsubid = 2 and classid = ? and objid = ?",
        );

        while (true) {
            $waiting->execute([$ruleKey, $tenantKey]);

            if ((int) $waiting->fetchColumn() === count($jobs)) {
                break;
            }

            foreach ($processes as $i => $process) {
                if (! proc_get_status($process)['running']) {
                    throw new RuntimeException("El proceso hijo {$i} terminó antes de esperar el bloqueo: ".file_get_contents($outputs[$i]));
                }
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException('Los procesos hijo no llegaron a esperar el bloqueo en 60 s.');
            }

            usleep(20_000);
        }

        $holder->rollBack();
        $held = false;

        while (true) {
            $running = array_filter($processes, fn ($process): bool => proc_get_status($process)['running']);

            if ($running === []) {
                break;
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException('Los procesos hijo no terminaron en 60 s tras liberar el bloqueo.');
            }

            usleep(20_000);
        }
    } catch (Throwable $e) {
        $kill();

        throw $e;
    } finally {
        if ($held) {
            $holder->rollBack();
        }
    }

    $kill();

    $results = [];

    foreach ($outputs as $i => $path) {
        $raw = trim((string) file_get_contents($path));
        @unlink($path);
        $lines = explode("\n", $raw);
        $decoded = json_decode((string) end($lines), true);

        expect($decoded)->toBeArray("Salida inesperada del proceso hijo {$i}: {$raw}");

        $results[] = $decoded;
    }

    return $results;
}

/**
 * @param  list<array<string, mixed>>  $results
 */
function rc351Summary(array $results): array
{
    $ok = count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'ok'));
    $conflicts = array_values(array_map(
        fn (array $r): string => (string) $r['detail_key'],
        array_filter($results, fn (array $r): bool => $r['outcome'] === 'api_error' && $r['status'] === 409),
    ));

    return ['ok' => $ok, 'conflicts' => $conflicts];
}

// CA-PERM-049, RN-PERM-47
test('CA-PERM-049: #351 dos bajas reales y solapadas de los dos únicos titulares completos: una se hace y la otra responde 409 administration_capacity_lost', function (): void {
    [$tenant, $actor, $b, $c] = rc351Setup('rc351a', 'clone');
    $tc = app(TenantContext::class);

    // B y C (rol clonado) deben ser los dos únicos titulares completos activos:
    // el actor pasa a tener solo `usuario.eliminar`. El administrador aprovisionado
    // sigue `pendiente`, así que no cuenta para `RN-PERM-47` y sí como administrador
    // vivo para `RN-CORE-07`, que por tanto no interviene aquí.
    $tc->runFor($tenant->id, function () use ($actor): void {
        $only = Role::create(['code' => 'solo_eliminar_351a', 'name' => 'Solo eliminar']);
        PermissionRole::create(['role_id' => $only->id, 'permission_code' => 'usuario.eliminar', 'effect' => 'allow', 'scope' => 'todos']);
        $actor->roles()->sync([$only->id]);
    });

    $results = rc351Race($tenant, $actor, [
        ['operation' => 'delete', 'target' => (string) $b->public_id],
        ['operation' => 'delete', 'target' => (string) $c->public_id],
    ]);

    $summary = rc351Summary($results);
    $alive = $tc->runFor($tenant->id, fn () => User::query()->whereIn('id', [$b->id, $c->id])->count());

    expect($summary['ok'])->toBe(1)
        ->and($summary['conflicts'])->toBe(['core.validation.administration_capacity_lost'])
        ->and($alive)->toBe(1);
});

// RN-CORE-07
test('RN-CORE-07: #351 dos bajas, desactivaciones o retiradas de rol reales y solapadas de los dos únicos administradores: una se hace y la otra responde 409', function (string $operation): void {
    [$tenant, $actor, $b, $c, $owner] = rc351Setup('rc351_'.$operation, 'admin');
    $tc = app(TenantContext::class);

    // Quedan exactamente B y C como administrador_centro (el aprovisionado deja de contar).
    $tc->runFor($tenant->id, fn () => $owner->roles()->detach());

    $results = rc351Race($tenant, $actor, [
        ['operation' => $operation, 'target' => (string) $b->public_id],
        ['operation' => $operation, 'target' => (string) $c->public_id],
    ]);

    $summary = rc351Summary($results);

    $standing = $tc->runFor($tenant->id, fn () => User::query()
        ->whereIn('id', [$b->id, $c->id])
        ->where('status', UserStatus::Activo)
        ->whereHas('roles', fn ($q) => $q->where('code', 'administrador_centro'))
        ->count());

    expect($summary['ok'])->toBe(1)
        ->and($summary['conflicts'])->toBe(['core.validation.last_school_administrator'])
        ->and($standing)->toBe(1);
})->with(['delete', 'status', 'roles']);
