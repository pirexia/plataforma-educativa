<?php

namespace App\Modules\Auth\Infrastructure;

/**
 * Motivo genérico por el que `SsrfSafeFetcher` rechaza una petición,
 * independiente del dominio (descubrimiento OIDC o metadatos SAML). Cada
 * consumidor lo traduce a su propio código de fallo de cara a la API
 * (`DiscoveryFailureCode`/`SamlMetadataFailureCode`), con los mismos
 * valores de cadena (`api.md §G.4`, `OPEN-AUTH-45`: "mismas guardas,
 * mismo cliente").
 */
enum SsrfGuardFailureReason
{
    /** Guarda 1: la URL no es `https`. */
    case UnsupportedScheme;

    /** Guarda 2: dirección privada, de bucle local o de enlace local. */
    case PrivateDestination;

    /** Guarda 3. */
    case TooManyRedirects;

    /** Guarda 4: tiempo de espera agotado o error de red. */
    case NoResponse;

    /** Guarda 4. */
    case ResponseTooLarge;
}
