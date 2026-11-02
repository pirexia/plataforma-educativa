<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Application\FamilyUnitStudentService;
use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Modules\FamUnit\Application\PickupListReader;
use App\Modules\FamUnit\Application\PickupListService;
use App\Modules\FamUnit\Domain\AuthorizedPickupList;
use App\Modules\FamUnit\Domain\PickupBasis;
use App\Modules\FamUnit\Domain\PickupGrant;
use App\Modules\FamUnit\Domain\StudentMatch;
use App\Support\Modules\ModuleAvailability;

/**
 * REQ-FAM-UNIT/funcional.md §21.8, `ADR-068 §5.5` (1.14b-a). La interfaz pública de la lista maestra: cada llamada compone
 * la lista de nuevo, **sin memoización entre llamadas ni caché** (`RN-FAM-UNIT-81`), con el reloj del servidor, y falla en
 * cerrado con un alumno o una persona desconocidos, borrados o de otro centro, o con el módulo `fam_unit` descontratado
 * (lista vacía, `isAuthorized = false`). La lectura con datos (`entriesFor`) es la auditada de `PickupListReader`.
 *
 * `isAuthorized` y `authorizedAmong` no se auditan: no devuelven datos personales.
 */
final class EloquentAuthorizedPickupList implements AuthorizedPickupList
{
    public function __construct(
        private readonly PickupListService $list,
        private readonly PickupListReader $reader,
        private readonly PersonDirectory $people,
        private readonly FamilyUnitStudentService $students,
        private readonly GuardianLinkService $links,
        private readonly ModuleAvailability $modules,
    ) {}

    public function isAuthorized(int $personId, int $studentPersonId): bool
    {
        return $this->authorizedAmong($studentPersonId, [$personId]) !== [];
    }

    public function grantFor(int $personId, int $studentPersonId): ?PickupGrant
    {
        if (! $this->modules->isEnabled('fam_unit')) {
            return null;
        }

        // Fallo en cerrado: ni el alumno ni la persona pueden ser desconocidos, de otro centro ni estar borrados.
        $refs = $this->people->refsByIds([$studentPersonId, $personId]);

        foreach ([$studentPersonId, $personId] as $id) {
            if (! isset($refs[$id]) || $refs[$id]->removed) {
                return null;
            }
        }

        $bases = array_map(
            static fn (array $row): PickupBasis => new PickupBasis($row['basis'], $row['reference'], $row['relationship'], $row['valid_from'], $row['valid_until'], $row['reference_id']),
            $this->list->compose($studentPersonId, [$personId]),
        );

        if ($bases === []) {
            return null;
        }

        return new PickupGrant($personId, $bases, isset($this->list->photosAmong([$personId])[$personId]));
    }

    public function entriesFor(int $studentPersonId, string $purpose): array
    {
        return $this->reader->read($studentPersonId, $purpose);
    }

    public function authorizedAmong(int $studentPersonId, array $personIds): array
    {
        $personIds = array_values(array_unique(array_map('intval', $personIds)));

        if ($personIds === [] || ! $this->modules->isEnabled('fam_unit')) {
            return [];
        }

        // Fallo en cerrado: ni el alumno ni la persona pueden ser desconocidos, de otro centro ni estar borrados.
        $refs = $this->people->refsByIds([$studentPersonId, ...$personIds]);

        if (! isset($refs[$studentPersonId]) || $refs[$studentPersonId]->removed) {
            return [];
        }

        $alive = array_values(array_filter($personIds, static fn (int $id): bool => isset($refs[$id]) && ! $refs[$id]->removed));

        return array_values(array_unique(array_column($this->list->compose($studentPersonId, $alive), 'person_id')));
    }

    public function studentsMatching(string $term, int $limit): array
    {
        if (! $this->modules->isEnabled('fam_unit')) {
            return [];
        }

        $universe = array_values(array_unique([...$this->students->memberStudentIds(), ...$this->links->linkedStudentIds()]));
        $found = $this->people->searchByNameAmong($term, $universe, $limit);
        $codes = $this->students->unitCodesOfStudents(array_map(static fn ($ref): int => $ref->id, $found));

        return array_map(static fn ($ref): StudentMatch => new StudentMatch($ref->id, $ref->publicId, $ref->displayName, $codes[$ref->id] ?? null), $found);
    }
}
