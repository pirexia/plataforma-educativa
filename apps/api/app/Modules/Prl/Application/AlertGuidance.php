<?php

namespace App\Modules\Prl\Application;

use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\FamilyMembership;

/**
 * REQ-PRL/funcional.md `RN-PRL-08`, `OPEN-FAM-UNIT-33`, `OPEN-FAM-UNIT-40` (1.14b-b): lo que la puerta muestra con una alerta
 * abierta, **calculado al leer y nunca guardado** (`RN-PRL-01`):
 *
 * - el **protocolo** de actuación: el texto mínimo fijo del producto en el idioma de la petición (no existe todavía el almacén
 *   de configuración por módulo que permitiría un texto propio del centro, `OPEN-FAM-UNIT-40`);
 * - los **teléfonos** del tutor principal de la unidad del alumno y de su contacto de emergencia, para la llamada manual.
 *   Los «quién» los da `fam_unit` por su interfaz pública `FamilyMembership` (el principal, y los vínculos **activos**) y el
 *   número, `Core` por `PersonDirectory` (`INV-007`). El principal con el acceso **revocado** a ese alumno no aparece (fallo
 *   en cerrado; la puerta no revela la revocación, `OPEN-FAM-UNIT-34`). Sin teléfono registrado, `null`.
 */
final class AlertGuidance
{
    public function __construct(
        private readonly FamilyMembership $membership,
        private readonly PersonDirectory $people,
    ) {}

    public function protocol(): string
    {
        return __('prl.protocol.minimal');
    }

    /**
     * @return array{principal_phone: ?string, emergency_phone: ?string}
     */
    public function contactsFor(int $studentPersonId): array
    {
        $principalId = $this->membership->unitOfStudent($studentPersonId)?->principalGuardianPersonId;

        if ($principalId !== null && $this->membership->isAccessRevoked($principalId, $studentPersonId)) {
            $principalId = null;
        }

        $emergencyId = null;

        foreach ($this->membership->activeGuardiansOf($studentPersonId) as $guardian) {
            if ($guardian->isEmergencyContact) {
                $emergencyId = $guardian->guardianPersonId;
            }
        }

        $ids = array_values(array_unique(array_filter([$principalId, $emergencyId], static fn (?int $id): bool => $id !== null)));
        $contacts = $ids === [] ? [] : $this->people->contactDetailsByIds($ids);

        return [
            'principal_phone' => $principalId === null ? null : ($contacts[$principalId]->contactPhone ?? null),
            'emergency_phone' => $emergencyId === null ? null : ($contacts[$emergencyId]->contactPhone ?? null),
        ];
    }
}
