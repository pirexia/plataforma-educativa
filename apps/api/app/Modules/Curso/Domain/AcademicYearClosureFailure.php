<?php

namespace App\Modules\Curso\Domain;

/**
 * RN-CURSO-30 (1.10): un fallo de validación de cierre. Una entrada por
 * validación fallida en `errors.closure[]` de la respuesta, con el
 * `code`/`message`/`params` que aporte el módulo dueño de la validación
 * (`message` ya traducido, ADR-038 §6.3).
 */
final readonly class AcademicYearClosureFailure
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
