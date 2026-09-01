<?php

namespace App\Modules\Auth\Domain;

/**
 * `ADR-042 §4.4`. Tres motivos distinguibles: el controlador del
 * *callback* tiene que hacer tres cosas distintas con cada uno.
 */
enum ExternalIdentityFailure
{
    /** Google devuelve `error=access_denied` (la persona canceló). No es un incidente. */
    case ConsentDenied;

    /** `state` ausente, distinto o caducado. Señal de CSRF o de sesión perdida. */
    case InvalidState;

    /** Fallo HTTP contra el proveedor o respuesta ilegible. Transitorio. */
    case ProviderUnreachable;
}
