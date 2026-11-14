<?php

namespace Tests\Support;

use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD (1.11-b): ayudantes de los tests del módulo. Crea el árbol
 * completo directamente por los modelos (sin API) dentro del contexto del
 * tenant; el estado del curso lo fija el test.
 */
final class AcadTestHelpers
{
    /**
     * Una de cada una de las seis entidades, colgadas del curso dado.
     *
     * @return array{level: EducationLevel, course: Course, group: Group, subgroup: Subgroup, subject: Subject, groupSubject: GroupSubject}
     */
    public static function tree(Tenant $tenant, AcademicYear $year, string $suffix = ''): array
    {
        return app(TenantContext::class)->runFor($tenant->id, function () use ($year, $suffix): array {
            $level = EducationLevel::create([
                'academic_year_id' => $year->id,
                'code' => "ESO{$suffix}",
                'name_translations' => ['es-ES' => 'Educación Secundaria', 'en' => 'Secondary'],
                'stage' => 'eso',
                'regime' => 'concertado',
                'position' => 1,
            ]);
            $course = Course::create([
                'academic_year_id' => $year->id,
                'education_level_id' => $level->id,
                'code' => "1ESO{$suffix}",
                'name_translations' => ['es-ES' => 'Primero de ESO'],
                'position' => 1,
            ]);
            $group = Group::create([
                'academic_year_id' => $year->id,
                'course_id' => $course->id,
                'code' => "A{$suffix}",
                'name' => 'Grupo A',
                'position' => 1,
            ]);
            $subgroup = Subgroup::create([
                'academic_year_id' => $year->id,
                'group_id' => $group->id,
                'code' => "A1{$suffix}",
                'name' => 'Subgrupo A1',
                'position' => 1,
            ]);
            $subject = Subject::create([
                'academic_year_id' => $year->id,
                'course_id' => $course->id,
                'code' => "MAT{$suffix}",
                'name_translations' => ['es-ES' => 'Matemáticas'],
                'position' => 1,
            ]);
            $groupSubject = GroupSubject::create([
                'academic_year_id' => $year->id,
                'course_id' => $course->id,
                'group_id' => $group->id,
                'subject_id' => $subject->id,
            ]);

            return compact('level', 'course', 'group', 'subgroup', 'subject', 'groupSubject');
        });
    }

    /**
     * Ejecuta una escritura en un punto de guardado: un error de PostgreSQL
     * no aborta la transacción del test. Devuelve el `SQLSTATE` o `null`.
     */
    public static function sqlStateOf(\Closure $write): ?string
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? null;
        }

        return null;
    }
}
