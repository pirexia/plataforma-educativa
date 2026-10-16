<?php

namespace App\Modules\Curso\Domain;

/**
 * RN-CURSO-49, RN-CURSO-53 (1.11d-a): un fallo de validación de cambio de
 * fechas. Una entrada por validación fallida en `errors.date_change[]`, con
 * el `code`/`message`/`params` que aporte el módulo dueño (`message` ya
 * traducido, ADR-038 §6.3). Sin datos personales.
 */
final readonly class AcademicYearDateChangeFailure
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $params = [],
    ) {}
}
