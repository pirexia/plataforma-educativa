<?php

namespace App\Modules\Prl\Application;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\AuthorizedPickupList;
use App\Modules\FamUnit\Domain\PickupEntry;
use App\Modules\FamUnit\Domain\StudentMatch;
use App\Support\Api\ApiException;

/**
 * REQ-PRL/funcional.md §21.5.5 (1), (2), `RN-PRL-01`, `RN-PRL-10`, api.md §8.4 (1.14b-b, `REQ-PRL-004`): la consulta rápida de
 * la puerta. **Solo consume** `AuthorizedPickupList` (`INV-007`): el buscador de alumnos es `studentsMatching()` y la lista
 * es `entriesFor()` con finalidad `puerta`, que es la **lectura auditada y limitada de `fam_unit`** (una fila `read` por
 * consulta, `RN-FAM-UNIT-92`, y el cubo de `RN-FAM-UNIT-93`, `429`): `prl` no escribe esa auditoría ni la puede saltar
 * (`CA-PRL-010`). Sin caché: la lista se calcula en cada petición (`RN-FAM-UNIT-81`).
 */
final class DoorService
{
    /** `api.md §8.4`: máximo de alumnos devueltos por el buscador. */
    public const STUDENT_SEARCH_LIMIT = 20;

    public function __construct(
        private readonly AuthorizedPickupList $pickupList,
        private readonly PersonDirectory $people,
    ) {}

    /**
     * @return list<StudentMatch>
     */
    public function students(string $term): array
    {
        return $this->pickupList->studentsMatching($term, self::STUDENT_SEARCH_LIMIT);
    }

    /**
     * La lista de puerta de un alumno. `404` si el alumno no existe, es de otro centro o está borrado.
     *
     * @return list<PickupEntry>
     */
    public function pickupList(string $studentPersonPublicId): array
    {
        $student = $this->people->findByPublicId($studentPersonPublicId);

        if ($student === null || $student->removed) {
            throw ApiException::notFound();
        }

        return $this->pickupList->entriesFor($student->id, AuthorizedPickupList::PURPOSE_DOOR);
    }
}
