<?php

namespace App\Modules\Acad\Domain;

/**
 * RN-ACAD-21: un veto de borrado que se cumple. `dependent` es la clave técnica
 * del dependiente (p. ej. `enrollments`); su nombre visible es la clave
 * `acad.dependents.{dependent}`. `count` es el recuento de filas vivas que
 * bloquean el borrado.
 */
final readonly class VetoFailure
{
    public function __construct(
        public string $dependent,
        public int $count,
    ) {}
}
