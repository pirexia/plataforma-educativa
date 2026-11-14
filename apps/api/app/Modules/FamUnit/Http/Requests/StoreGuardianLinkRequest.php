<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.4, `POST /guardian-links`: `guardian`, `student_person` y `relationship` obligatorios;
 * `is_legal_guardian` (defecto `false`), `access_level` (defecto `lectura`, el más restrictivo), `restricted_categories`
 * (defecto `[]`) e `is_emergency_contact` (defecto `false`). Los enumerados son vocabularios cerrados (`RN-FAM-UNIT-32`).
 */
class StoreGuardianLinkRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{guardian: string, student_person: string, relationship: string, is_legal_guardian: bool, access_level: string, restricted_categories: list<string>, is_emergency_contact: bool}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $guardian = self::requiredReference($body, 'guardian', $errors);
        $student = self::requiredReference($body, 'student_person', $errors);

        if (! array_key_exists('relationship', $body) || $body['relationship'] === null) {
            $errors->add('relationship', 'fam_unit.validation.required', 'fam_unit.validation.required');
            $relationship = null;
        } else {
            $relationship = self::optionalEnum($body, 'relationship', GuardianLinkService::RELATIONSHIPS, 'fam_unit.validation.relationship_invalid', $errors);
        }

        $legal = self::optionalBoolean($body, 'is_legal_guardian', $errors);
        $access = self::optionalEnum($body, 'access_level', GuardianLinkService::ACCESS_LEVELS, 'fam_unit.validation.access_level_invalid', $errors);
        $categories = self::optionalCategories($body, 'restricted_categories', GuardianLinkService::RESTRICTED_CATEGORIES, $errors);
        $emergency = self::optionalBoolean($body, 'is_emergency_contact', $errors);

        $errors->throwIfAny();

        return [
            'guardian' => (string) $guardian,
            'student_person' => (string) $student,
            'relationship' => (string) $relationship,
            'is_legal_guardian' => $legal ?? false,
            'access_level' => $access ?? 'lectura',
            'restricted_categories' => $categories ?? [],
            'is_emergency_contact' => $emergency ?? false,
        ];
    }
}
