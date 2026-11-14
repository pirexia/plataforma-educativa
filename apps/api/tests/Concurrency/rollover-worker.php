<?php

/**
 * Proceso hijo de `tests/Concurrency/RolloverConcurrencyTest.php` (REQ-CURSO-002, 1.12b; CA-CURSO-131, -132).
 * No es un test: arranca la aplicación en su propio proceso y, por tanto, en su propia conexión a PostgreSQL, y
 * ejecuta UNA operación llamando a los mismos servicios que la API y los trabajos en cola. Imprime una línea JSON.
 *
 * Uso: php rollover-worker.php <tenantId> <operación> [argumentos…]
 *   request <userId> <targetPublicId> <sourcePublicId> <gate|->
 *       RolloverService::request() con un participante de prueba (`curso.alpha`) cuyo `preview()` espera el
 *       bloqueo consultivo `<gate>` (que retiene el test), de modo que ambos procesos pasen la comprobación de
 *       «copia viva» antes de insertar y la carrera la resuelva el índice único parcial (RN-CURSO-67).
 *   run <rolloverId> <gate|-> <probeFirst:0|1>
 *       RolloverExecutor::run() con `Auth` vacío (como un worker). Con un participante de prueba cuyo `copy()` espera
 *       `<gate>` con el FOR SHARE del destino y la transacción REPEATABLE READ ya abiertos. Con `probeFirst=1`, el
 *       participante de prueba va ANTES que el de `acad` (para que `acad` lea el origen después de la espera).
 */

use App\Models\User;
use App\Modules\Curso\Application\Rollover\RolloverExecutor;
use App\Modules\Curso\Application\Rollover\RolloverRequest;
use App\Modules\Curso\Application\Rollover\RolloverService;
use App\Modules\Curso\Domain\AcademicYearRolloverRegistry;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Infrastructure\InMemoryAcademicYearRolloverRegistry;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProbeRolloverParticipant;

[, $tenantId, $operation] = $argv;
$args = array_slice($argv, 3);

foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Escribe de verdad: solo contra la base de test.
$database = DB::connection()->selectOne('select current_database() as name')->name;

if (! $app->environment('testing') || $database !== 'plataforma_test') {
    fwrite(STDERR, "rollover-worker.php aborta: entorno «{$app->environment()}» y base «{$database}», se esperaba «testing» y «plataforma_test».\n");
    exit(2);
}

/** Bloqueo consultivo que retiene el test: se espera y se suelta enseguida. */
$wait = static function (string $gate): void {
    if ($gate !== '-') {
        DB::select('select pg_advisory_lock(?)', [(int) $gate]);
        DB::select('select pg_advisory_unlock(?)', [(int) $gate]);
    }
};

$result = app(TenantContext::class)->runFor((int) $tenantId, function () use ($operation, $args, $wait): array {
    try {
        switch ($operation) {
            case 'request':
                Queue::fake();
                $probe = ProbeRolloverParticipant::trio();
                $probe->onPreview = fn () => $wait($args[3]);
                app(AcademicYearRolloverRegistry::class)->register($probe);

                $rollover = app(RolloverService::class)->request(
                    User::query()->findOrFail((int) $args[0]),
                    new RolloverRequest($args[1], $args[2], ['curso.alpha']),
                );

                return ['outcome' => 'ok', 'public_id' => $rollover->public_id];

            case 'run':
                $probe = ProbeRolloverParticipant::trio();
                $probe->onCopy = fn () => $wait($args[1]);

                if ($args[2] === '1') {
                    // El de prueba antes que `acad`: sin requisitos entre ellos, el orden es el de registro.
                    $registry = new InMemoryAcademicYearRolloverRegistry;
                    $registry->register($probe);

                    foreach (app(AcademicYearRolloverRegistry::class)->all() as $participant) {
                        $registry->register($participant);
                    }

                    app()->instance(AcademicYearRolloverRegistry::class, $registry);
                } else {
                    app(AcademicYearRolloverRegistry::class)->register($probe);
                }

                Auth::forgetUser();
                app(RolloverExecutor::class)->run((int) $args[0]);

                return ['outcome' => 'ok', 'status' => YearRollover::query()->whereKey((int) $args[0])->value('status')];
        }

        return ['outcome' => 'unknown_operation'];
    } catch (ApiException $e) {
        $first = collect($e->errors)->flatten(1)->first();

        return ['outcome' => 'api_error', 'status' => $e->status, 'code' => $first['code'] ?? $e->detailKey];
    } catch (QueryException $e) {
        return ['outcome' => 'sql_error', 'sqlstate' => $e->errorInfo[0] ?? null];
    }
});

echo json_encode($result), "\n";
