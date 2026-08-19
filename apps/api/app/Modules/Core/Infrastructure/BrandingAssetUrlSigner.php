<?php

namespace App\Modules\Core\Infrastructure;

use Illuminate\Support\Facades\Storage;

/**
 * funcional.md §4.2/§4.8, operacion.md §5/§6: bucket privado, entrega
 * siempre por URL firmada de caducidad corta (`CORE_SIGNED_URL_TTL_MINUTES`).
 * Nunca se cachea la URL en sí — se regenera en cada respuesta.
 */
final class BrandingAssetUrlSigner
{
    public function sign(?string $objectKey): ?string
    {
        if ($objectKey === null) {
            return null;
        }

        return Storage::disk(config('filesystems.default'))->temporaryUrl(
            $objectKey,
            now()->addMinutes((int) config('core.signed_url_ttl_minutes')),
        );
    }
}
