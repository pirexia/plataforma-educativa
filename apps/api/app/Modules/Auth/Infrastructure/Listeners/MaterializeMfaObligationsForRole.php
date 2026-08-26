<?php

namespace App\Modules\Auth\Infrastructure\Listeners;

use App\Models\Role;
use App\Modules\Auth\Domain\MfaObligationTrigger;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Core\Domain\Events\RoleMfaRequirementChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * funcional.md §C.4.8 (tabla, disparador 1): "un trabajo encolado
 * materializa la fila de los usuarios afectados en ese momento, sin
 * esperar a que entren". `INV-012`: tareas pesadas en cola, nunca en la
 * petición HTTP — un rol puede tener cientos de usuarios.
 *
 * `ShouldQueue`, sin `TenantContext::runFor()` propio: `Queue::createPayloadUsing()`
 * (`TenancyServiceProvider`) ya estampa el `tenant_id` activo en el
 * momento del `event()` de `RolesController::update()`, y lo restaura al
 * procesar el job — el mismo mecanismo que usa cualquier otro job
 * encolado de este módulo (`ADR-033 §8`).
 *
 * `INV-007`: consume el evento publicado por `REQ-CORE`, no importa
 * código interno de ese módulo — `App\Models\Role` es el modelo del
 * núcleo compartido, no un interno de `REQ-CORE` (mismo criterio que
 * `App\Models\User` en el resto de este módulo).
 */
class MaterializeMfaObligationsForRole implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('auth-maintenance');
    }

    public function handle(RoleMfaRequirementChanged $event, MfaPolicy $policy): void
    {
        $role = Role::query()->where('public_id', $event->rolePublicId)->first();

        if ($role === null) {
            return;
        }

        $role->users()->chunkById(200, function ($users) use ($policy): void {
            foreach ($users as $user) {
                $policy->materialize($user, MfaObligationTrigger::RolModificado);
            }
        });
    }
}
