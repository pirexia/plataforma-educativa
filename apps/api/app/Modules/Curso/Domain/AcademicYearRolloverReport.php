<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-61`, `-65` (1.12b): informe de la
 * simulación de un participante, un `AcademicYearRolloverElementReport` por
 * elemento seleccionado de su módulo.
 */
final readonly class AcademicYearRolloverReport
{
    /**
     * @param  list<AcademicYearRolloverElementReport>  $elements
     */
    public function __construct(public array $elements) {}

    /** @return list<AcademicYearRolloverIssue> */
    public function issues(): array
    {
        $issues = [];

        foreach ($this->elements as $element) {
            array_push($issues, ...$element->issues);
        }

        return $issues;
    }
}
