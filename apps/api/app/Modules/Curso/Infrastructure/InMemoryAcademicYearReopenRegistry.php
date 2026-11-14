<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearReopenCheck;
use App\Modules\Curso\Domain\AcademicYearReopenRegistry;

/**
 * RN-CURSO-45: el registro de validaciones de reapertura, **vacío** al
 * entregarse (`OPEN-059-04` a). Singleton del contenedor: cada módulo registra
 * la suya desde su `ServiceProvider`.
 */
final class InMemoryAcademicYearReopenRegistry implements AcademicYearReopenRegistry
{
    /** @var list<AcademicYearReopenCheck> */
    private array $checks = [];

    public function register(AcademicYearReopenCheck $check): void
    {
        $this->checks[] = $check;
    }

    public function all(): array
    {
        return $this->checks;
    }
}
