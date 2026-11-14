<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\StructureFields;

/**
 * Referencias por `public_id` del cuerpo (usuario, grupo, asignatura, franja…):
 * una cadena no vacía y guardable. Cualquier otra cosa es «no encontrado» en
 * su campo (el llamador elige el código), nunca un error de motor (`S-2`).
 */
trait ReadsReferences
{
    private function reference(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || ! StructureFields::isSafeText($value)) {
            return null;
        }

        return trim($value);
    }
}
