<?php

use App\Models\AuditLog;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadTestHelpers;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11-b), INV-003, ADR-035, datos.md §4, CA-ACAD-001 (entrada
// `created`), CA-ACAD-013 (desglose por idioma), CA-ACAD-017 (entradas por
// instancia de la asignación) y CA-ACAD-008 (entrada `deleted`), a nivel de
// modelo. Los endpoints llegan en el lote 2.

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->year = CursoTestHelpers::year($this->tenant, '2026-2027', AcademicYearStatus::Activo);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('datos.md §4 AR-06: los seis modelos están en el morph map con su alias y son Auditable con política Full', function (): void {
    $expected = [
        'education_level' => EducationLevel::class,
        'course' => Course::class,
        'group' => Group::class,
        'subgroup' => Subgroup::class,
        'subject' => Subject::class,
        'group_subject' => GroupSubject::class,
    ];

    foreach ($expected as $alias => $class) {
        expect(Relation::getMorphedModel($alias))->toBe($class)
            ->and((new $class)->auditValuePolicy())->toBe(AuditValuePolicy::Full)
            ->and((new $class)->getMorphClass())->toBe($alias);
    }
});

test('CA-ACAD-001 CA-ACAD-008 INV-003: crear y borrar cada entidad deja su entrada created y deleted con el alias del morph map', function (): void {
    $tree = AcadTestHelpers::tree($this->tenant, $this->year);

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($tree): void {
        $aliases = ['level' => 'education_level', 'course' => 'course', 'group' => 'group', 'subgroup' => 'subgroup', 'subject' => 'subject', 'groupSubject' => 'group_subject'];

        foreach ($aliases as $key => $alias) {
            $created = AuditLog::query()->where('auditable_type', $alias)->where('auditable_id', $tree[$key]->id)->where('event', 'created')->count();

            expect($created)->toBe(1, "{$alias} sin entrada created");
        }

        // Hijos primero, como exigirá RN-ACAD-20 en el servicio.
        foreach (array_reverse(array_keys($aliases)) as $key) {
            $tree[$key]->delete();
        }

        foreach ($aliases as $key => $alias) {
            $deleted = AuditLog::query()->where('auditable_type', $alias)->where('auditable_id', $tree[$key]->id)->where('event', 'deleted')->count();

            expect($deleted)->toBe(1, "{$alias} sin entrada deleted");
        }
    });
});

test('CA-ACAD-013 ADR-062 §5.5: un cambio real de es-ES y un fr nuevo en el nombre de una asignatura dejan exactamente name_translations.es-ES y name_translations.fr', function (): void {
    $tree = AcadTestHelpers::tree($this->tenant, $this->year);

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($tree): void {
        $subject = $tree['subject'];
        $subject->update(['name_translations' => ['es-ES' => 'Matemáticas I', 'fr' => 'Mathématiques']]);

        $updated = AuditLog::query()->where('auditable_type', 'subject')->where('auditable_id', $subject->id)->where('event', 'updated')->firstOrFail();

        expect(array_values(array_diff(array_keys($updated->changes), ['updated_at', 'updated_by'])))->toEqualCanonicalizing(['name_translations.es-ES', 'name_translations.fr'])
            ->and($updated->changes['name_translations.es-ES'])->toEqual(['from' => 'Matemáticas', 'to' => 'Matemáticas I'])
            ->and($updated->changes['name_translations.fr'])->toEqual(['from' => null, 'to' => 'Mathématiques']);
    });
});

test('datos.md §4 ADR-035 §8: Full registra el código y el nombre simple de un grupo en created', function (): void {
    $tree = AcadTestHelpers::tree($this->tenant, $this->year);

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($tree): void {
        $created = AuditLog::query()->where('auditable_type', 'group')->where('auditable_id', $tree['group']->id)->where('event', 'created')->firstOrFail();

        expect($created->changes)->toHaveKeys(['code', 'name', 'position'])
            ->and($created->changes['name'])->toEqual(['from' => null, 'to' => 'Grupo A']);
    });
});
