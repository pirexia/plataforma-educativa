<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Issue #128: no había worker de colas desplegado. Este fichero cubre las
 * tres piezas de la corrección: la comprobación de salud
 * `core:queue-health` (detecta «hay trabajos y nadie los toma»), que el
 * comando de worker desplegado consume de verdad la cola `database` con
 * el rol de la aplicación, y que compose.yaml y la unidad Quadlet listan
 * TODAS las colas que el código usa (si alguien añade `->onQueue('x')`
 * sin añadir `x` al worker, sus trabajos se quedarían sin consumidor, que
 * es exactamente el fallo del issue).
 */
beforeEach(function (): void {
    DB::table('jobs')->delete();
    Cache::flush();
});

afterEach(function (): void {
    DB::table('jobs')->delete();
});

/** Inserta una fila de `jobs` disponible desde hace $ageMinutes minutos. */
function insertQueuedJob(string $queue, int $ageMinutes, ?int $reservedAt = null): void
{
    $at = time() - ($ageMinutes * 60);

    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => $at,
        'created_at' => $at,
    ]);
}

test('#128 core:queue-health sale con éxito cuando la cola está vacía', function (): void {
    $this->artisan('core:queue-health')
        ->expectsOutputToContain('no pending jobs')
        ->assertExitCode(0);
});

test('#128 core:queue-health sale con éxito con trabajos recientes (worker sano)', function (): void {
    insertQueuedJob('auth-mail', 0);
    insertQueuedJob('auth-mail', 1);

    $this->artisan('core:queue-health')
        ->expectsOutputToContain('queue=auth-mail pending=2')
        ->assertExitCode(0);
});

test('#128 core:queue-health falla cuando hay trabajos disponibles sin tomar más allá del umbral (worker caído)', function (): void {
    insertQueuedJob('auth-mail', 30);
    insertQueuedJob('core-exports', 0);

    $this->artisan('core:queue-health')
        ->expectsOutputToContain('STALE queue=auth-mail pending=1 oldest_age_min=30')
        ->expectsOutputToContain('ok queue=core-exports')
        ->assertExitCode(1);
});

test('#128 core:queue-health respeta --max-age y la configuración', function (): void {
    insertQueuedJob('core-mail', 10);

    $this->artisan('core:queue-health --max-age=20')->assertExitCode(0);
    $this->artisan('core:queue-health --max-age=5')->assertExitCode(1);

    config(['core.queue_health_max_age_minutes' => 3]);
    $this->artisan('core:queue-health')->assertExitCode(1);
});

test('#128 core:queue-health ignora trabajos reservados (en curso) y los futuros (con retraso)', function (): void {
    insertQueuedJob('auth-mail', 60, reservedAt: time() - 30);

    DB::table('jobs')->insert([
        'queue' => 'auth-mail',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() + 3600,
        'created_at' => time(),
    ]);

    $this->artisan('core:queue-health')->assertExitCode(0);
});

test('#128 el comando de worker desplegado consume un trabajo real de la cola database', function (): void {
    config(['queue.default' => 'database']);
    Cache::put('issue-128-job-ran', false, 60);

    Queue::connection('database')->pushOn('auth-mail', function (): void {
        Cache::put('issue-128-job-ran', true, 60);
    });

    expect(DB::table('jobs')->where('queue', 'auth-mail')->count())->toBe(1);

    // Mismas opciones que compose.yaml/worker.container, salvo --stop-when-empty
    // (el worker real no termina) y --max-time (irrelevante en un test).
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => 'auth-mail,core-mail,bo-mail,default',
        '--tries' => 3,
        '--backoff' => 30,
        '--timeout' => 120,
        '--sleep' => 0,
        '--stop-when-empty' => true,
    ]);

    expect(Cache::get('issue-128-job-ran'))->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(0);
});

/** @return list<string> */
function queuesUsedByCode(): array
{
    $queues = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/onQueue\('([a-z0-9-]+)'\)/", (string) file_get_contents($file->getPathname()), $found);
        array_push($queues, ...$found[1]);
    }

    return array_values(array_unique($queues));
}

/**
 * Las colas de cada proceso `queue:work` de un fichero: conexión => colas. compose.yaml lista el comando en
 * elementos aparte ("- queue:work", "- database", "- --queue=a,b,c"); Quadlet, en una línea `Exec=`.
 *
 * @return array<string, list<string>>
 */
function workerCommands(string $path): array
{
    expect($path)->toBeFile();

    $content = (string) file_get_contents($path);
    $commands = [];

    // Quadlet: `queue:work <conexión> … --queue=…` en una línea.
    preg_match_all('/queue:work\s+([a-z_]+)[^\n]*?--queue=([a-z0-9,-]+)/', $content, $inline, PREG_SET_ORDER);

    foreach ($inline as $m) {
        $commands[$m[1]] = array_merge($commands[$m[1]] ?? [], explode(',', $m[2]));
    }

    // compose.yaml: elementos de lista consecutivos.
    preg_match_all('/-\s+queue:work\s*\n\s*-\s+([a-z_]+)\s*\n\s*-\s+--queue=([a-z0-9,-]+)/', $content, $listed, PREG_SET_ORDER);

    foreach ($listed as $m) {
        $commands[$m[1]] = array_merge($commands[$m[1]] ?? [], explode(',', $m[2]));
    }

    return $commands;
}

/** @return list<string> */
function queuesOfWorkers(array $paths): array
{
    $queues = [];

    foreach ($paths as $path) {
        foreach (workerCommands($path) as $list) {
            array_push($queues, ...$list);
        }
    }

    return array_values(array_unique($queues));
}

test('#128 compose.yaml y las unidades Quadlet de los workers cubren todas las colas que usa el código', function (): void {
    $used = queuesUsedByCode();
    $root = base_path('../..');

    expect($used)->not->toBeEmpty();

    foreach ([['compose.yaml'], ['infra/quadlet/worker.container', 'infra/quadlet/worker-long.container']] as $files) {
        $worker = queuesOfWorkers(array_map(fn (string $f): string => $root.'/'.$f, $files));

        expect(array_values(array_diff($used, $worker)))->toBe([], implode(', ', $files).' no consumen estas colas')
            ->and($worker)->toContain('default');
    }
})->skip(fn () => ! is_file(base_path('../../compose.yaml')), 'La raíz del repositorio no está montada (contenedor de desarrollo solo con apps/api); CI sí la tiene.');

test('CA-CURSO-155 RN-CURSO-84 operacion.md §1: la cola long se consume por la conexión database_long, con su propio proceso, y no por el worker de la conexión database (cuyo retry_after es menor que el timeout de los trabajos)', function (): void {
    $root = base_path('../..');

    foreach (['compose.yaml', 'infra/quadlet/worker.container', 'infra/quadlet/worker-long.container'] as $file) {
        $commands = workerCommands($root.'/'.$file);

        expect($commands['database'] ?? [])->not->toContain('long', "{$file}: el worker de la conexión database no debe consumir la cola long");

        if ($file !== 'infra/quadlet/worker.container') {
            expect($commands['database_long'] ?? [])->toBe(['long'], "{$file}: falta el proceso de la cola long");
        }
    }

    // Un solo intento, sin reentrega; --timeout del worker menor que el retry_after de la conexión.
    $retryAfter = (int) config('queue.connections.database_long.retry_after');

    expect($retryAfter)->toBe(900);

    foreach (['compose.yaml', 'infra/quadlet/worker-long.container'] as $file) {
        $content = (string) file_get_contents($root.'/'.$file);
        $long = substr($content, (int) strpos($content, 'database_long'));

        expect($long)->toContain('--tries=1');
        preg_match('/--timeout=(\d+)/', $long, $timeout);
        expect((int) $timeout[1])->toBeLessThan($retryAfter);
    }
})->skip(fn () => ! is_file(base_path('../../compose.yaml')), 'La raíz del repositorio no está montada (contenedor de desarrollo solo con apps/api); CI sí la tiene.');

test('#128 la unidad Quadlet del worker cumple ADR-028 (Wants+After, nunca Requires/BindsTo) y --timeout < retry_after', function (): void {
    $unit = (string) file_get_contents(base_path('../../infra/quadlet/worker.container'));
    $code = preg_replace('/^#.*$/m', '', $unit);

    expect($code)->toContain('Wants=')->toContain('After=')
        ->not->toMatch('/^(Requires|BindsTo)=/m')
        ->not->toMatch('/^PublishPort=/m')
        ->toMatch('/--max-time=\d+/')
        ->toMatch('#^EnvironmentFile=/etc/plataforma/plataforma-worker\.env$#m')
        ->toMatch('/^NoNewPrivileges=true$/m')
        ->toMatch('/^DropCapability=ALL$/m');

    preg_match('/--timeout=(\d+)/', $code, $timeout);
    preg_match('/DB_QUEUE_RETRY_AFTER=(\d+)/', $code, $retryAfter);

    expect((int) $timeout[1])->toBeLessThan((int) $retryAfter[1]);
})->skip(fn () => ! is_file(base_path('../../compose.yaml')), 'La raíz del repositorio no está montada (contenedor de desarrollo solo con apps/api); CI sí la tiene.');

test('CA-CURSO-155 ADR-028: la unidad Quadlet del worker long cumple ADR-028 (Wants+After, nunca Requires/BindsTo, sin puertos) y deja acabar la copia en curso al parar', function (): void {
    $unit = (string) file_get_contents(base_path('../../infra/quadlet/worker-long.container'));
    $code = preg_replace('/^#.*$/m', '', $unit);

    expect($code)->toContain('Wants=')->toContain('After=')
        ->not->toMatch('/^(Requires|BindsTo)=/m')
        ->not->toMatch('/^PublishPort=/m')
        ->toMatch('/--max-time=\d+/')
        ->toMatch('#^EnvironmentFile=/etc/plataforma/plataforma-worker-long\.env$#m')
        ->toMatch('/^NoNewPrivileges=true$/m')
        ->toMatch('/^DropCapability=ALL$/m');

    preg_match('/--timeout=(\d+)/', $code, $timeout);
    preg_match('/^StopTimeout=(\d+)$/m', $code, $stop);

    // La parada espera más que el timeout del trabajo: SIGTERM deja terminar la copia en curso.
    expect((int) $stop[1])->toBeGreaterThan((int) $timeout[1]);
})->skip(fn () => ! is_file(base_path('../../compose.yaml')), 'La raíz del repositorio no está montada (contenedor de desarrollo solo con apps/api); CI sí la tiene.');

test('CA-CURSO-155 revisión de seguridad M2: worker-long tiene su propio entorno sin DB_OWNER_* ni DB_PLATFORM_*, en Quadlet y en compose, y su plantilla tampoco los lleva', function (): void {
    $root = base_path('../../');
    $template = (string) file_get_contents($root.'infra/quadlet/plataforma-worker-long.env.example');
    $values = preg_replace('/^#.*$/m', '', $template);

    expect($values)->not->toContain('DB_OWNER_')->not->toContain('DB_PLATFORM_')->toContain('DB_USERNAME=plataforma_app');

    // La plantilla del worker general sí los lleva (desviación declarada de #128): el contraste fija la diferencia.
    expect((string) file_get_contents($root.'infra/quadlet/plataforma-worker.env.example'))->toContain('DB_OWNER_USERNAME=');

    $compose = (string) file_get_contents($root.'compose.yaml');
    $block = substr($compose, (int) strpos($compose, "  worker-long:\n"));

    foreach (['DB_OWNER_USERNAME', 'DB_OWNER_PASSWORD', 'DB_PLATFORM_USERNAME', 'DB_PLATFORM_PASSWORD'] as $var) {
        expect($block)->toContain("{$var}: \"\"");
    }
})->skip(fn () => ! is_file(base_path('../../compose.yaml')), 'La raíz del repositorio no está montada (contenedor de desarrollo solo con apps/api); CI sí la tiene.');

test('#128 core:queue-health --queues solo mira las colas indicadas: una cola atascada de otro worker no marca como no sano a este', function (): void {
    insertQueuedJob('long', 30);
    insertQueuedJob('auth-mail', 0);

    $this->artisan('core:queue-health --queues=auth-mail,core-mail')
        ->expectsOutputToContain('ok queue=auth-mail')
        ->assertExitCode(0);
    $this->artisan('core:queue-health --queues=long')->assertExitCode(1);
    // Sin la opción sigue mirando todas (compatibilidad).
    $this->artisan('core:queue-health')->assertExitCode(1);

    DB::table('jobs')->delete();
    insertQueuedJob('auth-mail', 30);

    $this->artisan('core:queue-health --queues=long')->expectsOutputToContain('no pending jobs')->assertExitCode(0);
});

test('CA-CURSO-155 revisión de seguridad B3: cada HEALTHCHECK de worker pasa --queues con exactamente las colas de su propio proceso (compose y Quadlet)', function (): void {
    $root = base_path('../..');

    foreach (['infra/quadlet/worker.container' => 'database', 'infra/quadlet/worker-long.container' => 'database_long'] as $file => $connection) {
        $content = (string) file_get_contents($root.'/'.$file);
        $queues = workerCommands($root.'/'.$file)[$connection];

        preg_match('/^HealthCmd=php artisan core:queue-health --queues=([a-z0-9,-]+)$/m', $content, $m);

        expect($m)->not->toBeEmpty("{$file}: el HealthCmd debe llevar --queues")
            ->and(explode(',', $m[1]))->toBe($queues);
    }

    $compose = (string) file_get_contents($root.'/compose.yaml');
    preg_match_all('/"core:queue-health", "--queues=([a-z0-9,-]+)"/', $compose, $health);
    $fromCompose = workerCommands($root.'/compose.yaml');

    expect(array_map(fn (string $l): array => explode(',', $l), $health[1]))->toBe([$fromCompose['database'], $fromCompose['database_long']]);
})->skip(fn () => ! is_file(base_path('../../compose.yaml')), 'La raíz del repositorio no está montada (contenedor de desarrollo solo con apps/api); CI sí la tiene.');
