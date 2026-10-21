<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-73` (1.12b): resultado de copiar un
 * elemento. Solo códigos y cuentas, sin datos personales (`ADR-035`).
 */
final readonly class AcademicYearRolloverElementResult
{
    /**
     * @param  list<array{reason: string, count: int}>  $skipped
     * @param  list<array{reason: string, count: int}>  $adjusted
     */
    public function __construct(
        public string $element,
        public int $copied,
        public array $skipped = [],
        public array $adjusted = [],
    ) {}
}
