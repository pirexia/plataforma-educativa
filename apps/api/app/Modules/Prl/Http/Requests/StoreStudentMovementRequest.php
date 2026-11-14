<?php

namespace App\Modules\Prl\Http\Requests;

use App\Modules\Prl\Domain\Models\StudentMovement;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-PRL/api.md §8.4, `POST /student-movements`: solo la **forma** del cuerpo y las coherencias que no necesitan datos
 * (`INV-010`: las de negocio —la persona figura, el método con un tutor sin fotografía— son del servicio).
 *
 * - `student_person` (obligatorio), `direction` (`entrada`|`salida`), `picked_up_by`, `verification_method`, `delivered_by`
 *   (`public_id` de un usuario; nulo = quien registra), `off_schedule` (por defecto `false`), `off_schedule_reason`, `notes`,
 *   `corrects` (`public_id` de un movimiento) y `correction_reason`.
 * - Una **salida** exige persona que recoge y método de verificación (la salida autónoma es de `1.14b-d`, `datos.md §8.4`);
 *   una **entrada** no lleva ninguno de los dos (`datos.md §8.4`: persona, base y método aparecen juntos).
 * - Fuera de horario exige motivo (`422 prl.movement.reason_required`, `RN-PRL-07`) y el motivo `otro`, además una nota; con
 *   `off_schedule = false` no hay motivo. Una corrección lleva motivo y un motivo de corrección exige la corrección.
 */
class StoreStudentMovementRequest extends PrlRequest
{
    /**
     * @return array{student_person: string, direction: string, picked_up_by: ?string, verification_method: ?string, delivered_by: ?string, off_schedule: bool, off_schedule_reason: ?string, notes: ?string, corrects: ?string, correction_reason: ?string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $studentPerson = self::requiredReference($body, 'student_person', $errors);
        $direction = self::requiredEnum($body, 'direction', StudentMovement::DIRECTIONS, $errors);
        $pickedUpBy = self::optionalReference($body, 'picked_up_by', $errors);
        $method = self::optionalEnum($body, 'verification_method', StudentMovement::VERIFICATION_METHODS, $errors);
        $deliveredBy = self::optionalReference($body, 'delivered_by', $errors);
        $offSchedule = self::optionalBoolean($body, 'off_schedule', false, $errors);
        $reason = self::optionalEnum($body, 'off_schedule_reason', StudentMovement::OFF_SCHEDULE_REASONS, $errors);
        $notes = self::optionalText($body, 'notes', StudentMovement::NOTES_MAX_LENGTH, $errors);
        $corrects = self::optionalReference($body, 'corrects', $errors);
        $correctionReason = self::optionalText($body, 'correction_reason', StudentMovement::NOTES_MAX_LENGTH, $errors);

        // Un único error por campo (el primero): no se acumulan dos códigos sobre el mismo campo.
        $fail = static function (string $field, string $code) use ($errors): void {
            if (! array_key_exists($field, $errors->toArray())) {
                $errors->add($field, $code, $code);
            }
        };

        if ($direction === StudentMovement::DIRECTION_EXIT) {
            if ($pickedUpBy === null) {
                $fail('picked_up_by', 'prl.validation.required');
            }

            if ($method === null) {
                $fail('verification_method', 'prl.validation.required');
            }
        } elseif ($direction === StudentMovement::DIRECTION_ENTRY) {
            if ($pickedUpBy !== null) {
                $fail('picked_up_by', 'prl.validation.entry_without_pickup');
            }

            if ($method !== null) {
                $fail('verification_method', 'prl.validation.entry_without_pickup');
            }
        }

        if ($offSchedule) {
            if ($reason === null) {
                $fail('off_schedule_reason', 'prl.movement.reason_required');
            } elseif ($reason === 'otro' && $notes === null) {
                $fail('notes', 'prl.movement.reason_required');
            }
        } elseif ($reason !== null) {
            $fail('off_schedule_reason', 'prl.validation.off_schedule_reason_unexpected');
        }

        if ($corrects !== null && $correctionReason === null) {
            $fail('correction_reason', 'prl.validation.required');
        }

        if ($corrects === null && $correctionReason !== null) {
            $fail('corrects', 'prl.validation.required');
        }

        $errors->throwIfAny();

        return [
            'student_person' => (string) $studentPerson,
            'direction' => (string) $direction,
            'picked_up_by' => $pickedUpBy,
            'verification_method' => $method,
            'delivered_by' => $deliveredBy,
            'off_schedule' => $offSchedule,
            'off_schedule_reason' => $offSchedule ? $reason : null,
            'notes' => $notes,
            'corrects' => $corrects,
            'correction_reason' => $correctionReason,
        ];
    }
}
