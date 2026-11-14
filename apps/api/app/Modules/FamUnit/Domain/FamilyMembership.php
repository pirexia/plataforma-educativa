<?php

namespace App\Modules\FamUnit\Domain;

/**
 * REQ-FAM-UNIT/funcional.md §7.1 (1.14-a, `REQ-FAM-UNIT-002`, `ADR-032`): lectura de la pertenencia familiar, la única
 * puerta de los demás módulos hacia los vínculos tutor ↔ alumno (`INV-007`, `RARQ-ARC-003`). Trabaja con ids internos
 * de persona (`people.id`), igual que `UserDirectory::activeIdsAmong()`, porque es lo que los consumidores guardan en
 * sus claves foráneas.
 *
 * Contrato común:
 * - **Una consulta por llamada**, sin N+1.
 * - **Sin memoización entre llamadas ni entre peticiones** (`RN-FAM-UNIT-34`): una revocación surte efecto en la
 *   petición siguiente. Los consumidores tampoco deben cachear el resultado entre peticiones
 *   (`permisos.md §3.2` punto 3).
 * - Solo cuenta el vínculo **activo** y vivo; un vínculo revocado nunca da acceso (`RN-FAM-UNIT-35`).
 * - Con el módulo `fam_unit` descontratado, los conjuntos salen vacíos y `unitOfStudent()` es `null`
 *   (`funcional.md §9`, fallo en cerrado). `isAccessRevoked()` **no** depende de la contratación (`§15`, D-3).
 */
interface FamilyMembership
{
    /**
     * Ids de persona de los alumnos con vínculo **activo** con la persona `$personId` (la del tutor). Si se pide una
     * categoría de información (`salud`), excluye los vínculos que la restringen. Una persona sin faceta de tutor, o
     * sin vínculos activos, ⇒ lista vacía. Orden ascendente, sin repetidos.
     *
     * Consumidores previstos: los resolutores `unidad_familiar` de los módulos con datos de alumnado
     * (`ADR-063 §5.2`).
     *
     * @return list<int>
     */
    public function studentPersonIdsReachedBy(int $personId, ?string $category = null): array;

    /**
     * Tutores **activos** del alumno `$studentPersonId` con los atributos de su vínculo, ordenados por id de persona
     * del tutor. Sin tutores activos ⇒ lista vacía.
     *
     * @return list<GuardianOfStudent>
     */
    public function activeGuardiansOf(int $studentPersonId): array;

    /**
     * ¿Está revocado el acceso de la persona `$guardianPersonId` al alumno `$studentPersonId`? `true` si existe un
     * vínculo vivo `revocado` entre ambos (`ADR-032`: el módulo de entrega de menores lo exige para excluir al tutor
     * de la lista maestra de autorizados).
     */
    public function isAccessRevoked(int $guardianPersonId, int $studentPersonId): bool;

    /**
     * La unidad familiar del alumno y el id de persona de su tutor principal; `null` si el alumno no pertenece a
     * ninguna unidad viva.
     */
    public function unitOfStudent(int $studentPersonId): ?StudentUnit;
}
