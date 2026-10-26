<?php

namespace App\Modules\FamUnit\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §7.4 (1.14-a, `OPEN-FAM-UNIT-01`): escritura de la pertenencia de la persona de un alumno
 * a una unidad familiar, para que `REQ-ALUM` (1.15) la registre al dar de alta un alumno sin acceder a las tablas de
 * este módulo (`INV-007`). Aplica **exactamente** las mismas reglas (`RN-FAM-UNIT-20`, `-22`) y produce la misma
 * auditoría que `POST`/`DELETE /family-units/{id}/students` (`CA-FAM-UNIT-011`): el endpoint llama a esta misma
 * implementación.
 *
 * Las reglas incumplidas lanzan `App\Support\Api\ApiException` (`404` unidad, `422 fam_unit.validation.student_not_found`,
 * `422 fam_unit.student.already_in_unit`).
 */
interface FamilyUnitStudents
{
    /**
     * Añade la persona de un alumno a la unidad `$familyUnitPublicId`. Devuelve el `public_id` de la pertenencia. Si
     * `ALUM` lo llama dentro de la transacción del alta del alumno, se une a ella.
     */
    public function add(string $familyUnitPublicId, int $studentPersonId): string;

    /**
     * Quita una pertenencia (baja lógica). **No** borra los vínculos del alumno (pueden cruzar unidades, C2):
     * devuelve cuántos vínculos vivos conserva (`RN-FAM-UNIT-22`).
     */
    public function remove(string $membershipPublicId): int;
}
