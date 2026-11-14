<?php

namespace App\Modules\Curso\Domain;

/**
 * RN-CURSO-45 (1.11c, `OPEN-059-04` a): registro de validaciones de reapertura.
 * **Vacío** al entregarse; cada módulo registra la suya al llegar (`1.17b`, fase
 * 2). Simétrico a `AcademicYearClosureRegistry`.
 */
interface AcademicYearReopenRegistry
{
    public function register(AcademicYearReopenCheck $check): void;

    /** @return list<AcademicYearReopenCheck> */
    public function all(): array;
}
