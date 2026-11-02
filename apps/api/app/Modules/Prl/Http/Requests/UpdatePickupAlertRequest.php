<?php

namespace App\Modules\Prl\Http\Requests;

use App\Modules\Prl\Domain\Models\PickupAlert;
use App\Support\Api\ValidationErrorBag;
use Carbon\CarbonImmutable;

/**
 * REQ-PRL/api.md §8.4, `PATCH /pickup-alerts/{id}`: registra la llamada al tutor principal (`principal_contacted_at`, un
 * instante) y/o cierra la alerta con una `resolution` del vocabulario cerrado (`RN-PRL-08`) y `resolution_notes`
 * (hasta 1 000 caracteres, solo junto a la resolución). Al menos un campo; cualquier otro campo de la alerta no se edita
 * (`422 prl.validation.field_not_editable`): una alerta cerrada no se reabre por `PATCH` (`409`, `CA-PRL-008`).
 */
class UpdatePickupAlertRequest extends PrlRequest
{
    private const NOT_EDITABLE = ['status', 'student_person', 'kind', 'presenter_description', 'raised_by', 'raised_at', 'resolved_by', 'resolved_at'];

    /**
     * @return array{principal_contacted_at?: ?CarbonImmutable, resolution?: string, resolution_notes?: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;
        $changes = [];

        foreach (self::NOT_EDITABLE as $field) {
            if (array_key_exists($field, $body)) {
                $errors->add($field, 'prl.validation.field_not_editable', 'prl.validation.field_not_editable');
            }
        }

        if (array_key_exists('principal_contacted_at', $body)) {
            $changes['principal_contacted_at'] = self::optionalInstant($body, 'principal_contacted_at', $errors);
        }

        if (array_key_exists('resolution', $body)) {
            $resolution = self::requiredEnum($body, 'resolution', PickupAlert::RESOLUTIONS, $errors);

            if ($resolution !== null) {
                $changes['resolution'] = $resolution;
            }
        }

        if (array_key_exists('resolution_notes', $body)) {
            $changes['resolution_notes'] = self::optionalText($body, 'resolution_notes', PickupAlert::NOTES_MAX_LENGTH, $errors);

            if (! array_key_exists('resolution', $body)) {
                $errors->add('resolution_notes', 'prl.validation.notes_without_resolution', 'prl.validation.notes_without_resolution');
            }
        }

        if ($changes === [] && $errors->isEmpty()) {
            $errors->add('body', 'prl.validation.empty_body', 'prl.validation.empty_body');
        }

        $errors->throwIfAny();

        return $changes;
    }
}
