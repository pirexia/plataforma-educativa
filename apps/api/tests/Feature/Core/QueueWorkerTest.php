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

/** @return list<string> */
function queuesOfWorkerCommand(string $path): array
{
    expect($path)->toBeFile();

    preg_match('/queue:work database[^\n]*?--queue=([a-z0-9,-]+)/', (string) file_get_contents($path), $m);
    // compose.yaml lista el comando en elementos aparte: "- --queue=a,b,c".
    if ($m === []) {
        preg_match('/--queue=([a-z0-9,-]+)/', (string) file_get_contents($path), $m);
    }

    return explode(',', $m[1] ?? '');
}

test('#128 compose.yaml y la unidad Quadlet del worker cubren todas las colas que usa el código', function (): void {
    $used = queuesUsedByCode();
    $root = base_path('../..');

    expect($used)->not->toBeEmpty();

    foreach (['compose.yaml', 'infra/quadlet/worker.container'] as $file) {
        $worker = queuesOfWorkerCommand($root.'/'.$file);

        expect(array_values(array_diff($used, $worker)))->toBe([], "$file no consume estas colas")
            ->and($worker)->toContain('default');
    }
})->skip(fn () => ! is_file(base_path('../../compose.yaml')), 'La raíz del repositorio no está montada (contenedor de desarrollo solo con apps/api); CI sí la tiene.');

test('#128 la unidad Quadlet del worker cumple ADR-028 (Wants+After, nunca Requires/BindsTo) y --timeout < retry_after', function (): void {
    $unit = (string) file_get_contents(base_path('../../infra/quadlet/worker.container'));
    $code = preg_replace('/^#.*$/m', '', $unit);

    expect($code)->toContain('Wants=')->toContain('After=')
        ->not->toMatch('/^(Requires|BindsTo)=/m')
        ->not->toMatch('/^PublishPort=/m')
        ->toMatch('/--max-time=\d+/');

    preg_match('/--timeout=(\d+)/', $code, $timeout);
    preg_match('/DB_QUEUE_RETRY_AFTER=(\d+)/', $code, $retryAfter);

    expect((int) $timeout[1])->toBeLessThan((int) $retryAfter[1]);
})->skip(fn () => ! is_file(base_path('../../compose.yaml')), 'La raíz del repositorio no está montada (contenedor de desarrollo solo con apps/api); CI sí la tiene.');
