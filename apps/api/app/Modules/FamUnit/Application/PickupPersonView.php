<?php

namespace App\Modules\FamUnit\Application;

use App\Modules\Core\Domain\PersonContact;
use App\Modules\Core\Domain\PersonRef;
use App\Modules\FamUnit\Domain\Models\PickupPhoto;

/**
 * REQ-FAM-UNIT/api.md §8.1, `PickupPerson` (1.14b-a): una persona de recogida con su teléfono, su documento (completo, solo
 * aquí y con `autorizado_recogida.actualizar`, `RN-FAM-UNIT-90`) y el estado de su fotografía. `reused` y `dataMismatch` solo
 * los rellena `POST /pickup-persons` (`RN-FAM-UNIT-89`); `identified = false` es una persona reutilizada que estos
 * *endpoints* no alcanzan, de la que no se revela nada más que su identificador.
 */
final class PickupPersonView
{
    public function __construct(
        public readonly PersonRef $person,
        public readonly ?PersonContact $contact,
        public readonly ?PickupPhoto $photo,
        public readonly ?bool $reused = null,
        public readonly ?bool $dataMismatch = null,
        public readonly bool $identified = true,
    ) {}
}
