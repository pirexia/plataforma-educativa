<?php

namespace App\Modules\Acad\Infrastructure;

use App\Modules\Acad\Domain\GroupDirectory;
use App\Modules\Acad\Domain\GroupSummary;
use App\Modules\Acad\Domain\Models\Group;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * §7.2, INV-001: la consulta pasa por `TenantScope` y por el borrado lógico.
 * Memoiza por petición y centro (registrado con `scoped()`); solo guarda los
 * encontrados, como `EloquentAcademicYearDirectory`.
 */
final class EloquentGroupDirectory implements GroupDirectory
{
    /** @var array<int, array<int, GroupSummary>> tenant_id => id => resumen */
    private array $byId = [];

    /** @var array<int, array<string, GroupSummary>> tenant_id => public_id => resumen */
    private array $byPublicId = [];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function findByPublicId(string $publicId): ?GroupSummary
    {
        $tenantId = $this->tenantContext->tenantId();

        return $this->byPublicId[$tenantId][$publicId] ?? $this->load(Group::query()->where('public_id', $publicId), $tenantId);
    }

    public function findById(int $id): ?GroupSummary
    {
        $tenantId = $this->tenantContext->tenantId();

        return $this->byId[$tenantId][$id] ?? $this->load(Group::query()->where('id', $id), $tenantId);
    }

    /**
     * @param  Builder<Group>  $query
     */
    private function load($query, int $tenantId): ?GroupSummary
    {
        $group = $query->with('course.educationLevel')->first();
        $course = $group?->course;
        $level = $course?->educationLevel;

        if ($group === null || $course === null || $level === null) {
            return null;
        }

        $summary = new GroupSummary($group->id, $group->public_id, $group->academic_year_id, $course->id, $level->id, (string) $level->regime);
        $this->byId[$tenantId][$summary->id] = $summary;
        $this->byPublicId[$tenantId][$summary->publicId] = $summary;

        return $summary;
    }
}
