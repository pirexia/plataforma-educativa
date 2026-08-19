<?php

namespace App\Modules\Core\Application;

/**
 * api.md §2.2/§2.3, operacion.md §5. `kind` de `PUT`/`DELETE
 * /tenant/settings/assets/{kind}`. Los límites de tipo y tamaño son los
 * que fija `api.md`: `login-background` no admite SVG.
 */
enum BrandingAssetKind: string
{
    case Logo = 'logo';
    case Favicon = 'favicon';
    case LoginBackground = 'login-background';

    /**
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::Logo => ['image/svg+xml', 'image/png', 'image/webp'],
            self::Favicon => ['image/svg+xml', 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
            self::LoginBackground => ['image/jpeg', 'image/png', 'image/webp'],
        };
    }

    public function maxSizeBytes(): int
    {
        return match ($this) {
            self::Logo => 1_048_576,
            self::Favicon => 262_144,
            self::LoginBackground => 3_145_728,
        };
    }

    public function settingsColumn(): string
    {
        return match ($this) {
            self::Logo => 'logo_object_key',
            self::Favicon => 'favicon_object_key',
            self::LoginBackground => 'login_background_object_key',
        };
    }
}
