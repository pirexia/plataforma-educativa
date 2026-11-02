<?php

namespace App\Modules\FamUnit\Domain;

/**
 * `ADR-068 §5.5`, `OPEN-068-06`: un alumno devuelto por `AuthorizedPickupList::studentsMatching()`. El alumno se
 * identifica por su persona (`OPEN-FAM-UNIT-01`); `familyUnitCode` es el código de su unidad familiar, o `null` si solo
 * tiene vínculos.
 */
final class StudentMatch
{
    public function __construct(
        public readonly int $studentPersonId,
        public readonly string $studentPublicId,
        public readonly string $displayName,
        public readonly ?string $familyUnitCode,
    ) {}
}
