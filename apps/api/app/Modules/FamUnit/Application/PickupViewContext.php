<?php

namespace App\Modules\FamUnit\Application;

/**
 * REQ-FAM-UNIT/api.md §8.1 (1.14b-a): lo que la representación de una `PickupAuthorization` necesita saber de la petición y
 * de su sujeto, calculado una vez por el controlador y leído por el recurso (se registra con `scoped`, como `FamilyRefs`):
 * si cada autorización es vigente y por qué no, si el sujeto ve el motivo (`autorizado_recogida.leer` con ámbito `todos`) y
 * si ve la causa de no vigencia (`revocacion_tutor.leer`; sin él, toda causa es `no_vigente`, `RN-FAM-UNIT-36`).
 */
final class PickupViewContext
{
    /** @var array<int, array{effective: bool, reason: ?string}> */
    public array $effectiveness = [];

    public bool $showReason = false;

    public bool $showExcluded = false;
}
