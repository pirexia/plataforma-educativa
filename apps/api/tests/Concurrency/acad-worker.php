<?php

/**
 * Proceso hijo de `tests/Concurrency/AcadConcurrencyTest.php` (REQ-ACAD,
 * 1.11-b; CA-ACAD-004). No es un test: arranca la aplicación en su propio
 * proceso y, por tanto, en su propia conexión a PostgreSQL, y ejecuta UNA
 * operación llamando al mismo servicio que la API. Imprime una línea JSON.
 *
 * Uso: php acad-worker.php <tenantId> <operación> [argumentos…]
 *   create-group <coursePublicId> <code>
 */

use App\Models\User;
use App\Modules\Acad\Application\GroupService;
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
    fwrite(STDERR, "acad-worker.php aborta: entorno «{$app->environment()}» y base «{$database}», se esperaba «testing» y «plataforma_test».\n");
    exit(2);
}

$result = app(TenantContext::class)->runFor((int) $tenantId, function () use ($operation, $args): array {
    try {
        return AuditActor::actingAs('console', function () use ($operation, $args): array {
            if ($operation === 'create-group') {
                $actor = User::query()->where('email', 'admin@example.com')->firstOrFail();
                $group = app(GroupService::class)->create(['course' => $args[0], 'code' => $args[1], 'name' => 'Grupo '.$args[1], 'position' => null], $actor);

                return ['outcome' => 'ok', 'public_id' => $group->public_id];
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
