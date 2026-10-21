<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-61`, `-74`, `-75` (1.12b): lo que `curso`
 * pasa a un participante al comprobar o ejecutar la reversión de una copia: el
 * destino y los *items* **de ese módulo** en esa copia.
 */
final readonly class AcademicYearRolloverReversal
{
    /**
     * @param  list<AcademicYearRolloverItem>  $items
     */
    public function __construct(
        public AcademicYearSummary $target,
        public array $items,
    ) {}
}
