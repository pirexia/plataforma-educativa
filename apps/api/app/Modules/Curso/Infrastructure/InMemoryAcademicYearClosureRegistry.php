<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearClosureCheck;
use App\Modules\Curso\Domain\AcademicYearClosureRegistry;

/**
 * RN-CURSO-30: el registro de validaciones de cierre, **vacío en 1.10**
 * (`OPEN-CURSO-07`). Singleton del contenedor: cada módulo registra la suya
 * desde su `ServiceProvider`.
 */
final class InMemoryAcademicYearClosureRegistry implements AcademicYearClosureRegistry
{
    /** @var list<AcademicYearClosureCheck> */
    private array $checks = [];

    public function register(AcademicYearClosureCheck $check): void
    {
        $this->checks[] = $check;
    }

    public function all(): array
    {
        return $this->checks;
    }
}
