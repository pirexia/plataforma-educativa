<?php

namespace App\Modules\Core\Domain;

use Illuminate\Support\Carbon;

/**
 * funcional.md §5.8.2, §5.8.5, `OPEN-BO-18`. Lo que de verdad cambió al
 * ejecutar un `ModuleChange` — módulo a módulo, con la dirección y si
 * fue principal o arrastrado (`RN-BO-72`) — y es lo que `REQ-BO` recorre
 * para escribir `admin_action_logs` y lo que `ModuleContracting::publish()`
 * recorre para invalidar caché y emitir eventos.
 *
 * Un `Outcome` vacío (`$applied === []`) es un resultado legítimo y
 * frecuente (`RN-BO-69`): contratar lo ya contratado no cambia nada.
 */
final class ModuleContractingOutcome
{
    /**
     * @param  list<array{module_code: string, enabled: bool, cascaded: bool, occurred_at: Carbon}>  $applied
     * @param  list<string>  $unchanged
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly array $applied,
        public readonly array $unchanged,
    ) {}

    public function isEmpty(): bool
    {
        return $this->applied === [];
    }
}
