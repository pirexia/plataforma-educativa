<?php

namespace App\Modules\Auth\Domain;

/**
 * `ADR-042 §4.3`. Firma fijada por el ADR, copiada literalmente — no la
 * adapta `implementer`. Interfaz de un solo proveedor a propósito
 * (`ADR-042 §4.3` último párrafo): no lleva `string $provider` ni se
 * generaliza a un registro. `SocialiteGoogleIdentityProvider` (real) y
 * `FakeIdentityProvider` (proveedor simulado, `operacion.md §E.10`) son
 * sus dos únicas implementaciones.
 */
interface ExternalIdentityProvider
{
    /**
     * Inicia el flujo: devuelve la URL de autorización del proveedor.
     *
     * Devuelve una cadena, NO una RedirectResponse (§4.5).
     * Tiene efecto de sesión: guarda `state` y, con PKCE, `code_verifier`.
     * El nombre lo dice a propósito — no es un getter puro.
     */
    public function beginAuthorization(): string;

    /**
     * Resuelve el callback y devuelve la identidad ya normalizada.
     *
     * @throws ExternalIdentityException
     */
    public function completeAuthorization(): ExternalIdentity;
}
