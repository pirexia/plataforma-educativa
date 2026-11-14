<?php

namespace App\Modules\Auth\Domain;

/**
 * `ADR-042 §4.4`, ampliado en `funcional.md §F.3.2`/`§F.4.4` (1.4b) y en
 * `funcional.md §G.3.6` (REQ-AUTH-004, 1.4c). El controlador del *callback*
 * tiene que hacer algo distinto con cada uno — salvo `IdTokenInvalid` y
 * `AssertionInvalid`, que `funcional.md §F.7.1`/`§G.3.6` agrupan a
 * propósito con `ProviderUnreachable` bajo el mismo código de salida
 * (`error_proveedor`): distinguirlos no ayuda a quien está delante, y sí
 * ayudaría a quien esté probando qué validaciones tenemos. El detalle de
 * cuál de los puntos falló va al registro de aplicación
 * (`operacion.md §F.8`/`§G.8`), no a la pantalla.
 */
enum ExternalIdentityFailure
{
    /** El proveedor devuelve `error=access_denied` (la persona canceló). No es un incidente. */
    case ConsentDenied;

    /** `state` ausente, distinto o caducado. Señal de CSRF o de sesión perdida. */
    case InvalidState;

    /** Fallo HTTP contra el proveedor o respuesta ilegible. Transitorio. Sin uso en SAML: no hay canal trasero en el perfil `POST` (`funcional.md §G.3.6`). */
    case ProviderUnreachable;

    /**
     * `RN-AUTH-104` (1.4b). El `id_token` no valida en alguno de sus
     * cinco puntos (`iss`, `aud`, `exp`, `iat`, `nonce`), o el `sub` de
     * `userinfo` no coincide con el del `id_token` (`RN-AUTH-105`).
     */
    case IdTokenInvalid;

    /**
     * `RN-AUTH-107` (1.4b). El dominio del correo no está admitido, o el
     * emisor es Google y falta el *claim* `hd` admitido. Se evalúa
     * dentro del adaptador porque necesita los *claims* crudos y la
     * configuración del proveedor a la vez (`funcional.md §F.4.4`).
     */
    case DomainNotAllowed;

    /**
     * `RN-AUTH-117` a `RN-AUTH-119` (REQ-AUTH-004, 1.4c). Cualquiera de
     * las ocho validaciones de una aserción SAML falla: firma ausente o
     * que no valida, `Issuer`/`Destination`/`Audience` que no casan,
     * ventana temporal fuera de tolerancia, `Recipient` incorrecto.
     * **No** cubre `InResponseTo`/repetición —eso es `estado_no_valido`,
     * resuelto por la correlación antes de llegar al envoltorio— ni la
     * ausencia de `NameID` —eso es `sin_cuenta` (`RN-AUTH-123`)—.
     */
    case AssertionInvalid;
}
