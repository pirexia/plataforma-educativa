<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ALUM-002 (1.15-b T2a): ayudantes de los tests de `EnrollmentService` y de los resolutores de ámbito.
 */
final class AlumEnrollmentHelpers
{
    /**
     * Centro con `acad`, `fam_unit` y `alum`, curso `activo` (2026-09-01 a 2027-06-30) con etapa, curso `1ESO`, grupos A y B
     * del mismo curso de etapa, una segunda etapa/curso `2ESO` con grupo, y tres alumnos. Devuelve `public_id`.
     *
     * @return array<string, mixed>
     */
    public static function scenario(string $slug): array
    {
        resetSessionState();
        [$tenant, $admin, $year] = AcadApiHelpers::setup($slug);
        contractModule($tenant, 'fam_unit');
        contractModule($tenant, 'alum');

        $api = AcadApiHelpers::tree($tenant, $admin, $year);
        $post = function (string $path, array $body) use ($tenant, $admin): string {
            resetSessionState();

            return test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, $path), $body)->assertCreated()->json('public_id');
        };
        $groupB = $post('/groups', ['course' => $api['course'], 'code' => 'B', 'name' => 'Grupo B']);
        $course2 = $post('/courses', ['education_level' => $api['level'], 'code' => '2ESO', 'name_translations' => ['es-ES' => 'Segundo']]);
        $group2 = $post('/groups', ['course' => $course2, 'code' => 'A2', 'name' => 'Grupo A2']);

        $students = [];

        foreach (['Uno', 'Dos', 'Tres'] as $n) {
            $students[] = AlumTestHelpers::student($tenant, $admin, ['given_name' => 'Alumno', 'family_name_1' => $n]);
        }

        return compact('tenant', 'admin', 'year', 'api', 'groupB', 'course2', 'group2', 'students');
    }

    /** Ejecuta `$fn` como `$actor` en el contexto del centro y con el estado por petición reiniciado. */
    public static function as(object $tenant, User $actor, Closure $fn): mixed
    {
        resetSessionState();
        app()->forgetScopedInstances();
        test()->actingAs($actor);

        return app(TenantContext::class)->runFor($tenant->id, $fn);
    }

    /**
     * Cuerpo de alta para el servicio.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function body(array $s, int $student = 0, array $overrides = []): array
    {
        return $overrides + [
            'student' => $s['students'][$student]['public_id'],
            'academic_year' => $s['year']->public_id,
            'course' => $s['api']['course'],
            'group' => $s['api']['group'],
            'enrolled_on' => '2026-09-10',
        ];
    }

    /** Curso académico en `planificacion` (2027-09-01 a 2028-06-30) con etapa y curso, sin grupos. */
    public static function planningYear(array $s): array
    {
        resetSessionState();
        test()->actingAs($s['admin']);
        $year = CursoTestHelpers::year($s['tenant'], '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-06-30');
        $api = AcadApiHelpers::tree($s['tenant'], $s['admin'], $year, 'P');

        return ['year' => $year, 'api' => $api];
    }

    public static function closeYear(array $s, AcademicYear $year): void
    {
        resetSessionState();
        test()->actingAs($s['admin']);
        CursoTestHelpers::setStatus($s['tenant'], $year, AcademicYearStatus::Cerrado);
    }

    /** Filas vivas de `enrollments` del centro. */
    public static function liveCount(object $tenant): int
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): int => DB::table('enrollments')->whereNull('deleted_at')->count());
    }
}
