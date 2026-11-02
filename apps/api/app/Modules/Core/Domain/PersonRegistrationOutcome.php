<?php

namespace App\Modules\Core\Domain;

/**
 * ADR-068 §5.2 (D2): resultado de `PersonRegistrar::registerOrReuse()`. `reused` indica que ya existía una persona viva
 * del centro con ese documento; `dataMismatch`, que algún dato aportado difiere del registrado. **No dice qué campo
 * difiere ni cuál es el valor registrado**: el personal lo ve en la ficha de la persona, con su permiso. Nunca se
 * devuelven datos personales de una persona reutilizada.
 */
final class PersonRegistrationOutcome
{
    public function __construct(
        public readonly int $personId,
        public readonly string $publicId,
        public readonly bool $reused,
        public readonly bool $dataMismatch,
    ) {}
}
