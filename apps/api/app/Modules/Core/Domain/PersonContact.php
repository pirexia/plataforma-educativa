<?php

namespace App\Modules\Core\Domain;

/**
 * ADR-068 §5.2 (`L5`, `OPEN-068-05`): el teléfono y el documento de una persona, tal como los devuelve
 * `PersonDirectory::contactDetailsByIds()`. El número de documento va **completo**: enmascararlo es de cada módulo
 * consumidor (`RN-FAM-UNIT-90`) y ningún consumidor de `AuthorizedPickupList` lo recibe completo.
 */
final class PersonContact
{
    public function __construct(
        public readonly ?string $contactPhone,
        public readonly ?string $documentType,
        public readonly ?string $documentNumber,
    ) {}
}
