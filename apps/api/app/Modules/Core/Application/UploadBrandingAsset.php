<?php

namespace App\Modules\Core\Application;

use App\Modules\Core\Domain\Models\TenantSetting;
use App\Support\Api\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * funcional.md §4.2, api.md §2.2, operacion.md §5. `RSEC-OWASP-012`/
 * `RN-CORE-18`: tipo real por contenido (nunca por extensión ni por
 * `Content-Type` declarado), tamaño máximo por tipo de activo, SVG
 * saneado antes de escribir. El activo anterior no se borra aquí — queda
 * huérfano y lo retira `PurgeOrphanBrandingAssets` pasadas 24h
 * (funcional.md §4.2 paso 5: si la escritura fallara, el centro no se
 * queda sin logo).
 */
final class UploadBrandingAsset
{
    /**
     * Extensión declarada (por el nombre de fichero del cliente) -> tipo
     * MIME real que debería tener. RN-CORE-18: un archivo cuyo contenido
     * no coincide con lo que su propia extensión declara se rechaza,
     * aunque el tipo real detectado esté permitido para el `kind` (p. ej.
     * un PNG servido con extensión .svg).
     *
     * @var array<string, list<string>>
     */
    private const EXTENSION_TO_MIME = [
        'svg' => ['image/svg+xml'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];

    /**
     * @var array<string, string>
     */
    private const MIME_TO_EXTENSION = [
        'image/svg+xml' => 'svg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/jpeg' => 'jpg',
    ];

    public function __construct(
        private readonly SvgSanitizer $svgSanitizer,
    ) {}

    public function upload(UploadedFile $file, BrandingAssetKind $kind, TenantSetting $settings, string $tenantPublicId): string
    {
        $this->assertSize($file, $kind);

        $realMime = $this->detectRealMimeType($file);

        $this->assertAllowedType($realMime, $kind);
        $this->assertDeclaredMatchesReal($file, $realMime);

        $content = file_get_contents($file->getRealPath());

        if ($content === false) {
            throw ApiException::validation([
                'file' => [[
                    'code' => 'core.validation.file_type_mismatch',
                    'message' => __('core.validation.file_type_mismatch'),
                    'params' => [],
                ]],
            ]);
        }

        if ($realMime === 'image/svg+xml') {
            $content = $this->svgSanitizer->sanitize($content);
        }

        $extension = self::MIME_TO_EXTENSION[$realMime] ?? 'bin';
        $objectKey = sprintf(
            'tenants/%s/branding/%s/%s.%s',
            $tenantPublicId,
            $kind->value,
            (string) Str::ulid(),
            $extension,
        );

        Storage::disk(config('filesystems.default'))->put($objectKey, $content);

        $settings->{$kind->settingsColumn()} = $objectKey;

        return $objectKey;
    }

    private function assertSize(UploadedFile $file, BrandingAssetKind $kind): void
    {
        if ($file->getSize() !== false && $file->getSize() > $kind->maxSizeBytes()) {
            throw ApiException::payloadTooLarge();
        }
    }

    private function detectRealMimeType(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $path !== false ? $finfo->file($path) : false;

        return $detected !== false ? $detected : 'application/octet-stream';
    }

    private function assertAllowedType(string $realMime, BrandingAssetKind $kind): void
    {
        if (! in_array($realMime, $kind->allowedMimeTypes(), true)) {
            throw ApiException::unsupportedMediaType();
        }
    }

    private function assertDeclaredMatchesReal(UploadedFile $file, string $realMime): void
    {
        $originalName = $file->getClientOriginalName();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === '' || ! isset(self::EXTENSION_TO_MIME[$extension])) {
            return;
        }

        if (! in_array($realMime, self::EXTENSION_TO_MIME[$extension], true)) {
            throw ApiException::validation([
                'file' => [[
                    'code' => 'core.validation.file_type_mismatch',
                    'message' => __('core.validation.file_type_mismatch'),
                    'params' => [],
                ]],
            ]);
        }
    }
}
