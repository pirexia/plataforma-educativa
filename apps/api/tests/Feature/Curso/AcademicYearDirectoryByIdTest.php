<?php

use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001, 1.11-a. CA-ACAD-042 (OPEN-ACAD-12): AcademicYearDirectory
// resuelve un curso por su identificador interno, uno y varios, memoizado por
// petición y sin cruzar centros (INV-001). INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * @return list<string> sentencias sobre academic_years ejecutadas dentro de $callback
 */
function academicYearQueriesDuring(Closure $callback): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'academic_years')) {
            $queries[] = $query->sql;
        }
    });

    $callback();

    return $queries;
}

test('CA-ACAD-042 OPEN-ACAD-12: findById devuelve el resumen del curso del centro', function (): void {
    [$tenant] = provisionCoreTenant('acad-042a');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo);

    app(TenantContext::class)->runFor($tenant->id, function () use ($year): void {
        $summary = app(AcademicYearDirectory::class)->findById($year->id);

        expect($summary)->toBeInstanceOf(AcademicYearSummary::class)
            ->and($summary->id)->toBe($year->id)
            ->and($summary->publicId)->toBe($year->public_id)
            ->and($summary->code)->toBe('2025-2026')
            ->and(app(AcademicYearDirectory::class)->findById(999999999))->toBeNull();
    });
});

test('CA-ACAD-042 INV-001: el identificador de un curso de otro centro devuelve null, uno y varios', function (): void {
    [$tenantA] = provisionCoreTenant('acad-042b');
    [$tenantB] = provisionCoreTenant('acad-042c');
    $yearA = CursoTestHelpers::year($tenantA, '2025-2026', AcademicYearStatus::Activo);
    $yearB = CursoTestHelpers::year($tenantB, '2025-2026', AcademicYearStatus::Activo);

    app(TenantContext::class)->runFor($tenantA->id, function () use ($yearA, $yearB): void {
        $directory = app(AcademicYearDirectory::class);

        expect($directory->findById($yearB->id))->toBeNull()
            ->and(array_keys($directory->findManyById([$yearA->id, $yearB->id])))->toBe([$yearA->id]);
    });
});

test('CA-ACAD-042: findManyById devuelve identificador => resumen, ignora duplicados e inexistentes', function (): void {
    [$tenant] = provisionCoreTenant('acad-042d');
    $one = CursoTestHelpers::year($tenant, '2024-2025', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    $two = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo);

    app(TenantContext::class)->runFor($tenant->id, function () use ($one, $two): void {
        $found = app(AcademicYearDirectory::class)->findManyById([$two->id, $one->id, $two->id, 999999999]);

        expect(array_keys($found))->toBe([$two->id, $one->id])
            ->and($found[$one->id]->status)->toBe(AcademicYearStatus::Cerrado)
            ->and($found[$one->id]->isReadOnly())->toBeTrue()
            ->and(app(AcademicYearDirectory::class)->findManyById([]))->toBe([]);
    });
});

test('CA-ACAD-042 RN-CURSO-24: la base de datos se consulta una vez por identificador y petición', function (): void {
    [$tenant] = provisionCoreTenant('acad-042e');
    $one = CursoTestHelpers::year($tenant, '2024-2025', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    $two = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo);

    app(TenantContext::class)->runFor($tenant->id, function () use ($one, $two): void {
        $directory = app(AcademicYearDirectory::class);

        $queries = academicYearQueriesDuring(function () use ($directory, $one, $two): void {
            $directory->findById($one->id);
            $directory->findById($one->id);
            $directory->findManyById([$one->id, $two->id]);   // solo $two es nuevo
            $directory->findManyById([$one->id, $two->id]);
            $directory->findById(999999999);
            $directory->findById(999999999);                  // la inexistencia también se memoiza
            app(AcademicYearDirectory::class)->findById($two->id);
        });

        expect($queries)->toHaveCount(3);
    });
});

test('CA-ACAD-042 INV-001: la memoización es por centro: el mismo id no se comparte al cambiar de contexto', function (): void {
    [$tenantA] = provisionCoreTenant('acad-042f');
    [$tenantB] = provisionCoreTenant('acad-042g');
    $yearA = CursoTestHelpers::year($tenantA, '2025-2026', AcademicYearStatus::Activo);

    $directory = app(AcademicYearDirectory::class);

    $inA = app(TenantContext::class)->runFor($tenantA->id, fn () => $directory->findById($yearA->id));
    $inB = app(TenantContext::class)->runFor($tenantB->id, fn () => $directory->findById($yearA->id));

    expect($inA?->code)->toBe('2025-2026')
        ->and($inB)->toBeNull();
});
