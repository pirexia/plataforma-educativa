<?php

namespace App\Modules\Acad\Infrastructure;

use App\Modules\Acad\Domain\GroupDirectory;
use App\Modules\Acad\Domain\GroupSummary;
use App\Modules\Acad\Domain\Models\Group;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

    public function findManyById(array $ids): array
    {
        $tenantId = $this->tenantContext->tenantId();
        $unique = array_values(array_unique($ids));
        $missing = array_values(array_filter($unique, fn (int $id): bool => ! isset($this->byId[$tenantId][$id])));

        if ($missing !== []) {
            foreach (Group::query()->whereIn('id', $missing)->with('course.educationLevel')->get() as $group) {
                $this->summarize($group, $tenantId);
            }
        }

        $result = [];

        foreach ($unique as $id) {
            if (isset($this->byId[$tenantId][$id])) {
                $result[$id] = $this->byId[$tenantId][$id];
            }
        }

        return $result;
    }

    public function lockAliveForShare(int $id): ?GroupSummary
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('lockAliveForShare() exige una transacción abierta.');
        }

        return $this->load(Group::query()->where('id', $id)->sharedLock(), $this->tenantContext->tenantId());
    }

    /**
     * @param  Builder<Group>  $query
     */
    private function load($query, int $tenantId): ?GroupSummary
    {
        $group = $query->with('course.educationLevel')->first();

        return $group === null ? null : $this->summarize($group, $tenantId);
    }

    private function summarize(Group $group, int $tenantId): ?GroupSummary
    {
        $course = $group->course;
        $level = $course?->educationLevel;

        if ($course === null || $level === null) {
            return null;
        }

        $summary = new GroupSummary($group->id, $group->public_id, $group->academic_year_id, $course->id, $level->id, (string) $level->regime, (string) $group->code, (string) $group->name);
        $this->byId[$tenantId][$summary->id] = $summary;
        $this->byPublicId[$tenantId][$summary->publicId] = $summary;

        return $summary;
    }
}
