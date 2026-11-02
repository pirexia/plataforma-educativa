<?php

namespace App\Modules\Core\Domain;

/**
 * ADR-068 §5.2 (D2, `L4`), `OPEN-068-02` y `OPEN-068-07`: la **única** vía de escritura sobre `people` que tienen los
 * módulos distintos de `Core` (`INV-007`). Hoy el único alta de persona es `CreateUser`, que crea persona y usuario
 * juntos; esta interfaz da de alta a una persona **sin cuenta** (un tercero autorizado a recoger) y corrige a una que no
 * tiene usuario. La implementación vive en `Core\Infrastructure`.
 *
 * Contrato común: el centro es el del contexto (`TenantScope` + RLS, `INV-001`); la interfaz **no comprueba permisos**
 * (igual que `FamilyUnitStudents`): lo hace el *endpoint* del módulo que la invoca (`INV-002`). La creación y la
 * corrección las audita `Core` por el ciclo de vida de `Person` (`Auditable`: `created` y `updated`, `INV-003`).
 */
interface PersonRegistrar
{
    /**
     * Crea una persona sin cuenta o reutiliza la persona **viva** del centro con el mismo documento. **Nunca modifica
     * una persona existente.** Valida el documento con las reglas de `Core` (`RN-CORE-90` a `-93`): un tipo o número
     * inválidos lanzan `422` (`ApiException`).
     *
     * Con documento, la búsqueda y la inserción van en la misma transacción; si dos altas simultáneas con el mismo
     * documento chocan con `people_tenant_document_unique`, la que pierde relee y reutiliza, sin error. **Sin documento,
     * siempre crea**: no hay clave con la que reutilizar.
     *
     * Reutiliza también a la persona de un alumno o de un tutor revocado: la elegibilidad es de `fam_unit`
     * (`RN-FAM-UNIT-86`), no de `Core`.
     */
    public function registerOrReuse(PersonRegistration $data): PersonRegistrationOutcome;

    /**
     * Corrige nombre, apellidos y teléfono de una persona **sin usuario**. No toca el documento ni ningún otro campo.
     *
     * @throws PersonNotCorrectableException si la persona no existe en el centro, está borrada o tiene usuario
     */
    public function correctUnaccounted(int $personId, PersonCorrection $data): void;
}
