<?php

namespace App\Modules\Acad\Infrastructure;

use App\Modules\Acad\Domain\CourseDirectory;
use App\Modules\Acad\Domain\CourseSummary;
use App\Modules\Acad\Domain\Models\Course;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * INV-001: la consulta pasa por `TenantScope` y por el borrado lógico. Memoiza por petición y centro (registrado con
 * `scoped()`); solo guarda los encontrados, como `EloquentGroupDirectory`.
 */
final class EloquentCourseDirectory implements CourseDirectory
{
    /** @var array<int, array<int, CourseSummary>> tenant_id => id => resumen */
    private array $byId = [];

    /** @var array<int, array<string, CourseSummary>> tenant_id => public_id => resumen */
    private array $byPublicId = [];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function findByPublicId(string $publicId): ?CourseSummary
    {
        $tenantId = $this->tenantContext->tenantId();

        return $this->byPublicId[$tenantId][$publicId] ?? $this->load(Course::query()->where('public_id', $publicId), $tenantId);
    }

    public function findById(int $id): ?CourseSummary
    {
        $tenantId = $this->tenantContext->tenantId();

        return $this->byId[$tenantId][$id] ?? $this->load(Course::query()->where('id', $id), $tenantId);
    }

    /**
     * @param  Builder<Course>  $query
     */
    private function load($query, int $tenantId): ?CourseSummary
    {
        $course = $query->first();

        if ($course === null) {
            return null;
        }

        $summary = new CourseSummary(
            $course->id,
            $course->public_id,
            $course->academic_year_id,
            $course->education_level_id,
            (string) $course->code,
            $course->name_translations ?? [],
        );
        $this->byId[$tenantId][$summary->id] = $summary;
        $this->byPublicId[$tenantId][$summary->publicId] = $summary;

        return $summary;
    }
}
