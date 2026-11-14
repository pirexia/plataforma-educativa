<?php

namespace App\Modules\Core\Infrastructure\Jobs;

use App\Modules\Core\Application\BrandingAssetKind;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Support\Tenancy\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * funcional.md §4.2 paso 5, operacion.md §4/§6: el activo anterior no se
 * borra en la petición de subida (purga diferida 24h), para que un fallo
 * de escritura no deje al centro sin logo. Este trabajo borra los objetos
 * de `tenants/{tenant}/branding/{kind}/` que ya no están referenciados
 * por `tenant_settings` y llevan más de 24h huérfanos.
 */
class PurgeOrphanBrandingAssets implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $tenantId,
    ) {
        $this->onQueue('core-maintenance');
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $settings = TenantSetting::query()->first();
        $referenced = array_filter([
            $settings?->logo_object_key,
            $settings?->favicon_object_key,
            $settings?->login_background_object_key,
        ]);

        $disk = Storage::disk(config('filesystems.default'));
        $cutoff = now()->subDay();

        foreach (BrandingAssetKind::cases() as $kind) {
            $directory = "tenants/{$tenant->public_id}/branding/{$kind->value}";

            foreach ($disk->files($directory) as $objectKey) {
                if (in_array($objectKey, $referenced, true)) {
                    continue;
                }

                if ($disk->lastModified($objectKey) < $cutoff->getTimestamp()) {
                    $disk->delete($objectKey);
                }
            }
        }
    }
}
