<?php

namespace App\Modules\Acad\Http\Resources;

use App\Modules\Acad\Application\StaffDirectory;
use App\Modules\Acad\Application\TimetableFrameService;
use App\Modules\Acad\Domain\Models\FacilityBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ACAD/api.md §12.1: reserva con el resumen del espacio y quien la hizo
 * (`booked_by` = `created_by`, nombre visible de `Core`; `null` si el usuario ya
 * no existe o la creó una tarea de consola). Las horas viajan como `HH:MM`.
 *
 * @mixin FacilityBooking
 */
class FacilityBookingResource extends JsonResource
{
    use ResolvesStructure;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $facility = $this->facility;

        return [
            'public_id' => $this->public_id,
            'academic_year' => $this->academicYearPublicId($this->academic_year_id),
            'facility' => [
                'public_id' => $facility->public_id,
                'facility_type' => $facility->facility_type,
                'code' => $facility->code,
                'name' => $facility->name,
                'deleted' => $facility->trashed(),
            ],
            'booked_on' => $this->booked_on->toDateString(),
            'starts_at' => TimetableFrameService::hhmm($this->starts_at),
            'ends_at' => TimetableFrameService::hhmm($this->ends_at),
            'booked_by' => $this->created_by === null ? null : app(StaffDirectory::class)->summary((int) $this->created_by),
            'created_at' => $this->created_at,
        ];
    }
}
