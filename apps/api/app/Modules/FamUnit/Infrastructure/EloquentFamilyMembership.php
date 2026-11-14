<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Modules\FamUnit\Application\FamilyUnitStudentService;
use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Modules\FamUnit\Application\GuardianService;
use App\Modules\FamUnit\Domain\FamilyMembership;
use App\Modules\FamUnit\Domain\GuardianOfStudent;
use App\Modules\FamUnit\Domain\StudentUnit;
use App\Support\Modules\ModuleAvailability;

/**
 * REQ-FAM-UNIT/funcional.md §7.1 (1.14-a). Una consulta por llamada y **ninguna memoización** (`RN-FAM-UNIT-34`): la
 * revocación surte efecto en la petición siguiente. Con el módulo descontratado, los conjuntos salen vacíos y la
 * unidad es `null` (`§9`); `isAccessRevoked()` no depende de la contratación (`§15`, D-3).
 */
final class EloquentFamilyMembership implements FamilyMembership
{
    public function __construct(
        private readonly GuardianService $guardians,
        private readonly GuardianLinkService $links,
        private readonly FamilyUnitStudentService $students,
        private readonly ModuleAvailability $modules,
    ) {}

    public function studentPersonIdsReachedBy(int $personId, ?string $category = null): array
    {
        if (! $this->modules->isEnabled('fam_unit')) {
            return [];
        }

        $guardianId = $this->guardians->guardianIdForPerson($personId);

        return $guardianId === null ? [] : $this->links->reachableStudentIdList($guardianId, $category);
    }

    public function activeGuardiansOf(int $studentPersonId): array
    {
        if (! $this->modules->isEnabled('fam_unit')) {
            return [];
        }

        $guardians = [];

        foreach ($this->links->activeLinksOfStudent($studentPersonId) as $link) {
            if ($link->guardian === null) {
                continue;
            }

            $guardians[] = new GuardianOfStudent(
                (int) $link->guardian->person_id,
                (string) $link->relationship,
                (bool) $link->is_legal_guardian,
                (string) $link->access_level,
                (bool) $link->is_emergency_contact,
            );
        }

        usort($guardians, static fn (GuardianOfStudent $a, GuardianOfStudent $b): int => $a->guardianPersonId <=> $b->guardianPersonId);

        return $guardians;
    }

    public function isAccessRevoked(int $guardianPersonId, int $studentPersonId): bool
    {
        $guardianId = $this->guardians->guardianIdForPerson($guardianPersonId);

        return $guardianId !== null && $this->links->hasRevokedLink($guardianId, $studentPersonId);
    }

    public function unitOfStudent(int $studentPersonId): ?StudentUnit
    {
        if (! $this->modules->isEnabled('fam_unit')) {
            return null;
        }

        $unit = $this->students->unitOfStudent($studentPersonId);

        return $unit === null ? null : new StudentUnit($unit['public_id'], $unit['principal_person_id']);
    }
}
