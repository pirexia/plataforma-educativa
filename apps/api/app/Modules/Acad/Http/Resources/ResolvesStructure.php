<?php

namespace App\Modules\Acad\Http\Resources;

use App\Http\Middleware\ResolveApiLocale;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Support\I18n\TranslationResolver;

/**
 * Piezas comunes de las representaciones de la estructura académica
 * (`api.md §1`): el `public_id` del curso académico por la interfaz de
 * `Curso\Domain` (memoizada por petición) y el nombre resuelto en servidor
 * (`ADR-062 §5.3`): idioma de la petición → `default_locale` → `es-ES` →
 * primera clave. No se exponen `id`, `*_id`, `created_by`, `updated_by` ni
 * `deleted_at`.
 */
trait ResolvesStructure
{
    private function academicYearPublicId(int $academicYearId): ?string
    {
        return app(AcademicYearDirectory::class)->findById($academicYearId)?->publicId;
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function resolvedName(array $translations): string
    {
        $requested = array_search(app()->getLocale(), ResolveApiLocale::TO_LARAVEL_LOCALE, true);

        return TranslationResolver::text(
            $translations,
            is_string($requested) ? $requested : null,
            app(TenantSettingsReader::class)->defaultLocale(),
        );
    }
}
