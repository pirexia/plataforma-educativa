<?php

namespace App\Modules\Prl\Http\Requests;

use App\Modules\Prl\Domain\Models\PickupAlert;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-PRL/api.md §8.4, `POST /pickup-alerts`: `student_person` y `presenter_description` (nombre declarado y documento exhibido
 * por quien se presenta; texto de 1 a 500 caracteres, `RN-PRL-08`) obligatorios; `kind` opcional con un único valor en
 * `1.14b-b` (`persona_no_autorizada`; `alumno_no_recogido` es de `1.14b-d`).
 */
class StorePickupAlertRequest extends PrlRequest
{
    /**
     * @return array{student_person: string, kind: string, presenter_description: string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $studentPerson = self::requiredReference($body, 'student_person', $errors);
        $kind = self::optionalEnum($body, 'kind', PickupAlert::KINDS, $errors) ?? PickupAlert::KIND_UNAUTHORIZED_PERSON;
        $description = self::optionalText($body, 'presenter_description', PickupAlert::DESCRIPTION_MAX_LENGTH, $errors);

        if ($description === null && ! array_key_exists('presenter_description', $errors->toArray())) {
            $errors->add('presenter_description', 'prl.validation.required', 'prl.validation.required');
        }

        $errors->throwIfAny();

        return [
            'student_person' => (string) $studentPerson,
            'kind' => $kind,
            'presenter_description' => (string) $description,
        ];
    }
}
