<?php

namespace App\Modules\Core\Domain;

/**
 * ADR-068 §5.2 (D2): los datos con los que un módulo pide dar de alta una persona **sin cuenta** (un tercero autorizado a
 * recoger a un menor). Mínimo por minimización (`OPEN-FAM-UNIT-27`): nombre, apellidos, documento y teléfono; ni fecha de
 * nacimiento, ni correo, ni dirección. El idioma de la persona es el del centro por defecto.
 *
 * `documentType` y `documentNumber` van juntos o ninguno (`RN-CORE-91`); `PersonRegistrar` valida el catálogo, el
 * formato y el dígito de control (`RN-CORE-90` a `-93`) y guarda el valor canónico.
 */
final class PersonRegistration
{
    public function __construct(
        public readonly string $givenName,
        public readonly string $familyName1,
        public readonly ?string $familyName2 = null,
        public readonly ?string $documentType = null,
        public readonly ?string $documentNumber = null,
        public readonly ?string $contactPhone = null,
    ) {}
}
