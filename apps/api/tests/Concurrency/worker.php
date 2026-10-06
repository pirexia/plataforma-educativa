<?php

/**
 * Proceso hijo de `tests/Feature/Perm/RealConcurrencyTest.php` (issue #351).
 * No es un test: arranca la aplicación en su propio proceso y, por tanto,
 * en su propia conexión a PostgreSQL, y ejecuta UNA operación de usuarios
 * llamando al mismo controlador/servicio que la ruta HTTP, con el actor ya
 * autenticado (la autenticación y el middleware de la ruta no son lo que se
 * prueba aquí). Imprime una línea JSON con el resultado.
 *
 * Uso: php worker.php <tenantId> <actorPublicId> <delete|status|roles> <targetPublicId>
 */

use App\Models\User;
use App\Modules\Core\Application\ReplaceUserRoles;
use App\Modules\Core\Domain\Events\UserDeactivated;
use App\Modules\Core\Domain\Events\UserRolesChanged;
use App\Modules\Core\Http\Controllers\UsersController;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

[, $tenantId, $actorPublicId, $operation, $targetPublicId] = $argv;

foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$result = app(TenantContext::class)->runFor((int) $tenantId, function () use ($actorPublicId, $operation, $targetPublicId): array {
    $actor = User::query()->where('public_id', $actorPublicId)->firstOrFail();
    Auth::setUser($actor);
    Event::fake([UserDeactivated::class, UserRolesChanged::class]);

    try {
        match ($operation) {
            'delete' => app(UsersController::class)->destroy($targetPublicId),
            'status' => app(UsersController::class)->updateStatus(Request::create('/', 'POST', ['status' => 'inactivo']), $targetPublicId),
            'roles' => app(ReplaceUserRoles::class)->execute(
                User::query()->where('public_id', $targetPublicId)->firstOrFail(),
                [],
                $actor,
            ),
        };

        return ['outcome' => 'ok'];
    } catch (ApiException $e) {
        return ['outcome' => 'api_error', 'status' => $e->status, 'detail_key' => $e->detailKey];
    }
});

echo json_encode($result), "\n";
