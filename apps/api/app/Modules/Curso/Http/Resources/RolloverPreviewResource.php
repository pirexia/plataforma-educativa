<?php

namespace App\Modules\Curso\Http\Resources;

use App\Modules\Curso\Application\CursoErrors;
use App\Modules\Curso\Application\Rollover\RolloverPreview;
use App\Modules\Curso\Domain\AcademicYearSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * REQ-CURSO/api.md §2.1 (1.12b): `AcademicYearRolloverPreview`, la simulación. Los resúmenes de curso son la
 * forma pública de `AcademicYear` reducida a esos campos; `blocking` son entradas `{code, message, params,
 * element}`, mensajes ya traducidos y sin datos personales.
 *
 * @mixin RolloverPreview
 */
class RolloverPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RolloverPreview $preview */
        $preview = $this->resource;

        return [
            'academic_year' => self::summary($preview->target),
            'source' => self::summary($preview->source),
            'date_offset_days' => $preview->dateOffsetDays,
            'copy_teaching_assignments' => $preview->copyTeachingAssignments,
            'elements' => array_map(static fn (array $element): array => [
                'code' => $element['code'],
                'label' => $element['label'],
                'to_copy' => $element['to_copy'],
                'skipped' => $element['skipped'],
                'adjusted' => $element['adjusted'],
                'date_range' => $element['date_range'],
                'blocking' => array_map(CursoErrors::issueEntry(...), $element['blocking']),
            ], $preview->elements),
            'blocking' => array_map(CursoErrors::issueEntry(...), $preview->blocking),
            'executable' => $preview->executable(),
        ];
    }

    /**
     * @return array{public_id: string, code: string, status: string, starts_on: string, ends_on: string}
     */
    private static function summary(AcademicYearSummary $year): array
    {
        return [
            'public_id' => $year->publicId,
            'code' => $year->code,
            'status' => $year->status->value,
            'starts_on' => $year->startsOn->toDateString(),
            'ends_on' => $year->endsOn->toDateString(),
        ];
    }
}
