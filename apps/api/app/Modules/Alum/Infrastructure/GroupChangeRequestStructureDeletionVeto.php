<?php

namespace App\Modules\Alum\Infrastructure;

use App\Modules\Acad\Domain\StructureDeletionVeto;
use App\Modules\Acad\Domain\StructureEntity;
use App\Modules\Acad\Domain\VetoFailure;
use App\Modules\Alum\Domain\Models\GroupChangeRequest;

/**
 * RN-ALUM-47, RN-ACAD-21 (1.15-c, decisión del usuario 2026-11-11 = B): veto de borrado de `alum` sobre un **grupo** de
 * `acad`: no se borra con solicitudes de cambio de grupo `pendiente` vivas que lo tengan de origen o de destino. Las
 * resueltas (`aprobada`, `rechazada`, `cancelada`) no vetan. No aplica al curso de etapa (sus matrículas ya lo vetan,
 * `RN-ALUM-26`).
 *
 * Se ejecuta con la fila de `acad` bloqueada (RN-CURSO-32): una sola cuenta por índice
 * (`group_change_requests_tenant_year_from_group_idx` / `..._to_group_idx`), sin bloqueo de filas de `alum`. No es una
 * consulta de lectura con ámbito del actor: cuenta todas las solicitudes del centro.
 */
final class GroupChangeRequestStructureDeletionVeto implements StructureDeletionVeto
{
    public function moduleCode(): string
    {
        return 'alum';
    }

    public function appliesTo(): StructureEntity
    {
        return StructureEntity::Group;
    }

    public function check(int $internalId): ?VetoFailure
    {
        $count = GroupChangeRequest::query()
            ->where('status', 'pendiente')
            ->where(fn ($query) => $query->where('from_group_id', $internalId)->orWhere('to_group_id', $internalId))
            ->count();

        return $count > 0 ? new VetoFailure('group_change_requests', $count) : null;
    }
}
