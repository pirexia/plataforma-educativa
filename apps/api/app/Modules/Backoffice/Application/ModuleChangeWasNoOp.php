<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Core\Domain\ModuleContractingOutcome;
use RuntimeException;

/**
 * `RN-BO-69`. Señal de control interna, nunca una condición de error:
 * `ModuleSubscriptionsService::applyChange()` la lanza y la captura ella
 * misma, dentro del mismo método, para salir de
 * `TenantContext::runAsPlatform(BackofficeEscritura, …)` sin escribir en
 * `admin_action_logs` cuando contratar lo ya contratado (o descontratar
 * lo ya descontratado) resulta ser una no-operación completa — detectado
 * bajo el bloqueo de `ModuleContractingService::apply()`, y por tanto
 * sólo se puede saber una vez dentro del bloque.
 *
 * Nunca cruza `Http\Controllers`: `applyChange()` la atrapa y devuelve
 * el resultado vacío con normalidad.
 */
final class ModuleChangeWasNoOp extends RuntimeException
{
    public function __construct(public readonly ModuleContractingOutcome $outcome)
    {
        parent::__construct('No-operación completa (RN-BO-69): nunca debe llegar fuera de ModuleSubscriptionsService.');
    }
}
