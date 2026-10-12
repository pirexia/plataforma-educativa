<?php

namespace App\Modules\Acad\Http\Requests;

use App\Http\Requests\ApiFormRequest;

/**
 * Base de las peticiones de escritura de la estructura académica. La forma
 * (tipos, longitud) se valida en `rules()`; la validación de negocio
 * (`INV-010`) en `payload()`, con los códigos de `api.md §6`, que el
 * controlador invoca nada más resolver el recurso de la ruta (así un grupo
 * inexistente es `404` y no `422`, `CA-ACAD-007`).
 */
abstract class StructureRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        // Cuerpo JSON en crudo: `ConvertEmptyStringsToNull` y `TrimStrings` convertirían
        // `""` en `null`, y en un nombre traducible `""` es `422 empty_value` mientras que
        // `null` quita el idioma (api.md §2.1, `ADR-062 §5.2`).
        $decoded = json_decode($this->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
