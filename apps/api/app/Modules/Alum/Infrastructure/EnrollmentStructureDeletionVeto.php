<?php

namespace App\Modules\Alum\Infrastructure;

use App\Modules\Acad\Domain\StructureDeletionVeto;
use App\Modules\Acad\Domain\StructureEntity;
use App\Modules\Acad\Domain\VetoFailure;
use App\Modules\Alum\Domain\Models\Enrollment;

/**
 * RN-ALUM-26, RN-ACAD-21, CA-ALUM-027: veto de borrado de `alum` sobre la estructura de `acad`. Se registran dos
 * instancias independientes (una por entidad). Un grupo no se borra con matrículas vivas en él; un curso de etapa no
 * se borra con matrículas vivas en él, **tengan o no grupo**. «Viva» = `deleted_at IS NULL` (incluye `baja`; el
 * borrado lógico del modelo la excluye solo).
 *
 * Se ejecuta con la fila de `acad` bloqueada (RN-CURSO-32): una sola cuenta por índice
 * (`enrollments_tenant_course_group_idx` / `enrollments_tenant_year_course_idx`), sin `FOR UPDATE` ni bloqueo de
 * filas de `alum`. No es una consulta de lectura con ámbito del actor: cuenta todas las matrículas del centro.
 */
final class EnrollmentStructureDeletionVeto implements StructureDeletionVeto
{
    public function __construct(private readonly StructureEntity $entity) {}

    public function moduleCode(): string
    {
        return 'alum';
    }

    public function appliesTo(): StructureEntity
    {
        return $this->entity;
    }

    public function check(int $internalId): ?VetoFailure
    {
        $column = $this->entity === StructureEntity::Group ? 'group_id' : 'course_id';
        $count = Enrollment::query()->where($column, $internalId)->count();

        return $count > 0 ? new VetoFailure('enrollments', $count) : null;
    }
}
