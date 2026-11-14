<?php

namespace App\Modules\FamUnit\Http\Requests;

use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-FAM-UNIT/api.md §2.5, `POST /guardian-links/{public_id}/revocation`: `reason` obligatorio, vocabulario cerrado
 * (`resolucion_judicial`). Sin campo de texto libre a propósito (`RN-FAM-UNIT-36`).
 */
class RevokeGuardianLinkRequest extends FamUnitRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function reason(): string
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        if (! array_key_exists('reason', $body) || $body['reason'] === null) {
            $errors->add('reason', 'fam_unit.validation.required', 'fam_unit.validation.required');
            $reason = null;
        } else {
            $reason = self::optionalEnum($body, 'reason', GuardianLinkService::REVOCATION_REASONS, 'fam_unit.validation.reason_invalid', $errors);
        }

        $errors->throwIfAny();

        return (string) $reason;
    }
}
