<?php

namespace App\Modules\Auth\Domain;

/**
 * `api.md §F.3`, `funcional.md §F.4.2`. Lista cerrada y traducible
 * (`INV-009`) de motivos por los que un documento de descubrimiento no
 * pasa las cinco guardas. Ninguno lleva el detalle del destino
 * (`RN-AUTH-113`): el mensaje no puede convertir el *endpoint* en un
 * escáner de la red interna.
 */
enum DiscoveryFailureCode: string
{
    /** Guarda 1: la URL no es `https`. */
    case EsquemaNoAdmitido = 'esquema_no_admitido';

    /** Guarda 2: la dirección resuelta es privada, de bucle local o de enlace local. */
    case DestinoNoPublico = 'destino_no_publico';

    /** Guarda 3. */
    case DemasiadasRedirecciones = 'demasiadas_redirecciones';

    /** Guarda 4: tiempo de espera agotado o error de red. */
    case SinRespuesta = 'sin_respuesta';

    /** Guarda 4. */
    case RespuestaDemasiadoGrande = 'respuesta_demasiado_grande';

    /** Guarda 5: no es JSON, o le faltan `issuer`, `authorization_endpoint` o `token_endpoint`. */
    case DocumentoNoValido = 'documento_no_valido';

    /** Guarda 5: el `issuer` no coincide con el origen de la URL. */
    case EmisorNoCoincide = 'emisor_no_coincide';

    /** Guarda 5: algún *endpoint* declarado no es `https`. */
    case EndpointNoSeguro = 'endpoint_no_seguro';

    /** Guarda 5: falta `code` en `response_types_supported`, o `code_challenge_methods_supported` viene sin `S256`. */
    case FlujoNoAdmitido = 'flujo_no_admitido';
}
