<?php

namespace App\Modules\Curso\Domain;

/**
 * RN-CURSO-30 (1.10): registro de validaciones de cierre. **Vacío en 1.10**
 * (`OPEN-CURSO-07`); cada módulo registra la suya al llegar. Precedente:
 * `ScopeResolverRegistry` (ADR-044).
 */
interface AcademicYearClosureRegistry
{
    public function register(AcademicYearClosureCheck $check): void;

    /** @return list<AcademicYearClosureCheck> */
    public function all(): array;
}
