<?php

namespace App\Modules\Acad\Http\Requests;

/**
 * REQ-ACAD/api.md §12.4, `GET /facility-bookings`: filtros `facility`
 * (múltiple), `booked_from` y `booked_to` (fechas `AAAA-MM-DD`, inclusivas);
 * orden fijo `booked_on`, `starts_at`, `id`. Un `public_id` de espacio
 * inexistente, de otro curso o de otro centro da una colección vacía.
 */
class IndexFacilityBookingsRequest extends IndexStructureRequest
{
    public const SORTS = [];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['sort']);

        return $rules;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'facility' => ['sometimes', 'string', new Rules\PlainText],
            'booked_from' => ['sometimes', 'date_format:Y-m-d'],
            'booked_to' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
