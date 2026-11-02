<?php

/**
 * Proceso hijo de `tests/Concurrency/PrlConcurrencyTest.php` (REQ-PRL-004, 1.14b-b; CA-PRL-005, RN-PRL-09). No es un test:
 * arranca la aplicación en su propio proceso y, por tanto, en su propia conexión a PostgreSQL, y ejecuta UNA operación
 * llamando al mismo servicio que la API. Imprime una línea JSON.
 *
 * Uso: php prl-worker.php <tenantId> <operación> [argumentos…]
 *   record-exit <userPublicId> <studentPersonPublicId> <personPublicId> <idempotencyKey|->   (StudentMovementService::record)
 *   raise-alert <userPublicId> <studentPersonPublicId> <idempotencyKey|->                    (PickupAlertService::raise)
 */

use App\Models\User;
use App\Modules\Prl\Application\PickupAlertService;
use App\Modules\Prl\Application\StudentMovementService;
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
    fwrite(STDERR, "prl-worker.php aborta: entorno «{$app->environment()}» y base «{$database}», se esperaba «testing» y «plataforma_test».\n");
    exit(2);
}

$result = app(TenantContext::class)->runFor((int) $tenantId, function () use ($operation, $args): array {
    try {
        return AuditActor::actingAs('console', function () use ($operation, $args): array {
            $user = User::query()->where('public_id', $args[0])->firstOrFail();
            $key = static fn (string $value): ?string => $value === '-' ? null : $value;

            switch ($operation) {
                case 'record-exit':
                    $recorded = app(StudentMovementService::class)->record([
                        'student_person' => $args[1], 'direction' => 'salida', 'picked_up_by' => $args[2], 'verification_method' => 'foto',
                        'delivered_by' => null, 'off_schedule' => false, 'off_schedule_reason' => null, 'notes' => null, 'corrects' => null, 'correction_reason' => null,
                    ], $user, $key($args[3]));

                    return ['outcome' => 'ok', 'public_id' => $recorded['movement']->public_id, 'replayed' => $recorded['replayed']];

                case 'raise-alert':
                    $raised = app(PickupAlertService::class)->raise([
                        'student_person' => $args[1], 'kind' => 'persona_no_autorizada', 'presenter_description' => 'Persona sin identificar',
                    ], $user, $key($args[2]));

                    return ['outcome' => 'ok', 'public_id' => $raised['alert']->public_id, 'replayed' => $raised['replayed']];
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
