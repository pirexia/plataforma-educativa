<?php

namespace App\Modules\Core\Domain;

/**
 * ADR-068 §5.2 (`L5`, `OPEN-068-05`): el teléfono y el documento de una persona, tal como los devuelve
 * `PersonDirectory::contactDetailsByIds()`. El número de documento va **completo**: enmascararlo es de cada módulo
 * consumidor (`RN-FAM-UNIT-90`) y ningún consumidor de `AuthorizedPickupList` lo recibe completo.
 *
 * Los tres campos de nombre (`givenName`, `familyName1`, `familyName2`) los añade el issue #509 (aditivo, opcionales):
 * solo los emite `GET /pickup-persons/{p}` y las demás respuestas de `PickupPerson` para precargar la edición.
 *
 * `locale` (idioma de la persona) lo añade `1.15-a` (REQ-ALUM, ficha de alumno; aditivo, opcional): lo consume solo la
 * ficha de `alum`.
 */
final class PersonContact
{
    public function __construct(
        public readonly ?string $contactPhone,
        public readonly ?string $documentType,
        public readonly ?string $documentNumber,
        public readonly ?string $givenName = null,
        public readonly ?string $familyName1 = null,
        public readonly ?string $familyName2 = null,
        public readonly ?string $locale = null,
    ) {}
}
