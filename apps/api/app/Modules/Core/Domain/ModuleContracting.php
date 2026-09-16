<?php

namespace App\Modules\Core\Domain;

/**
 * `ADR-045 §4.5`, `§4.8`; funcional.md §5.8.2, `OPEN-BO-18`. Escritura de
 * `module_subscriptions`: una sola implementación, consumida por la
 * contratación individual, la masiva y la vista previa de `REQ-BO`
 * (`RN-BO-22`). Sólo `REQ-BO` la consume — el evaluador de disponibilidad
 * de módulos usa `ModuleCatalog`, no ésta.
 *
 * **`apply()` y `publish()` son dos métodos y no uno**, y no es estilo:
 * lo fuerza `ADR-046 §6.4`. `apply()` corre dentro de
 * `runAsPlatform(BackofficeEscritura, …)`, donde está prohibido que haya
 * tenant activo; `publish()` necesita entrar en el contexto de cada
 * centro con `runFor()` para invalidar su prefijo de caché, que es
 * justamente la combinación prohibida. Las dos cosas no caben en el
 * mismo bloque (funcional.md §5.8.2, `RN-BO-75`).
 */
interface ModuleContracting
{
    /**
     * No escribe nada, no bloquea nada y no reserva nada (`RN-BO-68`).
     */
    public function preview(int $tenantId, ModuleChange $change): ModuleContractingPreview;

    /**
     * Fase 1: valida, bloquea la fila de `tenants` (`RN-BO-66`), recalcula
     * el cierre de dependencias y escribe `module_subscriptions`. No
     * invalida caché ni emite eventos — eso es `publish()`. El llamador
     * debe estar ya dentro de una transacción abierta sobre
     * `pgsql_platform` (mismo patrón que
     * `TenantLifecycleService::executeApprovedDeletion()`), para que la
     * escritura de `admin_action_logs` que hace `REQ-BO` sea atómica con
     * ésta.
     */
    public function apply(int $tenantId, ModuleChange $change): ModuleContractingOutcome;

    /**
     * Fase 2: invalida `modules:{code}:enabled` del tenant afectado, con
     * su prefijo, y emite `ModuleContracted`/`ModuleDecontracted` — uno
     * por módulo cambiado, arrastrados incluidos (`RN-BO-27`, `RN-BO-75`).
     * Se llama **fuera** del bloque de plataforma y **después** del
     * `COMMIT` de `apply()`. Intenta invalidar y emitir para **todos**
     * los módulos del `Outcome` aunque alguno falle, y relanza al final
     * la primera excepción encontrada, si hubo alguna: el llamador es
     * quien decide qué hacer con ese fallo — nunca dejar que llegue al
     * cliente como `5xx` (`RN-BO-76`), la escritura ya está confirmada y
     * auditada.
     */
    public function publish(ModuleContractingOutcome $outcome): void;
}
