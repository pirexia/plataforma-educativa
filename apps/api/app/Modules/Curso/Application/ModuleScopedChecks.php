<?php

namespace App\Modules\Curso\Application;

use App\Modules\Curso\Domain\AcademicYearClosureCheck;
use App\Modules\Curso\Domain\AcademicYearDateChangeCheck;
use App\Modules\Curso\Domain\AcademicYearReopenCheck;
use App\Support\Modules\ModuleAvailability;

/**
 * RN-CURSO-52, RN-CURSO-60 (1.11d-a): único sitio de `curso` que decide qué
 * validaciones registradas se ejecutan. Solo las de módulos utilizables en el
 * centro según `ModuleAvailability::isEnabled()` (la misma definición que el
 * middleware `module-enabled:` y el resolutor de permisos). Lo usan el
 * `PATCH` de fechas, el cierre y la reapertura (1.11c, RN-CURSO-45).
 */
final class ModuleScopedChecks
{
    public function __construct(private readonly ModuleAvailability $modules) {}

    /**
     * @template T of AcademicYearClosureCheck|AcademicYearDateChangeCheck|AcademicYearReopenCheck
     *
     * @param  list<T>  $checks
     * @return list<T>
     */
    public function enabled(array $checks): array
    {
        return array_values(array_filter(
            $checks,
            fn (AcademicYearClosureCheck|AcademicYearDateChangeCheck|AcademicYearReopenCheck $check): bool => $this->modules->isEnabled($check->moduleCode()),
        ));
    }
}
