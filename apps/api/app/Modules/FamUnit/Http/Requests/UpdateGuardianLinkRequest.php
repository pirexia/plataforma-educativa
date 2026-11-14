<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.4, `PATCH /guardian-links/{public_id}`: `relationship`, `is_legal_guardian`, `access_level`,
 * `restricted_categories` e `is_emergency_contact`. `status` y `revocation` se cambian solo por el recurso de
 * revocación (`CA-FAM-UNIT-037`) y el vínculo no cambia de tutor ni de alumno: enviarlos es `422 link.not_editable`.
 */
class UpdateGuardianLinkRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{relationship?: string, is_legal_guardian?: bool, access_level?: string, restricted_categories?: list<string>, is_emergency_contact?: bool}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $this->rejectFields($body, ['status', 'revocation', 'guardian', 'student_person'], 'fam_unit.link.not_editable', $errors);

        $input = [];

        $relationship = self::optionalEnum($body, 'relationship', GuardianLinkService::RELATIONSHIPS, 'fam_unit.validation.relationship_invalid', $errors);
        $legal = self::optionalBoolean($body, 'is_legal_guardian', $errors);
        $access = self::optionalEnum($body, 'access_level', GuardianLinkService::ACCESS_LEVELS, 'fam_unit.validation.access_level_invalid', $errors);
        $categories = self::optionalCategories($body, 'restricted_categories', GuardianLinkService::RESTRICTED_CATEGORIES, $errors);
        $emergency = self::optionalBoolean($body, 'is_emergency_contact', $errors);

        $errors->throwIfAny();

        foreach (['relationship' => $relationship, 'is_legal_guardian' => $legal, 'access_level' => $access, 'restricted_categories' => $categories, 'is_emergency_contact' => $emergency] as $field => $value) {
            if ($value !== null) {
                $input[$field] = $value;
            }
        }

        return $input;
    }
}
