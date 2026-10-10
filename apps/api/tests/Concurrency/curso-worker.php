<?php

/**
 * Proceso hijo de `tests/Concurrency/CursoConcurrencyTest.php` (REQ-CURSO,
 * 1.10; CA-CURSO-009, CA-CURSO-046, CA-057-08). No es un test: arranca la
 * aplicación en su propio proceso y, por tanto, en su propia conexión a
 * PostgreSQL, y ejecuta UNA operación llamando a los mismos servicios que la
 * API. Imprime una línea JSON con el resultado.
 *
 * Uso: php curso-worker.php <tenantId> <operación> [argumentos…]
 *   create  <code> <starts_on> <ends_on>
 *   activate <publicId>
 *   close   <publicId> <claveAdvisory>   (la validación de cierre espera
 *                                         el bloqueo consultivo con el
 *                                         FOR UPDATE del curso retenido)
 *   write   <academicYearId> <name> [claveAdvisory]  (inserta en la sonda;
 *                                         con clave, espera el bloqueo
 *                                         consultivo antes de confirmar)
 */

use App\Modules\Curso\Application\AcademicYearAdministration;
use App\Modules\Curso\Application\AcademicYearTransitions;
use App\Modules\Curso\Domain\AcademicYearClosureCheck;
use App\Modules\Curso\Domain\AcademicYearClosureFailure;
use App\Modules\Curso\Domain\AcademicYearClosureRegistry;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcademicYearProbe;

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
    fwrite(STDERR, "curso-worker.php aborta: entorno «{$app->environment()}» y base «{$database}», se esperaba «testing» y «plataforma_test».\n");
    exit(2);
}

$result = app(TenantContext::class)->runFor((int) $tenantId, function () use ($operation, $args): array {
    try {
        return AuditActor::actingAs('console', function () use ($operation, $args): array {
            switch ($operation) {
                case 'create':
                    $year = app(AcademicYearAdministration::class)->create(['code' => $args[0], 'starts_on' => $args[1], 'ends_on' => $args[2]]);

                    return ['outcome' => 'ok', 'public_id' => $year->public_id];

                case 'activate':
                    app(AcademicYearTransitions::class)->transition($args[0], AcademicYearStatus::Activo);

                    return ['outcome' => 'ok'];

                case 'close':
                    $key = (int) $args[1];
                    app(AcademicYearClosureRegistry::class)->register(new class($key) implements AcademicYearClosureCheck
                    {
                        public function __construct(private readonly int $key) {}

                        public function check(AcademicYearSummary $year): ?AcademicYearClosureFailure
                        {
                            // Con el FOR UPDATE del curso retenido: espera a que el test lo libere.
                            DB::select('select pg_advisory_lock(?)', [$this->key]);
                            DB::select('select pg_advisory_unlock(?)', [$this->key]);

                            return null;
                        }
                    });
                    app(AcademicYearTransitions::class)->transition($args[0], AcademicYearStatus::Cerrado);

                    return ['outcome' => 'ok'];

                case 'write':
                    DB::transaction(function () use ($args): void {
                        DB::table(AcademicYearProbe::TABLE)->insert(['name' => $args[1], 'academic_year_id' => (int) $args[0]]);

                        if (isset($args[2])) {
                            DB::select('select pg_advisory_lock(?)', [(int) $args[2]]);
                            DB::select('select pg_advisory_unlock(?)', [(int) $args[2]]);
                        }
                    });

                    return ['outcome' => 'ok'];
            }

            return ['outcome' => 'unknown_operation'];
        });
    } catch (ApiException $e) {
        $first = collect($e->errors)->flatten(1)->first();

        return ['outcome' => 'api_error', 'status' => $e->status, 'code' => $first['code'] ?? $e->detailKey];
    } catch (QueryException $e) {
        return ['outcome' => 'sql_error', 'sqlstate' => $e->errorInfo[0] ?? null];
    }
});

echo json_encode($result), "\n";
