<?php

namespace App\Modules\Auth\Domain;

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * `RN-AUTH-92`, `funcional.md §F.3.1`. Una sola URI de *callback* por
 * tenant, la misma para cualquier proveedor catalogado. Extraído a un
 * único sitio porque `api.md §F.2` exige que el valor que la pantalla de
 * administración muestra sea, carácter a carácter, el que construye el
 * flujo real (`GenericOidcProvider`) — dos implementaciones
 * independientes del mismo cálculo son exactamente el riesgo que eso
 * previene.
 */
final class OidcRedirectUri
{
    public static function build(TenantContext $tenantContext): string
    {
        $tenant = Tenant::query()->find($tenantContext->tenantId());
        $slug = $tenant->slug ?? '';
        $baseDomain = (string) config('tenancy.base_domain');

        return "https://{$slug}.{$baseDomain}/api/v1/auth/oauth/oidc/callback";
    }
}
