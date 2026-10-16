<?php

namespace App\Modules\Curso\Application;

use App\Modules\Curso\Domain\AcademicYearClosureCheck;
use App\Modules\Curso\Domain\AcademicYearDateChangeCheck;
use App\Support\Modules\ModuleAvailability;

/**
 * RN-CURSO-52, RN-CURSO-60 (1.11d-a): único sitio de `curso` que decide qué
 * validaciones registradas se ejecutan. Solo las de módulos utilizables en el
 * centro según `ModuleAvailability::isEnabled()` (la misma definición que el
 * middleware `module-enabled:` y el resolutor de permisos). Lo usan el
 * `PATCH` de fechas y el cierre; el registro de reapertura, cuando exista.
 */
final class ModuleScopedChecks
{
    public function __construct(private readonly ModuleAvailability $modules) {}

    /**
     * @template T of AcademicYearClosureCheck|AcademicYearDateChangeCheck
     *
     * @param  list<T>  $checks
     * @return list<T>
     */
    public function enabled(array $checks): array
    {
        return array_values(array_filter(
            $checks,
            fn (AcademicYearClosureCheck|AcademicYearDateChangeCheck $check): bool => $this->modules->isEnabled($check->moduleCode()),
        ));
    }
}
