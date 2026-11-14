<?php

namespace App\Modules\Curso\Application\Rollover;

/**
 * REQ-CURSO/api.md §2.1 (1.12b): lo que pide el cliente en la simulación y en la
 * solicitud de una copia. Los identificadores son `public_id`; `elements` son los códigos
 * pedidos (sin los de paquete, `RN-CURSO-64`); `copyTeachingAssignments`, la casilla
 * (`RN-CURSO-82`, por defecto `false`). Nada se valida aquí: lo hace `RolloverResolver`.
 */
final readonly class RolloverRequest
{
    /**
     * @param  list<string>  $elements
     */
    public function __construct(
        public ?string $target,
        public ?string $source,
        public array $elements,
        public bool $copyTeachingAssignments = false,
    ) {}
}
