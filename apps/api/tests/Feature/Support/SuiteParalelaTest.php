<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\Support\TestPasswordHash;

/**
 * ADR-060 (suite de tests en paralelo). CA-060-05, CA-060-06 y CA-060-08.
 */

/**
 * Ejecuta tests/bootstrap.php en un proceso aparte con el entorno dado y
 * devuelve [código de salida, stderr].
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function runBootstrapWith(array $env): array
{
    $process = new Process(
        [PHP_BINARY, '-r', "require 'tests/bootstrap.php';"],
        base_path(),
        // Sin DB_HOST la comprobación de existencia no se hace; la guarda
        // de nombre y entorno sí. Se hereda el resto del entorno real.
        $env,
    );
    $process->run();

    return [$process->getExitCode(), $process->getErrorOutput()];
}

// CA-060-05: la guarda de bootstrap.php aborta antes del primer test.
test('CA-060-05: con TEST_TOKEN y un nombre de base que no casa con plataforma_test_N, el bootstrap aborta', function (string $token): void {
    [$code, $stderr] = runBootstrapWith(['TEST_TOKEN' => $token, 'APP_ENV' => 'testing']);

    expect($code)->toBe(1)
        ->and($stderr)->toContain('ADR-060');
})->with(['no numérico' => 'abc', 'con sufijo' => '1x', 'con inyección' => '1; DROP DATABASE x', 'negativo' => '-1']);

test('CA-060-05: con TEST_TOKEN y un entorno distinto de testing, el bootstrap aborta', function (): void {
    [$code, $stderr] = runBootstrapWith(['TEST_TOKEN' => '1', 'APP_ENV' => 'production']);

    expect($code)->toBe(1)
        ->and($stderr)->toContain('APP_ENV');
});

test('CA-060-05: con TEST_TOKEN fuera del número de bases creadas, el bootstrap aborta nombrando el script', function (): void {
    [$code, $stderr] = runBootstrapWith(['TEST_TOKEN' => '99', 'APP_ENV' => 'testing']);

    expect($code)->toBe(1)
        ->and($stderr)->toContain('bases-test-paralelo.sh');
});

test('CA-060-05: sin TEST_TOKEN el bootstrap no cambia nada (ejecución en serie)', function (): void {
    $process = new Process(
        [PHP_BINARY, '-r', "require 'tests/bootstrap.php'; echo json_encode([getenv('DB_DATABASE'), getenv('REDIS_CACHE_DB')]);"],
        base_path(),
        ['APP_ENV' => 'testing', 'DB_DATABASE' => 'plataforma_test', 'REDIS_CACHE_DB' => '2', 'TEST_TOKEN' => false],
    );
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe('["plataforma_test","2"]');
});

test('CA-060-05: las guardas de Concurrency siguen admitiendo solo plataforma_test', function (): void {
    foreach (['tests/ConcurrentTestCase.php', 'tests/Concurrency/worker.php', 'tests/Concurrency/curso-worker.php'] as $file) {
        $source = (string) file_get_contents(base_path($file));

        expect($source)->toContain('plataforma_test')
            ->and($source)->not->toContain('plataforma_test_[0-9]');
    }
});

// CA-060-06 (ADR-060 §4.3.1): el hash memorizado es bcrypt coste 12 real.
test('CA-060-06 RN-AUTH-03: el hash de contraseña memorizado es bcrypt con coste 12 y se reutiliza', function (): void {
    $hash = TestPasswordHash::of('Cl4v3-Correcta-2026!');
    $info = password_get_info($hash);

    expect($info['algoName'])->toBe('bcrypt')
        ->and($info['options']['cost'])->toBe(12)
        ->and(Hash::check('Cl4v3-Correcta-2026!', $hash))->toBeTrue()
        ->and(TestPasswordHash::of('Cl4v3-Correcta-2026!'))->toBe($hash)
        ->and(TestPasswordHash::of('otra-clave-distinta'))->not->toBe($hash);
});

// CA-060-06 (ADR-060 §4.3.2): lo que escribe platform:sync-registry está
// confirmado, no dentro de la transacción del test.
test('CA-060-06: lo escrito por platform:sync-registry es visible desde otra sesión y sobrevive al final del test', function (): void {
    $this->artisan('platform:sync-registry')->run();

    // pgsql_platform es una sesión distinta de la de pgsql (transacción del test).
    $codes = DB::connection('pgsql_platform')->table('permissions')->where('module_code', 'core')->whereNull('retired_at')->count();

    expect($codes)->toBeGreaterThan(0);
});

test('CA-060-06: el registro sincronizado en el test anterior sigue ahí, tras el rollback de su transacción', function (): void {
    expect(DB::connection('pgsql_platform')->table('permissions')->where('module_code', 'core')->whereNull('retired_at')->count())->toBeGreaterThan(0)
        ->and(DB::connection('pgsql_platform')->table('modules')->where('code', 'core')->exists())->toBeTrue();
});

// CA-060-08: ningún fichero de test define una función usada desde otro.
test('CA-060-08: ninguna función definida en un fichero de test se usa desde otro fichero de test', function (): void {
    $root = base_path('tests');
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    $offenders = [];

    foreach ($files as $path => $source) {
        // Solo los ficheros de test; Pest.php y tests/Support/ son los sitios compartidos.
        if (! str_ends_with($path, 'Test.php')) {
            continue;
        }

        // `function nombre(` al principio de línea = función global del fichero.
        preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $matches);

        foreach ($matches[1] as $name) {
            foreach ($files as $otherPath => $otherSource) {
                if ($otherPath !== $path && preg_match('/(?<![A-Za-z0-9_>:$])'.preg_quote($name, '/').'\s*\(/', $otherSource) === 1) {
                    $offenders[] = basename($path)." define {$name}() y lo usa ".basename($otherPath);
                }
            }
        }
    }

    expect($offenders)->toBe([], 'Funciones compartidas entre ficheros de test: muévelas a tests/Support/ o tests/Pest.php (ADR-060 §4.2.1).');
});
