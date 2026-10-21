<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-61`, `-73` (1.12b): lo que devuelve
 * `copy()` de un participante: el resultado por elemento y la lista de filas
 * creadas (`AcademicYearRolloverItem`), que `curso` registra para poder
 * revertir.
 */
final readonly class AcademicYearRolloverResult
{
    /**
     * @param  list<AcademicYearRolloverElementResult>  $elements
     * @param  list<AcademicYearRolloverItem>  $items
     */
    public function __construct(
        public array $elements,
        public array $items,
    ) {}
}
