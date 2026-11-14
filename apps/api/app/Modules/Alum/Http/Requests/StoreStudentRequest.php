<?php

namespace App\Modules\Alum\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ALUM/api.md §1, `POST /students`: `given_name`, `family_name_1` y `birth_date` obligatorios; `family_name_2`,
 * `document_type`/`document_number` (catálogo de `Core`, lo valida `Core`), `locale` (entre los activos del centro, lo
 * valida `Core`) y `family_unit` (`public_id`) opcionales. Los nombres de campo son los de la ampliación de `Core`
 * (`PersonDataCorrection`, `OPEN-ALUM-19`).
 */
class StoreStudentRequest extends AlumRequest
{
    /**
     * @return array{given_name: string, family_name_1: string, family_name_2: ?string, birth_date: string, document_type: ?string, document_number: ?string, locale: ?string, family_unit: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $value = static fn (string $field): mixed => $body[$field] ?? null;
        $optional = static fn (string $field): ?string => $value($field) === null ? null : self::text($value($field), $field, $errors);

        $givenName = $value('given_name') === null ? null : self::text($value('given_name'), 'given_name', $errors);
        $familyName1 = $value('family_name_1') === null ? null : self::text($value('family_name_1'), 'family_name_1', $errors);
        $birthDate = $value('birth_date') === null ? null : self::birthDate($value('birth_date'), $errors);

        foreach (['given_name', 'family_name_1', 'birth_date'] as $field) {
            if ($value($field) === null) {
                self::required($field, $errors);
            }
        }

        $familyName2 = $optional('family_name_2');
        $documentType = $optional('document_type');
        $documentNumber = $optional('document_number');
        $locale = $optional('locale');

        $familyUnit = null;

        if ($value('family_unit') !== null) {
            $familyUnit = is_string($value('family_unit')) && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}\z/', $value('family_unit')) === 1
                ? $value('family_unit')
                : null;

            if ($familyUnit === null) {
                $errors->add('family_unit', 'alum.validation.family_unit_not_found', 'alum.validation.family_unit_not_found');
            }
        }

        $errors->throwIfAny();

        return [
            'given_name' => (string) $givenName,
            'family_name_1' => (string) $familyName1,
            'family_name_2' => $familyName2,
            'birth_date' => (string) $birthDate,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'locale' => $locale,
            'family_unit' => $familyUnit,
        ];
    }
}
