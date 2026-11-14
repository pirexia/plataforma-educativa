<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearDateChangeCheck;
use App\Modules\Curso\Domain\AcademicYearDateChangeRegistry;

/**
 * RN-CURSO-49: registro de validaciones del cambio de fechas. Singleton del
 * contenedor; cada módulo registra la suya desde su `ServiceProvider`.
 */
final class InMemoryAcademicYearDateChangeRegistry implements AcademicYearDateChangeRegistry
{
    /** @var list<AcademicYearDateChangeCheck> */
    private array $checks = [];

    public function register(AcademicYearDateChangeCheck $check): void
    {
        $this->checks[] = $check;
    }

    public function all(): array
    {
        return $this->checks;
    }
}
