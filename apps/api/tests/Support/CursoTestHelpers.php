<?php

namespace Tests\Support;

use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * REQ-CURSO (1.10): ayudantes de los tests del módulo. Crea cursos
 * directamente por el modelo (sin pasar por la API) en el estado que haga
 * falta; el estado inicial lo fija el test, no una transición.
 */
final class CursoTestHelpers
{
    public static function year(Tenant $tenant, string $code, AcademicYearStatus $status, string $startsOn = '2026-09-01', string $endsOn = '2027-06-30'): AcademicYear
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): AcademicYear => AcademicYear::create([
            'code' => $code,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => $status,
        ]));
    }

    public static function setStatus(Tenant $tenant, AcademicYear $year, AcademicYearStatus $status): void
    {
        app(TenantContext::class)->runFor($tenant->id, function () use ($year, $status): void {
            $year->status = $status;
            $year->save();
        });
    }
}
