<?php

namespace App\Modules\Auth\Application;

/**
 * `operacion.md §E.1`, `§E.2.1`, issue #140. Punto único de la pregunta
 * «¿hay proveedor externo configurado?», que necesitan tanto
 * `GET /auth/identity-providers` (para decidir la colección) como
 * `POST /auth/oauth-authorizations` (para el `422` de negocio) — sin
 * duplicar el criterio en dos sitios.
 *
 * `google` (con las guardas de arranque ya satisfechas: secreto presente,
 * HTTPS) y `fake` (proveedor simulado, solo en `local`/`testing`) cuentan
 * como configurado. `none` —el valor por defecto— no.
 */
final class OAuthProviderAvailability
{
    public function isConfigured(): bool
    {
        return in_array((string) config('auth-local.oauth.driver'), ['google', 'fake'], true);
    }
}
