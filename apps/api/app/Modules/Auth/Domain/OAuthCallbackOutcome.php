<?php

namespace App\Modules\Auth\Domain;

/**
 * `api.md §E.4.2`, `funcional.md §E.4.2`/`§E.4.6`. Lista cerrada de
 * códigos de resultado del *callback* de OAuth. `RN-AUTH-93`: es lo único
 * que viaja en la URL del `302` —nunca un token, un correo ni un
 * `public_id`— y es lo que hace traducible la pantalla `/entrar/google`
 * sin literales en el código (`INV-009`). El éxito (login completado) no
 * tiene caso propio: es la ausencia de `resultado` en la redirección.
 *
 * Ningún caso lleva sufijo con el detalle (`sin_cuenta` no se desdobla en
 * "sin verificar"/"sin cuenta", `acceso_denegado` no dice qué estado
 * tiene la cuenta, `proveedor_ya_vinculado` no nombra al otro usuario):
 * es la misma disciplina de `§4.7` aplicada a este canal.
 */
enum OAuthCallbackOutcome: string
{
    /** `funcional.md §E.4.2` paso 8.3: se abrió desafío de MFA. */
    case SegundoFactor = 'segundo_factor';

    /** Sesión creada pero restringida: obligado, sin factor, gracia vencida. */
    case AltaMfaRequerida = 'alta_mfa_requerida';

    /** `intent = link` completado (`funcional.md §E.4.4`). */
    case Vinculado = 'vinculado';

    /**
     * Sin vínculo y, o el correo no venía verificado, o no hay cuenta
     * local con ese correo. Un solo código para los dos casos a propósito
     * (`RN-AUTH-87`, `datos.md §E.3.2`): distinguirlos convertiría una
     * cuenta de Google no verificada en un comprobador de altas del
     * centro (`funcional.md §E.4.6`).
     */
    case SinCuenta = 'sin_cuenta';

    /** Bloqueo vivo para `(tenant_id, email)` (`funcional.md §E.6`). */
    case CuentaBloqueada = 'cuenta_bloqueada';

    /** Usuario `pendiente`, `inactivo` o borrado. Salida genérica. */
    case AccesoDenegado = 'acceso_denegado';

    /** `intent = link`: el usuario ya tenía un vínculo vivo de Google. */
    case YaVinculado = 'ya_vinculado';

    /** `intent = link`: esa cuenta de Google ya está vinculada a otro usuario. */
    case ProveedorYaVinculado = 'proveedor_ya_vinculado';

    /** La persona canceló en Google (`error=access_denied`). No es un fallo. */
    case Cancelado = 'cancelado';

    /**
     * `state` ausente, distinto, caducado o ya consumido. También cubre
     * `AUTH_OAUTH_DRIVER=none` sin rama propia (`operacion.md §E.1`,
     * issue #140): con `none` nadie ha podido arrancar el flujo, así que
     * no hay `state` que comparar.
     */
    case EstadoNoValido = 'estado_no_valido';

    /** Fallo al canjear el código, Google no responde, o límite de tasa excedido. */
    case ErrorProveedor = 'error_proveedor';
}
