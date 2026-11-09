<?php

namespace App\Modules\Alum\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ALUM/api.md §1, `PATCH /students/{public_id}`: los datos personales de `POST` salvo `family_unit` (la pertenencia
 * se gestiona en `fam_unit`, `RN-ALUM-04`). Solo cuentan las claves presentes. Cualquier otra clave (correo de acceso,
 * contraseña, estado del usuario, `family_unit`...) ⇒ `422 field_not_editable` y no se escribe nada (`RN-ALUM-10`).
 */
class UpdateStudentRequest extends AlumRequest
{
    private const TEXT_FIELDS = ['given_name', 'family_name_1', 'document_type', 'document_number', 'locale'];

    /**
     * @return array<string, ?string> columna de `people` ⇒ valor nuevo
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;
        $changes = [];

        foreach (array_diff(array_keys($body), [...self::TEXT_FIELDS, 'family_name_2', 'birth_date']) as $field) {
            $errors->add((string) $field, 'alum.validation.field_not_editable', 'alum.validation.field_not_editable');
        }

        foreach (self::TEXT_FIELDS as $field) {
            if (! array_key_exists($field, $body)) {
                continue;
            }

            // El par de documento resultante lo valida `Core`: aquí `null` borra el dato (como `family_name_2`).
            $nullable = in_array($field, ['document_type', 'document_number'], true);
            $changes[$field] = $nullable && $body[$field] === null ? null : self::text($body[$field], $field, $errors);
        }

        if (array_key_exists('family_name_2', $body)) {
            $changes['family_name_2'] = $body['family_name_2'] === null ? null : self::text($body['family_name_2'], 'family_name_2', $errors);
        }

        if (array_key_exists('birth_date', $body)) {
            $changes['birth_date'] = self::birthDate($body['birth_date'], $errors);
        }

        if ($changes === [] && $errors->isEmpty()) {
            $errors->add('body', 'alum.validation.no_changes', 'alum.validation.no_changes');
        }

        $errors->throwIfAny();

        return $changes;
    }
}
