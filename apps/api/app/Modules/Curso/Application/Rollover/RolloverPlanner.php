<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Modules\Curso\Domain\AcademicYearRolloverElementReport;
use App\Modules\Curso\Domain\AcademicYearRolloverIssue;
use App\Modules\Curso\Domain\AcademicYearRolloverParticipant;
use App\Modules\Curso\Domain\AcademicYearRolloverPlan;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\YearRolloverStatus;
use Illuminate\Support\Facades\Lang;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-65`, `-66` (1.12b): invoca a `preview()` de cada
 * participante con elementos en la selección efectiva, en el orden del catálogo, y compone el
 * informe. Solo lectura, sin bloqueos de fila. Lo usan la simulación, la solicitud (paso 4 de
 * `RN-CURSO-68`) y la ejecución (que lo repite, `RN-CURSO-69`).
 *
 * Incidencias propias de `curso` (`RN-CURSO-66`): `curso.rollover.empty` (la selección no crea
 * ninguna fila) y, solo si se pide (`$includeExisting`, la simulación), `curso.rollover.exists`
 * (una copia viva sobre el mismo destino).
 */
final class RolloverPlanner
{
    public function __construct(private readonly RolloverCatalog $catalog) {}

    public function preview(ResolvedRollover $resolved, bool $includeExisting = false): RolloverPreview
    {
        $offset = $resolved->dateOffsetDays();
        $elements = [];

        foreach ($this->catalog->participants() as $participant) {
            $codes = $this->selectedCodes($participant, $resolved->effective);

            if ($codes === []) {
                continue;
            }

            $plan = new AcademicYearRolloverPlan($resolved->source, $resolved->target, $codes, $offset);
            $reports = [];

            foreach ($participant->preview($plan)->elements as $report) {
                $reports[$report->element] = $report;
            }

            foreach ($codes as $code) {
                $elements[] = $this->elementRow($participant, $code, $reports[$code] ?? new AcademicYearRolloverElementReport($code, 0));
            }
        }

        $blocking = [];

        if ($includeExisting) {
            $live = $this->liveRollover($resolved->target);

            if ($live !== null) {
                $blocking[] = new AcademicYearRolloverIssue(
                    'curso.rollover.exists',
                    __('curso.rollover.exists'),
                    ['public_id' => $live->public_id, 'status' => $live->status->value],
                );
            }
        }

        $preview = new RolloverPreview($resolved->target, $resolved->source, $offset, $resolved->copyTeachingAssignments, $elements, $blocking);

        if ($preview->totalToCopy() === 0) {
            $blocking[] = new AcademicYearRolloverIssue('curso.rollover.empty', __('curso.rollover.empty'));
            $preview = new RolloverPreview($resolved->target, $resolved->source, $offset, $resolved->copyTeachingAssignments, $elements, $blocking);
        }

        return $preview;
    }

    /** La copia viva (`pendiente`, `en_curso`, `completado` o `revirtiendo`) hacia el destino, si hay (`RN-CURSO-67`). */
    public function liveRollover(AcademicYearSummary $target): ?YearRollover
    {
        return YearRollover::query()
            ->where('academic_year_id', $target->id)
            ->whereIn('status', array_map(static fn (YearRolloverStatus $s): string => $s->value, YearRolloverStatus::live()))
            ->first();
    }

    /**
     * Códigos de la selección efectiva que ofrece este participante, en el orden en que los declara.
     *
     * @param  list<string>  $effective
     * @return list<string>
     */
    public function selectedCodes(AcademicYearRolloverParticipant $participant, array $effective): array
    {
        $codes = [];

        foreach ($participant->elements() as $element) {
            if (in_array($element->code, $effective, true)) {
                $codes[] = $element->code;
            }
        }

        return $codes;
    }

    /**
     * @return array{code: string, label: string, to_copy: int, skipped: list<array{reason: string, label: string, count: int}>, adjusted: list<array{reason: string, label: string, count: int}>, date_range: array{starts_on: string, ends_on: string}|null, blocking: list<AcademicYearRolloverIssue>}
     */
    private function elementRow(AcademicYearRolloverParticipant $participant, string $code, AcademicYearRolloverElementReport $report): array
    {
        $labelKey = null;

        foreach ($participant->elements() as $element) {
            if ($element->code === $code) {
                $labelKey = $element->labelKey;
            }
        }

        $module = $participant->moduleCode();

        return [
            'code' => $code,
            'label' => $labelKey !== null ? (string) __($labelKey) : $code,
            'to_copy' => $report->toCopy,
            'skipped' => self::labelled($module, 'skipped', $report->skipped),
            'adjusted' => self::labelled($module, 'adjusted', $report->adjusted),
            'date_range' => $report->dateRange,
            'blocking' => $report->issues,
        ];
    }

    /**
     * Añade la etiqueta traducida de cada motivo: `<módulo>.rollover.<skipped|adjusted>.<motivo>`;
     * sin clave, el propio código (`RN-CURSO-73`).
     *
     * @param  list<array{reason: string, count: int}>  $rows
     * @return list<array{reason: string, label: string, count: int}>
     */
    public static function labelled(string $module, string $kind, array $rows): array
    {
        return array_map(static function (array $row) use ($module, $kind): array {
            $key = "{$module}.rollover.{$kind}.{$row['reason']}";

            return [
                'reason' => $row['reason'],
                'label' => Lang::has($key) ? (string) __($key) : $row['reason'],
                'count' => $row['count'],
            ];
        }, $rows);
    }
}
