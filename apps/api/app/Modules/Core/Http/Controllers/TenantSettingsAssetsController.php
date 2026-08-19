<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Application\BrandingAssetKind;
use App\Modules\Core\Application\UploadBrandingAsset;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Http\Requests\PutBrandingAssetRequest;
use App\Modules\Core\Infrastructure\BrandingAssetUrlSigner;
use App\Modules\Core\Infrastructure\TenantSettingsCache;
use App\Support\Api\ApiException;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * api.md §2.2/§2.3, funcional.md §4.2. El activo anterior no se borra en
 * la petición (purga diferida, `PurgeOrphanBrandingAssets`): si la
 * escritura fallara, el centro no se queda sin logo.
 */
class TenantSettingsAssetsController extends Controller
{
    public function __construct(
        private readonly UploadBrandingAsset $uploader,
        private readonly TenantSettingsCache $cache,
        private readonly BrandingAssetUrlSigner $urlSigner,
        private readonly TenantContext $tenantContext,
    ) {}

    public function update(PutBrandingAssetRequest $request, string $kind): JsonResponse
    {
        $assetKind = $this->resolveKind($kind);
        $settings = $this->currentOrDefault();
        $tenant = Tenant::query()->findOrFail($this->tenantContext->tenantId());

        $this->uploader->upload($request->file('file'), $assetKind, $settings, $tenant->public_id);
        $settings->save();

        $this->cache->forget();

        return response()->json([
            'kind' => $assetKind->value,
            'url' => $this->urlSigner->sign($settings->{$assetKind->settingsColumn()}),
        ]);
    }

    public function destroy(string $kind): JsonResponse
    {
        $assetKind = $this->resolveKind($kind);
        $settings = TenantSetting::query()->first();
        $column = $assetKind->settingsColumn();

        if ($settings === null || $settings->{$column} === null) {
            throw ApiException::notFound();
        }

        $settings->{$column} = null;
        $settings->save();

        $this->cache->forget();

        return response()->json(null, 204);
    }

    private function resolveKind(string $kind): BrandingAssetKind
    {
        return BrandingAssetKind::tryFrom($kind) ?? throw ApiException::notFound();
    }

    private function currentOrDefault(): TenantSetting
    {
        return TenantSetting::query()->first() ?? new TenantSetting([
            'default_locale' => 'es-ES',
            'active_locales' => ['es-ES'],
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'fiscal_country_code' => 'ES',
        ]);
    }
}
