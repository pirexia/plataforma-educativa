<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-65` (1.12b): resultado de simular un
 * elemento. `toCopy` cuenta solo las filas principales del elemento (los
 * valores de una escala y las franjas de un marco se copian con su padre y no
 * se cuentan aparte); `skipped` son las filas del origen que no se copian y
 * `adjusted` las que se copian con algún cambio, cada una con el motivo que
 * declare el módulo; `dateRange` es el menor inicio y el mayor fin resultantes
 * (solo elementos con fechas); `issues`, lo que impide ejecutar.
 */
final readonly class AcademicYearRolloverElementReport
{
    /**
     * @param  list<array{reason: string, count: int}>  $skipped
     * @param  list<array{reason: string, count: int}>  $adjusted
     * @param  array{starts_on: string, ends_on: string}|null  $dateRange
     * @param  list<AcademicYearRolloverIssue>  $issues
     */
    public function __construct(
        public string $element,
        public int $toCopy,
        public array $skipped = [],
        public array $adjusted = [],
        public ?array $dateRange = null,
        public array $issues = [],
    ) {}
}
