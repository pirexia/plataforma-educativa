<?php

namespace App\Modules\FamUnit\Application;

use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use Illuminate\Database\Eloquent\Builder;

/**
 * REQ-FAM-UNIT/funcional.md §3.6 (`RN-FAM-UNIT-34`, `-35`, `-36`): qué alumnos alcanza un usuario autenticado con el
 * ámbito `unidad_familiar`. Se resuelve por **vínculos activos**, no por pertenencia a la unidad: es la única forma
 * de que una revocación no afecte al resto de la unidad y a la vez el tutor revocado no vea nada del alumno.
 *
 * Una sola definición para los resolutores de ámbito (listado y detalle, `ADR-044 §3.4`) y para los recuentos. Sin
 * memoización entre llamadas: cada llamada compone una consulta nueva, que lee el estado del momento
 * (`RN-FAM-UNIT-34`).
 */
final class FamilyScope
{
    public function __construct(
        private readonly PersonDirectory $people,
        private readonly GuardianService $guardians,
        private readonly GuardianLinkService $links,
    ) {}

    /**
     * Subconsulta de los ids de persona de los alumnos con vínculo activo con la faceta de tutor del sujeto; `null` si
     * el sujeto no tiene persona o no es tutor (⇒ conjunto vacío, deniega).
     *
     * @return Builder<GuardianStudentLink>|null
     */
    public function reachableStudentIds(User $subject): ?Builder
    {
        $personId = $this->people->personIdForUser((int) $subject->id);

        if ($personId === null) {
            return null;
        }

        $guardianId = $this->guardians->guardianIdForPerson($personId);

        return $guardianId === null ? null : $this->links->reachableStudentIds($guardianId);
    }
}
