<?php

namespace App\Modules\Core\Domain;

/**
 * api.md §2.7, funcional.md §5.8.2, `OPEN-BO-18`. Respuesta de la vista
 * previa de impacto: no reserva nada y no es un contrato (`RN-BO-68`).
 *
 * `screens_removed`/`integrations_disabled` salen del descriptor
 * declarado por el módulo; en `1.6c` ningún módulo los declara todavía,
 * así que van siempre vacíos — nunca rellenos con un valor inventado
 * (api.md §2.7).
 */
final class ModuleContractingPreview
{
    /**
     * @param  list<string>  $modulesToContract
     * @param  list<string>  $modulesToDecontract
     * @param  list<string>  $cascadedDependencies
     * @param  list<array{module_code: string, reason_code: string}>  $blocked
     * @param  array{users_affected: int, screens_removed: list<string>, integrations_disabled: list<string>}  $impact
     */
    public function __construct(
        public readonly int $affectedTenants,
        public readonly array $modulesToContract,
        public readonly array $modulesToDecontract,
        public readonly array $cascadedDependencies,
        public readonly array $blocked,
        public readonly array $impact,
    ) {}
}
