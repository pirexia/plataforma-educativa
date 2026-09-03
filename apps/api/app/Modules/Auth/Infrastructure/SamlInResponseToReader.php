<?php

namespace App\Modules\Auth\Infrastructure;

use DOMDocument;

/**
 * `funcional.md §G.4.3` punto 8, `RN-AUTH-120` (REQ-AUTH-004, 1.4c). Lee
 * el `InResponseTo` de un `SAMLResponse` **antes** de cualquier
 * verificación de firma, para resolver la fila de `saml_auth_requests`
 * contra la que correlacionar (`api.md §G.7.2`: *"InResponseTo ausente,
 * sin fila viva, ya consumida o caducada ⇒ `estado_no_valido`"*, un
 * código **distinto** de `error_proveedor`, que es el que cubre las
 * demás validaciones de `RN-AUTH-119`).
 *
 * Leer un atributo sin verificar la firma no es una decisión de
 * confianza: el valor solo se usa como clave de búsqueda en una tabla
 * propia, y todo lo que de verdad importa (firma, `Issuer`, `Destination`,
 * `Audience`) se valida después, dentro del envoltorio
 * (`OneLoginSamlIdentityProvider::validateAssertion()`), sobre la fila ya
 * resuelta. **No cruza `OneLogin\Saml2\*`**: es un análisis de XML propio,
 * con la misma guarda XXE que `CurlSamlMetadataValidator`.
 */
final class SamlInResponseToReader
{
    public function read(string $base64SamlResponse): ?string
    {
        $decoded = base64_decode($base64SamlResponse, true);

        if ($decoded === false || $decoded === '') {
            return null;
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument;
        $loaded = @$dom->loadXML($decoded, LIBXML_NONET);

        $hasDoctype = false;

        if ($loaded) {
            foreach ($dom->childNodes as $child) {
                if ($child->nodeType === XML_DOCUMENT_TYPE_NODE) {
                    $hasDoctype = true;

                    break;
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (! $loaded || $hasDoctype || $dom->documentElement === null) {
            return null;
        }

        $inResponseTo = $dom->documentElement->getAttribute('InResponseTo');

        return $inResponseTo !== '' ? $inResponseTo : null;
    }
}
