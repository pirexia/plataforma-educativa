<?php

namespace App\Modules\Auth\Domain;

/**
 * datos.md §C.5, funcional.md §C.4.8/§C.4.10/§C.4.11. Los cinco
 * disparadores por los que se abre una fila de `user_mfa_obligations`, y
 * ninguno más (mismo criterio que `SessionEndReason`, issue #61: no
 * reutilizar un valor que no corresponde por no tener el correcto).
 */
enum MfaObligationTrigger: string
{
    /** `PATCH /roles/{public_id}` pone `mfa_required = true` en un rol. */
    case RolModificado = 'rol_modificado';

    /** Se asigna a un usuario un rol que ya exigía MFA (materialización perezosa, §C.4.8). */
    case RolAsignado = 'rol_asignado';

    /** El tenant retira de `mfa_allowed_methods` el único método utilizable del usuario. */
    case MetodoRetirado = 'metodo_retirado';

    /** `POST /mfa-resets`: el administrador restablece el MFA del usuario. */
    case Restablecimiento = 'restablecimiento';

    /** La excepción temporal nominal del usuario caduca. */
    case ExencionVencida = 'exencion_vencida';
}
