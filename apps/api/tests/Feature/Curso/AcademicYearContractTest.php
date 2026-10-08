<?php

use App\Modules\Curso\Domain\AcademicYearClosureCheck;
use App\Modules\Curso\Domain\AcademicYearClosureFailure;
use App\Modules\Curso\Domain\AcademicYearClosureRegistry;
use App\Modules\Curso\Domain\AcademicYearContext;
use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Modules\Curso\Domain\AcademicYearReadAccess;
use App\Modules\Curso\Domain\AcademicYearReadDeniedException;
use App\Modules\Curso\Domain\AcademicYearReadOnlyException;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\AcademicYearWriteGuard;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\CursoTestHelpers;

// Contrato transversal de Curso\Domain (REQ-CURSO-001 puntos 2-3, RN-CURSO-24
// a -26, RN-CURSO-30), funcional.md §7.1, INV-001, INV-007, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/** Usuario activo sin ningún rol: no tiene ningún permiso (RPERM-011). */
function userWithoutRoles(string $slug): array
{
    [$tenant, $user] = provisionActiveUser($slug);

    return [$tenant, $user];
}

test('CA-CURSO-044 RN-CURSO-25 OPEN-CURSO-16: sin curso_historico.leer, el contrato de lectura deniega un curso cerrado con la excepción que produce 404', function (): void {
    [$tenant, $user] = userWithoutRoles('cur-044');
    $closed = CursoTestHelpers::year($tenant, '2024-2025', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    $archived = CursoTestHelpers::year($tenant, '2023-2024', AcademicYearStatus::Archivado, '2023-09-01', '2024-06-30');

    $access = app(AcademicYearReadAccess::class);

    app(TenantContext::class)->runFor($tenant->id, function () use ($access, $user, $closed, $archived): void {
        expect($access->canRead($closed->toSummary(), $user))->toBeFalse()
            ->and($access->canRead($archived->toSummary(), $user))->toBeFalse()
            ->and(fn () => $access->assertCanRead($closed->toSummary(), $user))->toThrow(AcademicYearReadDeniedException::class);
    });

    // Traducida a 404, nunca 403: no se confirma que existan datos (ADR-038 §6.4).
    Route::middleware(['resolve-tenant', 'resolve-locale'])->get('/api/v1/_test/curso-read-044', function () use ($closed, $user) {
        app(AcademicYearReadAccess::class)->assertCanRead($closed->toSummary(), $user);

        return response()->json(['ok' => true]);
    });

    test()->getJson('http://'.$tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/curso-read-044')
        ->assertNotFound()
        ->assertJsonPath('type', 'urn:pge:error:not-found');
});

test('CA-CURSO-044 RN-CURSO-25: con curso_historico.leer permite; planificacion y activo permiten sin pedir ese permiso', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-044b');
    [, $plain] = userWithoutRoles('cur-044c');
    $closed = CursoTestHelpers::year($tenant, '2024-2025', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    $active = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');
    $planning = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-06-30');

    $access = app(AcademicYearReadAccess::class);

    app(TenantContext::class)->runFor($tenant->id, function () use ($access, $admin, $plain, $closed, $active, $planning): void {
        expect($access->canRead($closed->toSummary(), $admin))->toBeTrue()
            ->and($access->canRead($active->toSummary(), $plain))->toBeTrue()
            ->and($access->canRead($planning->toSummary(), $plain))->toBeTrue();
    });
});

test('CA-CURSO-045 INV-001: el directorio devuelve null para un curso de otro centro y una ruta que lo usa responde 404', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('cur-045a');
    [$tenantB] = provisionCoreTenant('cur-045b');
    $yearA = CursoTestHelpers::year($tenantA, '2025-2026', AcademicYearStatus::Activo);
    $yearB = CursoTestHelpers::year($tenantB, '2025-2026', AcademicYearStatus::Activo);

    app(TenantContext::class)->runFor($tenantA->id, function () use ($yearA, $yearB): void {
        $directory = app(AcademicYearDirectory::class);

        expect($directory->findByPublicId($yearA->public_id))->toBeInstanceOf(AcademicYearSummary::class)
            ->and($directory->findByPublicId($yearA->public_id)->code)->toBe('2025-2026')
            ->and($directory->findByPublicId($yearB->public_id))->toBeNull()
            ->and($directory->findByPublicId('01JA0000000000000000000000'))->toBeNull();
    });

    // Convención de api.md §3: `academic_year` = public_id; inexistente o ajeno => 404.
    Route::middleware(['resolve-tenant', 'resolve-locale'])->get('/api/v1/_test/curso-045', function (Request $request) {
        $year = app(AcademicYearDirectory::class)->findByPublicId((string) $request->query('academic_year')) ?? throw ApiException::notFound();

        return response()->json(['code' => $year->code]);
    });

    $url = 'http://'.$tenantA->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/curso-045';

    test()->actingAs($adminA)->getJson("{$url}?academic_year={$yearA->public_id}")->assertOk();
    test()->actingAs($adminA)->getJson("{$url}?academic_year={$yearB->public_id}")->assertNotFound();
});

test('CA-CURSO-047 RN-CURSO-24: dentro de una petición AcademicYearContext consulta la base de datos una sola vez', function (): void {
    [$tenant] = provisionCoreTenant('cur-047');
    CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $context = app(AcademicYearContext::class);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'academic_years')) {
                $queries[] = $query->sql;
            }
        });

        $first = $context->active();
        $second = $context->active();
        $third = app(AcademicYearContext::class)->active();

        expect($first?->code)->toBe('2025-2026')
            ->and($second)->toBe($first)
            ->and($third)->toBe($first)
            ->and($queries)->toHaveCount(1);
    });
});

test('CA-CURSO-047 RN-CURSO-24: el curso activo nunca se comparte entre centros ni se cachea entre peticiones', function (): void {
    [$tenantA] = provisionCoreTenant('cur-047a');
    [$tenantB] = provisionCoreTenant('cur-047b');
    CursoTestHelpers::year($tenantA, 'curso-A', AcademicYearStatus::Activo);

    $context = app(AcademicYearContext::class);

    $inA = app(TenantContext::class)->runFor($tenantA->id, fn () => $context->active()?->code);
    $inB = app(TenantContext::class)->runFor($tenantB->id, fn () => $context->active()?->code);

    expect($inA)->toBe('curso-A')->and($inB)->toBeNull();

    // Otra «petición» (instancia nueva del ámbito): lee de la base de datos.
    app()->forgetScopedInstances();
    CursoTestHelpers::setStatus($tenantA, app(TenantContext::class)->runFor($tenantA->id, fn () => AcademicYear::query()->firstOrFail()), AcademicYearStatus::Cerrado);

    expect(app(TenantContext::class)->runFor($tenantA->id, fn () => app(AcademicYearContext::class)->active()))->toBeNull();
});

test('RN-CURSO-20 ADR-057 §5.6: AcademicYearWriteGuard es una comprobación previa consultiva que lanza la excepción que se traduce a 409', function (): void {
    [$tenant] = userWithoutRoles('cur-guard');
    $closed = CursoTestHelpers::year($tenant, '2024-2025', AcademicYearStatus::Cerrado, '2024-09-01', '2025-06-30');
    $open = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');

    $guard = app(AcademicYearWriteGuard::class);

    expect($guard->admitsWrites($open->toSummary()))->toBeTrue()
        ->and($guard->admitsWrites($closed->toSummary()))->toBeFalse()
        ->and(fn () => $guard->assertWritable($closed->toSummary()))->toThrow(AcademicYearReadOnlyException::class);

    $guard->assertWritable($open->toSummary());

    Route::middleware(['resolve-tenant', 'resolve-locale'])->post('/api/v1/_test/curso-guard-previa', function () use ($closed) {
        app(AcademicYearWriteGuard::class)->assertWritable($closed->toSummary());

        return response()->json(['ok' => true]);
    });

    test()->postJson('http://'.$tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/curso-guard-previa')
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:pge:error:academic-year-closed')
        ->assertJsonPath('errors.academic_year.0.code', 'curso.academic_year_closed')
        ->assertJsonPath('errors.academic_year.0.params', ['code' => '2024-2025', 'status' => 'cerrado']);
});

test('RN-CURSO-30 CA-CURSO-026: el registro de validaciones de cierre está vacío en 1.10 y admite registrar una', function (): void {
    $registry = app(AcademicYearClosureRegistry::class);

    expect($registry->all())->toBe([])
        ->and(app(AcademicYearClosureRegistry::class))->toBe($registry);

    $registry->register(new class implements AcademicYearClosureCheck
    {
        public function check(AcademicYearSummary $year): ?AcademicYearClosureFailure
        {
            return null;
        }
    });

    expect($registry->all())->toHaveCount(1);
});

test('RN-CURSO-26 INV-007: Curso\Domain no expone el modelo Eloquent: la vista inmutable lleva solo lo necesario', function (): void {
    [$tenant] = userWithoutRoles('cur-vista');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo);
    $summary = $year->toSummary();

    expect($summary)->toBeInstanceOf(AcademicYearSummary::class)
        ->and($summary->id)->toBe($year->id)
        ->and($summary->publicId)->toBe($year->public_id)
        ->and($summary->status)->toBe(AcademicYearStatus::Activo)
        ->and($summary->startsOn->toDateString())->toBe('2026-09-01')
        ->and($summary->isReadOnly())->toBeFalse();
});
