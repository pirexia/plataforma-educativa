<?php

use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Infrastructure\AcademicYearClosedTranslator;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AcadTestHelpers;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11-b), CA-ACAD-020 a nivel de modelo/motor, RN-ACAD-07,
// RN-CURSO-20/-21, ADR-057, INV-015. Primeras tablas reales con el
// disparador `academic_year_write_guard`: sobre un curso `cerrado` o
// `archivado`, crear, modificar o borrar cualquiera de las seis entidades
// falla con SQLSTATE YC001 y la fila no cambia. La respuesta HTTP 409 es de
// los endpoints (lote 2). Base de datos real (ADR-033 §10).

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->year = CursoTestHelpers::year($this->tenant, '2025-2026', AcademicYearStatus::Activo);
    $this->tree = AcadTestHelpers::tree($this->tenant, $this->year);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, array{0: string, 1: class-string}> */
function acadEntities(): array
{
    return [
        'level' => ['education_levels', EducationLevel::class],
        'course' => ['courses', Course::class],
        'group' => ['groups', Group::class],
        'subgroup' => ['subgroups', Subgroup::class],
        'subject' => ['subjects', Subject::class],
        'groupSubject' => ['group_subjects', GroupSubject::class],
    ];
}

/** Modificación aplicable a cada entidad (la asignación solo tiene columnas de sistema: se mueve `updated_at`; el disparador salta en cualquier UPDATE). */
function acadChange(string $key, object $model): void
{
    match ($key) {
        'level', 'course', 'subject' => $model->name_translations = ['es-ES' => 'Cambiado'],
        'group', 'subgroup' => $model->name = 'Cambiado',
        'groupSubject' => $model->updated_at = now()->addDay(),
    };
}

foreach (['cerrado' => AcademicYearStatus::Cerrado, 'archivado' => AcademicYearStatus::Archivado] as $label => $status) {
    foreach (acadEntities() as $key => [$table, $class]) {
        test("CA-ACAD-020 RN-ACAD-07 RN-CURSO-21 ADR-057: en un curso {$label}, modificar y borrar {$table} falla con YC001 y la fila no cambia", function () use ($status, $key, $table, $class): void {
            $id = $this->tree[$key]->id;
            CursoTestHelpers::setStatus($this->tenant, $this->year, $status);

            app(TenantContext::class)->enter($this->tenant->id);
            $before = (array) DB::table($table)->where('id', $id)->first();

            $update = AcadTestHelpers::sqlState(function () use ($class, $id, $key): void {
                $model = $class::query()->findOrFail($id);
                acadChange($key, $model);
                $model->save();
            });
            $delete = AcadTestHelpers::sqlState(fn () => $class::query()->findOrFail($id)->delete());

            expect($update)->toBe(AcademicYearClosedTranslator::SQLSTATE)
                ->and($delete)->toBe(AcademicYearClosedTranslator::SQLSTATE)
                ->and((array) DB::table($table)->where('id', $id)->first())->toEqual($before);
        });
    }

    test("CA-ACAD-020 RN-ACAD-07 RN-CURSO-21 ADR-057: en un curso {$label}, crear cualquiera de las seis entidades falla con YC001 y no deja filas", function () use ($status): void {
        CursoTestHelpers::setStatus($this->tenant, $this->year, $status);
        app(TenantContext::class)->enter($this->tenant->id);

        $year = $this->year->id;
        $tree = $this->tree;

        $inserts = [
            'education_levels' => fn () => DB::table('education_levels')->insert([
                'public_id' => (string) Str::ulid(), 'academic_year_id' => $year, 'code' => 'NUEVA',
                'name_translations' => json_encode(['es-ES' => 'N']), 'stage' => 'eso', 'regime' => 'publico', 'position' => 2,
            ]),
            'courses' => fn () => DB::table('courses')->insert([
                'public_id' => (string) Str::ulid(), 'academic_year_id' => $year, 'education_level_id' => $tree['level']->id,
                'code' => 'NUEVA', 'name_translations' => json_encode(['es-ES' => 'N']), 'position' => 2,
            ]),
            'groups' => fn () => DB::table('groups')->insert([
                'public_id' => (string) Str::ulid(), 'academic_year_id' => $year, 'course_id' => $tree['course']->id,
                'code' => 'NUEVA', 'name' => 'N', 'position' => 2,
            ]),
            'subgroups' => fn () => DB::table('subgroups')->insert([
                'public_id' => (string) Str::ulid(), 'academic_year_id' => $year, 'group_id' => $tree['group']->id,
                'code' => 'NUEVA', 'name' => 'N', 'position' => 2,
            ]),
            'subjects' => fn () => DB::table('subjects')->insert([
                'public_id' => (string) Str::ulid(), 'academic_year_id' => $year, 'course_id' => $tree['course']->id,
                'code' => 'NUEVA', 'name_translations' => json_encode(['es-ES' => 'N']), 'position' => 2,
            ]),
        ];

        foreach ($inserts as $table => $write) {
            $counted = DB::table($table)->count();

            expect(AcadTestHelpers::sqlState($write))->toBe(AcademicYearClosedTranslator::SQLSTATE, $table)
                ->and(DB::table($table)->count())->toBe($counted, $table);
        }

        // La asignación ya existe (unicidad parcial), pero el disparador es BEFORE INSERT y salta antes que cualquier índice único.
        $state = AcadTestHelpers::sqlState(fn () => DB::table('group_subjects')->insert([
            'public_id' => (string) Str::ulid(), 'academic_year_id' => $year,
            'course_id' => $tree['course']->id, 'group_id' => $tree['group']->id, 'subject_id' => $tree['subject']->id,
        ]));

        expect($state)->toBe(AcademicYearClosedTranslator::SQLSTATE);
    });
}

foreach ([AcademicYearStatus::Activo, AcademicYearStatus::Planificacion] as $status) {
    test("CA-ACAD-020 RN-CURSO-20: en un curso {$status->value} se admiten la modificación y el borrado de las seis entidades", function () use ($status): void {
        if ($status !== AcademicYearStatus::Activo) {
            CursoTestHelpers::setStatus($this->tenant, $this->year, AcademicYearStatus::Planificacion);
        }

        app(TenantContext::class)->enter($this->tenant->id);

        foreach (acadEntities() as $key => [$table, $class]) {
            $model = $class::query()->findOrFail($this->tree[$key]->id);
            acadChange($key, $model);
            $model->save();
        }

        // Borrado en orden de dependencia (hijos primero): la FK es NO ACTION y el borrado es lógico.
        foreach (array_reverse(array_keys(acadEntities())) as $key) {
            $class = acadEntities()[$key][1];
            $class::query()->findOrFail($this->tree[$key]->id)->delete();
        }

        expect(DB::table('education_levels')->where('id', $this->tree['level']->id)->value('deleted_at'))->not->toBeNull();
    });
}

test('CA-ACAD-020 ADR-057: el error de YC001 de una tabla de acad lo reconoce el traductor de Curso y lleva el public_id del curso', function (): void {
    CursoTestHelpers::setStatus($this->tenant, $this->year, AcademicYearStatus::Cerrado);
    app(TenantContext::class)->enter($this->tenant->id);

    try {
        DB::transaction(fn () => Subject::query()->findOrFail($this->tree['subject']->id)->delete());
        $caught = null;
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and(AcademicYearClosedTranslator::isGuardViolation($caught))->toBeTrue()
        ->and($caught->getMessage())->toContain("academic_year_closed:{$this->year->public_id}");
});
