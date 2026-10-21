<?php

namespace App\Modules\Curso\Domain;

use RuntimeException;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-70`, `-75` (1.12b): un participante la lanza desde `revert()` cuando, **ya con sus
 * bloqueos tomados**, repite `checkRevert()` y encuentra datos añadidos después de la comprobación previa que dependen de
 * lo copiado. Revierte la transacción entera: la copia vuelve a `completado` con `revert_failure` (`rollover_blocked`).
 */
final class RolloverBlockedException extends RuntimeException
{
    /**
     * @param  list<AcademicYearRolloverIssue>  $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct('La reversión está bloqueada por datos añadidos después (RN-CURSO-75).');
    }
}
