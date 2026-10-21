<?php

namespace App\Modules\Curso\Domain;

/**
 * REQ-CURSO/funcional.md `RN-CURSO-61`, `-66`, `-73` (1.12b): incidencia que
 * impide ejecutar una copia o revertirla (forma de
 * `AcademicYearDateChangeFailure`). `message` ya va traducido y solo se usa en
 * las respuestas inmediatas; lo que se persiste es `code` + `params`
 * (`RN-CURSO-73`). Sin datos personales ni identificadores de filas.
 */
final readonly class AcademicYearRolloverIssue
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $params = [],
        public ?string $element = null,
    ) {}
}
