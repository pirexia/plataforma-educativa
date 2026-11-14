<?php

namespace App\Modules\Curso\Infrastructure;

use App\Modules\Curso\Domain\AcademicYearContext;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;

/**
 * RN-CURSO-24, `CA-CURSO-047`: consulta por el índice único parcial
 * `academic_years_tenant_status_unique` una sola vez por petición y centro.
 * Registrado con `scoped()` (se reinicia entre peticiones y trabajos en
 * cola) y memoizado además por `tenant_id`, para que un cambio de contexto
 * de centro dentro de un mismo ciclo nunca devuelva el curso de otro.
 * **Sin caché entre peticiones.**
 */
final class EloquentAcademicYearContext implements AcademicYearContext
{
    /** @var array<int, array{0: ?AcademicYearSummary}> */
    private array $memo = [];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function active(): ?AcademicYearSummary
    {
        $tenantId = $this->tenantContext->tenantId();

        if (! isset($this->memo[$tenantId])) {
            $this->memo[$tenantId] = [
                AcademicYear::query()->where('status', AcademicYearStatus::Activo)->first()?->toSummary(),
            ];
        }

        return $this->memo[$tenantId][0];
    }

    /** Lo llama el servicio de transiciones tras cambiar un estado. */
    public function forget(): void
    {
        $this->memo = [];
    }
}
