<?php

namespace App\Modules\Acad\Http\Resources;

use App\Modules\Acad\Application\TimetableFrameService;
use App\Modules\Acad\Domain\Models\TimeSlot;
use App\Modules\Acad\Domain\Models\TimetableFrame;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-ACAD/api.md §12.1. Las horas viajan como `HH:MM` (hora civil del centro,
 * sin zona, `ADR-065`); `valid_from`/`valid_to` son `null` en un `ordinaria`.
 * `slots` siempre presente, ordenado por `weekday`, `starts_at`.
 *
 * @mixin TimetableFrame
 */
class TimetableFrameResource extends JsonResource
{
    use ResolvesStructure;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'academic_year' => $this->academicYearPublicId($this->academic_year_id),
            'education_level' => $this->educationLevel->public_id,
            'frame_type' => $this->frame_type,
            'name' => $this->resolvedName($this->name_translations),
            'name_translations' => $this->name_translations,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'slots' => $this->slots->map(static fn (TimeSlot $slot): array => [
                'public_id' => $slot->public_id,
                'weekday' => $slot->weekday,
                'starts_at' => TimetableFrameService::hhmm($slot->starts_at),
                'ends_at' => TimetableFrameService::hhmm($slot->ends_at),
            ])->all(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
