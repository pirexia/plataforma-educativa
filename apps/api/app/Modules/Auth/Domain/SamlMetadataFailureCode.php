<?php

namespace App\Modules\Auth\Domain;

/**
 * `api.md §G.4`, `funcional.md §G.4.2` (REQ-AUTH-004, 1.4c). Lista cerrada
 * y traducible (`INV-009`) de motivos por los que unos metadatos de IdP no
 * pasan las guardas. Ninguno lleva el detalle del destino (`RN-AUTH-113`).
 *
 * Los cinco primeros son literalmente los mismos códigos —mismo valor de
 * cadena— que `DiscoveryFailureCode` cuando el origen es una URL: las
 * cinco guardas SSRF de `CurlDiscoveryDocumentValidator` se reutilizan
 * sin relajación (`OPEN-AUTH-45`). Los siete siguientes son la guarda de
 * contenido, propia de SAML.
 */
enum SamlMetadataFailureCode: string
{
    /** Guarda SSRF 1: la URL no es `https`. */
    case EsquemaNoAdmitido = 'esquema_no_admitido';

    /** Guarda SSRF 2: dirección privada, de bucle local o de enlace local. */
    case DestinoNoPublico = 'destino_no_publico';

    /** Guarda SSRF 3. */
    case DemasiadasRedirecciones = 'demasiadas_redirecciones';

    /** Guarda SSRF 4: tiempo de espera agotado o error de red. */
    case SinRespuesta = 'sin_respuesta';

    /** Guarda SSRF 4. */
    case RespuestaDemasiadoGrande = 'respuesta_demasiado_grande';

    /** Guarda de contenido 1: XML mal formado, o que declara una entidad externa o una DTD (XXE). */
    case MetadatosNoValidos = 'metadatos_no_validos';

    /** Guarda de contenido 2: tope de tamaño o de profundidad de anidamiento del documento. */
    case MetadatosDemasiadoGrandes = 'metadatos_demasiado_grandes';

    /** Guarda de contenido 3: no hay exactamente un `EntityDescriptor`/`IDPSSODescriptor`, o vinieron los dos orígenes a la vez. */
    case MetadatosAmbiguos = 'metadatos_ambiguos';

    /** Guarda de contenido 4: sin `SingleSignOnService` con *binding* HTTP-Redirect y `https`. */
    case BindingNoAdmitido = 'binding_no_admitido';

    /** Guarda de contenido 5: ningún `KeyDescriptor use="signing"` con un X.509 analizable y no caducado. */
    case SinCertificadoDeFirma = 'sin_certificado_de_firma';

    /** Guarda de contenido 6: el `NameIDFormat` declarado no está en la lista blanca (incluye `transient`). */
    case FormatoDeIdentificadorNoAdmitido = 'formato_de_identificador_no_admitido';

    /** Guarda de contenido 7: el `entityID` ya está catalogado vivo en este centro. Se devuelve como `409`, no `422`. */
    case EmisorYaCatalogado = 'emisor_ya_catalogado';
}
