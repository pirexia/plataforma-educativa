<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;

/**
 * `CA-CURSO-045`, `CA-ACAD-042`, `INV-001`: la consulta pasa por
 * `TenantScope`, así que un `public_id` o un identificador de otro centro es
 * indistinguible de uno inexistente (`null`). La búsqueda por identificador
 * interno se memoiza por petición y centro (registrado con `scoped()`, se
 * reinicia entre peticiones y trabajos en cola; la clave incluye el
 * `tenant_id` para que un cambio de contexto dentro del ciclo nunca devuelva
 * el curso de otro centro). Sin caché entre peticiones. La inexistencia
 * también se memoiza (una consulta por identificador y petición).
 */
final class EloquentAcademicYearDirectory implements AcademicYearDirectory
{
    /** @var array<int, array<int, ?AcademicYearSummary>> tenant_id => id => resumen */
    private array $memo = [];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function findByPublicId(string $publicId): ?AcademicYearSummary
    {
        return AcademicYear::query()->where('public_id', $publicId)->first()?->toSummary();
    }

    public function findById(int $id): ?AcademicYearSummary
    {
        return $this->findManyById([$id])[$id] ?? null;
    }

    public function findManyById(array $ids): array
    {
        $tenantId = $this->tenantContext->tenantId();
        $unique = array_values(array_unique($ids));
        $missing = array_values(array_filter($unique, fn (int $id): bool => ! array_key_exists($id, $this->memo[$tenantId] ?? [])));

        if ($missing !== []) {
            $found = AcademicYear::query()->whereIn('id', $missing)->get()->keyBy('id');

            foreach ($missing as $id) {
                $this->memo[$tenantId][$id] = $found->get($id)?->toSummary();
            }
        }

        $result = [];

        foreach ($unique as $id) {
            $summary = $this->memo[$tenantId][$id] ?? null;

            if ($summary !== null) {
                $result[$id] = $summary;
            }
        }

        return $result;
    }
}
