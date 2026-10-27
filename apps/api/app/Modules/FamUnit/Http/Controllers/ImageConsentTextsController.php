<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Modules\FamUnit\Application\ImageConsentService;
use App\Modules\FamUnit\Application\TermsVersionService;
use App\Modules\FamUnit\Http\Requests\IndexImageConsentTextsRequest;
use Illuminate\Http\JsonResponse;

/**
 * REQ-FAM-UNIT/api.md §1.9, §2.7 (1.14-b, `RN-FAM-UNIT-75`, `OPEN-FAM-UNIT-23`): el texto **publicado** del consentimiento
 * de cada nivel, en el idioma de la petición con la caída de `ADR-062`, para que el tutor sepa qué autoriza antes de
 * `POST /image-consent-decisions` por el canal `portal`. Permiso `consentimiento_imagen.leer` con cualquiera de sus dos
 * ámbitos, pero **no pasa por `ScopedQuery`**: el texto es del centro, igual para todos y sin datos de ningún alumno
 * ni tutor (también lo lee un tutor sin vínculos activos). Nunca devuelve `borrador` ni `retirada`. Solo lectura, sin
 * auditoría de lectura (no es categoría especial).
 */
class ImageConsentTextsController extends FamUnitController
{
    public function index(IndexImageConsentTextsRequest $request, TermsVersionService $terms): JsonResponse
    {
        $levels = $request->levels();
        $data = [];

        foreach (['web', 'redes_sociales'] as $level) {
            if (! in_array($level, $levels, true)) {
                continue;
            }

            $version = $terms->published(ImageConsentService::kindOf($level));

            if ($version === null) {
                $data[] = ['level' => $level, 'terms_version' => null, 'title' => null, 'body' => null, 'locale' => null, 'published_at' => null];

                continue;
            }

            $resolved = $terms->resolve($version);
            $data[] = [
                'level' => $level,
                'terms_version' => ['public_id' => $version->public_id, 'kind' => $version->kind, 'version_number' => (int) $version->version_number],
                'title' => $resolved['title'],
                'body' => $resolved['body'],
                'locale' => $resolved['locale'],
                'published_at' => $version->published_at,
            ];
        }

        return response()->json(['data' => $data]);
    }
}
