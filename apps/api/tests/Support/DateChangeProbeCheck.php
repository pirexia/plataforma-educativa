<?php

namespace Tests\Support;

use App\Modules\Curso\Domain\AcademicYearDateChange;
use App\Modules\Curso\Domain\AcademicYearDateChangeCheck;
use App\Modules\Curso\Domain\AcademicYearDateChangeFailure;

/**
 * Validación de prueba del cambio de fechas: falla (o no) con el código dado y
 * registra cada cambio que recibe.
 */
final class DateChangeProbeCheck implements AcademicYearDateChangeCheck
{
    /** @var list<AcademicYearDateChange> */
    public array $received = [];

    public function __construct(
        private readonly string $module = 'curso',
        private readonly ?string $failCode = null,
    ) {}

    public function moduleCode(): string
    {
        return $this->module;
    }

    public function check(AcademicYearDateChange $change): ?AcademicYearDateChangeFailure
    {
        $this->received[] = $change;

        return $this->failCode === null ? null : new AcademicYearDateChangeFailure($this->failCode, 'Rechazado por la prueba.', ['n' => 1]);
    }
}
