<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\Models\AcademicYear;

/**
 * `CA-CURSO-045`, `INV-001`: la consulta pasa por `TenantScope`, así que un
 * `public_id` de otro centro es indistinguible de uno inexistente (`null`).
 */
final class EloquentAcademicYearDirectory implements AcademicYearDirectory
{
    public function findByPublicId(string $publicId): ?AcademicYearSummary
    {
        return AcademicYear::query()->where('public_id', $publicId)->first()?->toSummary();
    }
}
