<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Infrastructure\BrandingAssetUrlSigner;
use App\Modules\Core\Infrastructure\TenantSettingsCache;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * api.md §2. `show()` es la identidad del centro (ciclo de vida en 1.6,
 * funcional.md §1.1); `branding()` es el único endpoint sin autenticación
 * del módulo (funcional.md §4.8).
 */
class TenantController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantSettingsCache $settingsCache,
        private readonly BrandingAssetUrlSigner $urlSigner,
    ) {}

    public function show(): JsonResponse
    {
        $tenant = $this->currentTenant();

        return response()->json([
            'public_id' => $tenant->public_id,
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'status' => $tenant->status->value,
        ]);
    }

    /**
     * Regla no negociable de funcional.md §4.8: no devuelve nada más que
     * esto. Ni datos fiscales, ni estado del tenant, ni módulos.
     */
    public function branding(): JsonResponse
    {
        $tenant = $this->currentTenant();
        $settings = $this->settingsCache->get();

        return response()->json([
            'name' => $tenant->name,
            'color_primary' => $settings['color_primary'],
            'color_secondary' => $settings['color_secondary'],
            'logo_url' => $this->urlSigner->sign($settings['logo_object_key']),
            'favicon_url' => $this->urlSigner->sign($settings['favicon_object_key']),
            'login_background_url' => $this->urlSigner->sign($settings['login_background_object_key']),
            'default_locale' => $settings['default_locale'],
            'active_locales' => $settings['active_locales'],
        ]);
    }

    private function currentTenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->tenantContext->tenantId());
    }
}
