<?php

namespace App\Modules\Curso\Application\Rollover;

use App\Modules\Curso\Domain\AcademicYearRolloverIssue;
use App\Modules\Curso\Domain\AcademicYearSummary;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-65` (1.12b): informe de la simulación. Por elemento, lo que
 * se creará, las omisiones y los ajustes con su motivo (y la etiqueta ya traducida), el rango
 * de fechas resultante y las incidencias; más las incidencias propias de `curso`
 * (`RN-CURSO-66`). `executable` es `true` si no hay ninguna incidencia.
 */
final readonly class RolloverPreview
{
    /**
     * @param  list<array{code: string, label: string, to_copy: int, skipped: list<array{reason: string, label: string, count: int}>, adjusted: list<array{reason: string, label: string, count: int}>, date_range: array{starts_on: string, ends_on: string}|null, blocking: list<AcademicYearRolloverIssue>}>  $elements
     * @param  list<AcademicYearRolloverIssue>  $blocking
     */
    public function __construct(
        public AcademicYearSummary $target,
        public AcademicYearSummary $source,
        public int $dateOffsetDays,
        public bool $copyTeachingAssignments,
        public array $elements,
        public array $blocking,
    ) {}

    /** @return list<AcademicYearRolloverIssue> todas, las de los elementos primero */
    public function issues(): array
    {
        $issues = [];

        foreach ($this->elements as $element) {
            array_push($issues, ...$element['blocking']);
        }

        array_push($issues, ...$this->blocking);

        return $issues;
    }

    public function executable(): bool
    {
        return $this->issues() === [];
    }

    public function totalToCopy(): int
    {
        return array_sum(array_column($this->elements, 'to_copy'));
    }
}
