<?php

namespace App\Modules\Acad\Infrastructure;

use App\Modules\Acad\Domain\Models\GroupTutor;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use App\Modules\Acad\Domain\TeachingMembership;
use App\Support\Tenancy\TenantContext;

/**
 * `RN-ACAD-98`, §18.7: una sola consulta (`UNION` de tutorías y asignaciones),
 * ambas por `TenantScope` y borrado lógico. Memoizada por petición, centro,
 * usuario y curso (registrada con `scoped()`).
 */
final class EloquentTeachingMembership implements TeachingMembership
{
    /** @var array<string, list<int>> */
    private array $memo = [];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function groupIdsFor(int $userId, int $academicYearId): array
    {
        $key = $this->tenantContext->tenantId().':'.$userId.':'.$academicYearId;

        if (! isset($this->memo[$key])) {
            $assignments = TeachingAssignment::query()
                ->where('user_id', $userId)->where('academic_year_id', $academicYearId)
                ->select('group_id');

            $ids = GroupTutor::query()
                ->where('user_id', $userId)->where('academic_year_id', $academicYearId)
                ->select('group_id')
                ->union($assignments)
                ->orderBy('group_id')
                ->pluck('group_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $this->memo[$key] = $ids;
        }

        return $this->memo[$key];
    }
}
