<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.2, `POST /guardians`: `user` (`public_id` del **usuario**) y `family_unit`, obligatorios.
 */
class StoreGuardianRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{user: string, family_unit: string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $user = self::requiredReference($body, 'user', $errors);
        $unit = self::requiredReference($body, 'family_unit', $errors);

        $errors->throwIfAny();

        return ['user' => (string) $user, 'family_unit' => (string) $unit];
    }
}
