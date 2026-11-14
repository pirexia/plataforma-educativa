<?php

/**
 * Proceso hijo de `tests/Concurrency/AlumConcurrencyTest.php` (REQ-ALUM-002, 1.15-b T2b; CA-ALUM-022, CA-ALUM-030). No es un
 * test: arranca la aplicación en su propio proceso y, por tanto, en su propia conexión a PostgreSQL, y ejecuta UNA
 * operación llamando a los mismos servicios que la API. Imprime una línea JSON con el resultado.
 *
 * Uso: php alum-worker.php <tenantId> <operación> [argumentos…]
 *   enroll <studentPublicId> <yearPublicId> <coursePublicId> <enrolledOn>
 *       Alta de matrícula (`EnrollmentService::create`) como `admin@example.com`.
 *   (opcional, 5.º argumento de `enroll`: `<groupPublicId>`)
 *   delete-group <groupPublicId> <gate>
 *       Borrado del grupo (`Acad\GroupService::delete`, `1.15-b` T2c) dentro de una transacción que NO confirma hasta que el
 *       test libera el bloqueo consultivo `<gate>` (retiene la fila del grupo bloqueada y borrada).
 *   delete-student <studentPublicId> <gate>
 *       Baja del alumno (`StudentService::delete`) dentro de una transacción que NO confirma hasta que el test libera el
 *       bloqueo consultivo `<gate>` (retiene el bloqueo de la fila del alumno).
 */

use App\Models\User;
use App\Modules\Acad\Application\GroupService;
use App\Modules\Alum\Application\EnrollmentService;
use App\Modules\Alum\Application\StudentService;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
    fwrite(STDERR, "alum-worker.php aborta: entorno «{$app->environment()}» y base «{$database}», se esperaba «testing» y «plataforma_test».\n");
    exit(2);
}

$result = app(TenantContext::class)->runFor((int) $tenantId, function () use ($operation, $args): array {
    try {
        return AuditActor::actingAs('console', function () use ($operation, $args): array {
            $actor = static fn (): User => User::query()->where('email', 'admin@example.com')->firstOrFail();

            switch ($operation) {
                case 'enroll':
                    [$student, $year, $course, $on] = $args;
                    $enrollment = app(EnrollmentService::class)->create($actor(), [
                        'student' => $student,
                        'academic_year' => $year,
                        'course' => $course,
                        'enrolled_on' => $on,
                    ] + (isset($args[4]) ? ['group' => $args[4]] : []));

                    return ['outcome' => 'ok', 'public_id' => $enrollment->public_id];

                case 'delete-group':
                    [$publicId, $gate] = $args;
                    DB::transaction(function () use ($publicId, $gate, $actor): void {
                        app(GroupService::class)->delete($publicId, $actor());
                        // Con el grupo borrado y sin confirmar: espera a que el test libere la puerta.
                        DB::select('select pg_advisory_lock(?)', [(int) $gate]);
                        DB::select('select pg_advisory_unlock(?)', [(int) $gate]);
                    });

                    return ['outcome' => 'ok'];

                case 'delete-student':
                    [$publicId, $gate] = $args;
                    DB::transaction(function () use ($publicId, $gate): void {
                        app(StudentService::class)->delete($publicId);
                        // Con la fila del alumno bloqueada y sin confirmar: espera a que el test libere la puerta.
                        DB::select('select pg_advisory_lock(?)', [(int) $gate]);
                        DB::select('select pg_advisory_unlock(?)', [(int) $gate]);
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
