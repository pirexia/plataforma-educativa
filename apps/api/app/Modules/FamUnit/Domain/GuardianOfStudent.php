<?php

namespace App\Modules\FamUnit\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §7.1. Un tutor **activo** de un alumno, tal como lo devuelve
 * `FamilyMembership::activeGuardiansOf()`: id de persona del tutor y los atributos de su vínculo. Códigos técnicos
 * sin traducir (`ADR-038 §3.2`); sin nombre ni datos de contacto (son de `Core`, `OPEN-FAM-UNIT-20`).
 */
final class GuardianOfStudent
{
    public function __construct(
        public readonly int $guardianPersonId,
        public readonly string $relationship,
        public readonly bool $isLegalGuardian,
        public readonly string $accessLevel,
        public readonly bool $isEmergencyContact,
    ) {}
}
