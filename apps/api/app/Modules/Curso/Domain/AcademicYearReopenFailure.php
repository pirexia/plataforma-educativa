<?php

namespace App\Modules\Curso\Domain;

/**
 * RN-CURSO-45 (1.11c): un fallo de validación de reapertura. Una entrada por
 * validación fallida en `errors.reopen[]` de la respuesta, con el
 * `code`/`message`/`params` que aporte el módulo dueño de la validación
 * (`message` ya traducido, ADR-038 §6.3). Simétrico a `AcademicYearClosureFailure`.
 */
final readonly class AcademicYearReopenFailure
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
