<?php

namespace App\Modules\Acad\Http\Requests;

use App\Modules\Acad\Application\PeriodFields;
use App\Modules\Acad\Application\TimeSlotsValidator;
use App\Support\Api\ValidationErrorBag;

/**
 * REQ-ACAD/api.md §12.3, `POST /facility-bookings`: `academic_year`, `facility`,
 * `booked_on` (`AAAA-MM-DD`), `starts_at` y `ends_at` (`HH:MM`). Es el único alta
 * de `1.12` que lleva `academic_year` en el cuerpo: una reserva no tiene padre
 * con curso (`RN-ACAD-91`, `RN-ACAD-140`). Una referencia ausente o ilegible es
 * «no encontrada» en su campo; `ends_at` debe ser posterior a `starts_at`
 * (`slot_ends_before_start`, como las franjas, `RN-ACAD-112`). Que la fecha
 * caiga dentro del curso y los conflictos los decide el servicio.
 */
class StoreFacilityBookingRequest extends StructureRequest
{
    use ReadsReferences;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array{academic_year: string, facility: string, booked_on: string, starts_at: string, ends_at: string}
     */
    public function payload(): array
    {
        $body = $this->body();
        $errors = new ValidationErrorBag;

        $year = $this->reference($body['academic_year'] ?? null);
        $facility = $this->reference($body['facility'] ?? null);

        if ($year === null) {
            $errors->add('academic_year', 'acad.validation.academic_year_required', 'acad.validation.academic_year_required');
        }

        if ($facility === null) {
            $errors->add('facility', 'acad.validation.facility_not_found', 'acad.validation.facility_not_found');
        }

        $bookedOn = PeriodFields::date($body['booked_on'] ?? null, 'booked_on', $errors);
        $startsAt = $this->time($body['starts_at'] ?? null, 'starts_at', $errors);
        $endsAt = $this->time($body['ends_at'] ?? null, 'ends_at', $errors);

        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            $errors->add('ends_at', 'acad.validation.slot_ends_before_start', 'acad.validation.slot_ends_before_start');
        }

        $errors->throwIfAny();

        return [
            'academic_year' => (string) $year,
            'facility' => (string) $facility,
            'booked_on' => (string) $bookedOn,
            'starts_at' => (string) $startsAt,
            'ends_at' => (string) $endsAt,
        ];
    }

    private function time(mixed $value, string $field, ValidationErrorBag $errors): ?string
    {
        if (! TimeSlotsValidator::isCivilTime($value)) {
            $errors->add($field, 'acad.validation.time_invalid', 'acad.validation.time_invalid');

            return null;
        }

        return (string) $value;
    }
}
