<?php

namespace App\Modules\Curso\Http\Resources;

use App\Modules\Curso\Application\Rollover\RolloverCancellation;
use App\Modules\Curso\Application\Rollover\RolloverPlanner;
use App\Modules\Curso\Domain\AcademicYearRolloverRegistry;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\YearRolloverStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;

/**
 * REQ-CURSO/api.md §2.1 (1.12b): representación de una copia (`AcademicYearRollover`). `status` viaja sin
 * traducir (`ADR-038 §3.2`). `academic_year` y `source_academic_year` son los `public_id` de destino y origen.
 * **Los mensajes se traducen al leer** (`RN-CURSO-73`, revisión de documentación M-3): lo persistido son
 * `code` + `params`; el idioma es el de la petición. `revertible` y `cancellable_at` son orientativos: el
 * servidor decide al ejecutar (`INV-002`). No se exponen `id`, `tenant_id`, `created_by`, `reverted_by`,
 * `deleted_at` ni los *items*.
 *
 * @mixin YearRollover
 */
class AcademicYearRolloverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['academicYear', 'sourceAcademicYear']);

        return [
            'public_id' => $this->public_id,
            'academic_year' => $this->academicYear->public_id,
            'source_academic_year' => $this->sourceAcademicYear->public_id,
            'status' => $this->status->value,
            'elements' => $this->elements,
            'copy_teaching_assignments' => $this->copy_teaching_assignments,
            'date_offset_days' => $this->date_offset_days,
            'result' => $this->result === null ? null : ['elements' => array_map(self::resultElement(...), $this->result['elements'] ?? [])],
            'failure' => self::failure($this->failure),
            'revert_failure' => self::failure($this->revert_failure),
            'revertible' => $this->status === YearRolloverStatus::Completado && $this->academicYear->status === AcademicYearStatus::Planificacion,
            'cancellable_at' => $this->status->isInFlight() ? RolloverCancellation::availableAt($this->resource)->toIso8601String() : null,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'reverted_at' => $this->reverted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    private static function resultElement(array $element): array
    {
        $module = explode('.', (string) ($element['code'] ?? ''), 2)[0];

        return [
            'code' => $element['code'] ?? null,
            'label' => self::elementLabel((string) ($element['code'] ?? '')),
            'copied' => $element['copied'] ?? 0,
            'skipped' => RolloverPlanner::labelled($module, 'skipped', $element['skipped'] ?? []),
            'adjusted' => RolloverPlanner::labelled($module, 'adjusted', $element['adjusted'] ?? []),
        ];
    }

    /**
     * Etiqueta traducida del elemento, con el idioma de la petición y la misma clave que la simulación
     * (`RolloverPlanner::elementRow`). Se busca en el registro entero y no en el catálogo de módulos utilizables:
     * una copia ya hecha de un módulo después descontratado conserva su etiqueta. Sin clave, el propio código.
     */
    private static function elementLabel(string $code): string
    {
        foreach (app(AcademicYearRolloverRegistry::class)->all() as $participant) {
            foreach ($participant->elements() as $element) {
                if ($element->code === $code) {
                    return (string) __($element->labelKey);
                }
            }
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>|null  $failure
     * @return array<string, mixed>|null
     */
    private static function failure(?array $failure): ?array
    {
        if ($failure === null) {
            return null;
        }

        return [
            'code' => $failure['code'] ?? null,
            'errors' => array_map(static function (array $error): array {
                $code = (string) ($error['code'] ?? '');
                $params = is_array($error['params'] ?? null) ? $error['params'] : [];

                return [
                    'code' => $code,
                    // Sin clave de traducción, el propio código (RN-CURSO-73).
                    'message' => Lang::has($code) ? (string) __($code, $params) : $code,
                    'params' => $params,
                    'element' => $error['element'] ?? null,
                ];
            }, $failure['errors'] ?? []),
        ];
    }
}
