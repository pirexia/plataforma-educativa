<?php

namespace App\Modules\Auth\Domain;

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * `RN-AUTH-116`, `funcional.md §G.3.4` (REQ-AUTH-004, 1.4c). `entityId`
 * del SP y ACS URL, siempre derivados del *host* del tenant y nunca
 * compartidos entre centros — fuga entre tenants por diseño si no lo
 * fueran (`INV-001`, severidad crítica). Extraído a un único sitio por el
 * mismo motivo que `OidcRedirectUri` (`§F.3.1`): el valor que la pantalla
 * de administración muestra tiene que ser, carácter a carácter, el que
 * construye el flujo real.
 *
 * `entityId` es **por tenant** (una sola URL para todos los proveedores
 * SAML del centro); la ACS URL es **por proveedor** (`§G.3.4`: la clave
 * con la que se verifica una firma nunca puede elegirse a partir del
 * contenido del mensaje, así que el proveedor va en la ruta). No hace
 * falta que `entityId` resuelva a nada servido: es un identificador
 * opaco, con forma de URL por convención de SAML 2.0, no una URL que se
 * publique en un `GET` propio (`api.md §G.3.1`: nuestros metadatos de SP
 * no son anónimos).
 */
final class SamlSpUrls
{
    public static function entityId(TenantContext $tenantContext): string
    {
        return self::baseUrl($tenantContext).'/api/v1/auth/saml/entity';
    }

    public static function acsUrl(TenantContext $tenantContext, string $providerPublicId): string
    {
        return self::baseUrl($tenantContext)."/api/v1/auth/saml/{$providerPublicId}/acs";
    }

    private static function baseUrl(TenantContext $tenantContext): string
    {
        $tenant = Tenant::query()->find($tenantContext->tenantId());
        $slug = $tenant->slug ?? '';
        $baseDomain = (string) config('tenancy.base_domain');

        return "https://{$slug}.{$baseDomain}";
    }
}
