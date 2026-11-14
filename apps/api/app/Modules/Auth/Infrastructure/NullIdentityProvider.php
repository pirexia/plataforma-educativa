<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\ExternalIdentity;
use App\Modules\Auth\Domain\ExternalIdentityProvider;
use RuntimeException;

/**
 * `operacion.md §E.1`, `§E.2.1`, issue #140. `AUTH_OAUTH_DRIVER=none` —el
 * valor por defecto— no tiene proveedor externo: es el estado normal de
 * cualquier despliegue recién hecho o que no quiera Google. Ningún
 * endpoint debería llegar a invocar esta interfaz en ese estado — la
 * comprobación de negocio va antes (`GET /auth/identity-providers` y
 * `POST /auth/oauth-authorizations` comprueban la configuración por su
 * cuenta, sin necesitar resolver `ExternalIdentityProvider`).
 *
 * Esta clase es **defensa en profundidad**: si algo la invoca de todas
 * formas, falla ruidosamente en vez de simular un proveedor que no
 * existe. Es exactamente el fallo que tenía el *binding* anterior de
 * `AuthServiceProvider` — su rama `default` caía en `FakeIdentityProvider`
 * también con `driver=none`, así que cualquier descuido en la
 * comprobación de negocio habría abierto en silencio el proveedor
 * simulado de desarrollo en un despliegue que no lo pidió.
 */
final class NullIdentityProvider implements ExternalIdentityProvider
{
    public function beginAuthorization(): string
    {
        throw new RuntimeException(
            'ExternalIdentityProvider invocado con AUTH_OAUTH_DRIVER=none: ningún proveedor '.
            'externo está configurado. Esto es un fallo de la comprobación de negocio que '.
            'debía impedir llegar aquí, no una configuración válida a soportar '.
            '(docs/modulos/REQ-AUTH/operacion.md §E.1).'
        );
    }

    public function completeAuthorization(): ExternalIdentity
    {
        throw new RuntimeException(
            'ExternalIdentityProvider invocado con AUTH_OAUTH_DRIVER=none: ningún proveedor '.
            'externo está configurado. Esto es un fallo de la comprobación de negocio que '.
            'debía impedir llegar aquí, no una configuración válida a soportar '.
            '(docs/modulos/REQ-AUTH/operacion.md §E.1).'
        );
    }
}
