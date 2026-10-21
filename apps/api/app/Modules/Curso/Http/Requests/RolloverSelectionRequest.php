<?php

namespace App\Modules\Curso\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use App\Modules\Curso\Application\Rollover\RolloverRequest;

/**
 * REQ-CURSO/api.md §2.1 (1.12b): base de las peticiones de simulación y de solicitud de una copia.
 * Solo valida la **forma** (tipos); que falte el destino, el origen o los elementos lo responde el
 * servicio con sus códigos `curso.validation.rollover_*` (`RN-CURSO-63`, `-64`), por eso ninguno es
 * `required` aquí.
 */
abstract class RolloverSelectionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return list<string> */
    abstract protected function requestedElements(): array;

    public function rolloverRequest(): RolloverRequest
    {
        $target = $this->validated('academic_year');
        $source = $this->validated('source');

        return new RolloverRequest(
            is_string($target) ? $target : null,
            is_string($source) ? $source : null,
            $this->requestedElements(),
            $this->copyTeachingAssignments(),
        );
    }

    abstract protected function copyTeachingAssignments(): bool;
}
