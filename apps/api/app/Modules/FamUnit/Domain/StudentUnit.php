<?php

namespace App\Modules\FamUnit\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §7.1. La unidad familiar de un alumno y el id de persona de su tutor principal
 * (`null` si la unidad no tiene principal), para `1.14b` («el alta de un tercero requiere autorización del tutor
 * principal»).
 */
final class StudentUnit
{
    public function __construct(
        public readonly string $familyUnitPublicId,
        public readonly ?int $principalGuardianPersonId,
    ) {}
}
