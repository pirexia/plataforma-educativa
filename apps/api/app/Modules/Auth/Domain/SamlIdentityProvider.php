<?php

namespace App\Modules\Auth\Domain;

/**
 * `funcional.md §G.3.6` (REQ-AUTH-004, 1.4c). Interfaz propia, en Domain,
 * con dos verbos y la misma disciplina que `ExternalIdentityProvider`.
 * Firma fijada por la especificación, copiada literalmente — no la adapta
 * `implementer`. Ninguna clase de `OneLogin\Saml2\*` cruza esta frontera
 * (`CA-AUTH-362`): quien la implementa (`OneLoginSamlIdentityProvider`)
 * es el único punto autorizado a importarlas.
 */
interface SamlIdentityProvider
{
    /**
     * Emite el `AuthnRequest` con `ID = $requestId` —el identificador que
     * la aplicación ya generó y persistió en `saml_auth_requests` antes
     * de llamar aquí (`funcional.md §G.4.3` puntos 3.4-3.5)— y devuelve la
     * URL completa de HTTP-Redirect a la que la SPA navega.
     */
    public function buildAuthnRequest(string $requestId): string;

    /**
     * Valida la aserción en bloque —firma, `Issuer`, `Destination`,
     * `Audience`, ventana temporal, `Recipient`, `InResponseTo`— antes de
     * leer un solo atributo de identidad (`RN-AUTH-119`).
     *
     * @throws ExternalIdentityException con `ExternalIdentityFailure::AssertionInvalid`
     */
    public function validateAssertion(string $samlResponse, string $expectedRequestId): SamlIdentity;
}
