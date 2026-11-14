<?php

namespace App\Modules\Curso\Domain;

/**
 * RN-CURSO-49 (1.11d-a): registro de validaciones del cambio de fechas.
 * Cada módulo registra la suya desde el `boot()` de su `ServiceProvider`.
 * Precedente: `AcademicYearClosureRegistry` (RN-CURSO-30).
 */
interface AcademicYearDateChangeRegistry
{
    public function register(AcademicYearDateChangeCheck $check): void;

    /** @return list<AcademicYearDateChangeCheck> */
    public function all(): array;
}
