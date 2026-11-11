<?php

use App\Modules\Alum\Application\EnrollmentService;
use App\Modules\Alum\Application\StudentService;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Modules\Curso\Domain\AcademicYearReadDeniedException;
use App\Modules\Curso\Domain\AcademicYearReadOnlyException;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AlumEnrollmentHelpers as En;
use Tests\Support\AlumTestHelpers as Alum;

// REQ-ALUM-002 (1.15-b T2a), funcional.md §4.2, §4.3, §5.2 (RN-ALUM-20 a -29), §11.3: `EnrollmentService` con base de
// datos real, a nivel de servicio (los endpoints son T2b). INV-001, INV-003, INV-010, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** La excepción de API que lanza `$fn` (el test falla si no lanza ninguna). */
function enrollThrown(Closure $fn): ApiException
{
    try {
        $fn();
    } catch (ApiException $e) {
        return $e;
    }

    throw new RuntimeException('Se esperaba una ApiException.');
}

function enrollCode(ApiException $e, string $field): ?string
{
    return $e->errors[$field][0]['code'] ?? null;
}

function enrollSvc(): EnrollmentService
{
    return app(EnrollmentService::class);
}

test('CA-ALUM-020 RN-ALUM-20 RN-ALUM-21 RN-ALUM-27 INV-003: matricular crea la fila activa con su auditoría; la repetición exacta es 422 already_enrolled sin segunda fila', function (): void {
    $s = En::scenario('enr-020');
    $body = En::body($s);

    $enrollment = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], $body));

    expect($enrollment->public_id)->toHaveLength(26)
        ->and($enrollment->status)->toBe('activa')
        ->and($enrollment->group_id)->not->toBeNull()
        ->and(En::liveCount($s['tenant']))->toBe(1)
        ->and(Alum::auditCount($s['tenant'], 'alum_enrollment', 'created'))->toBe(1);

    $again = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($s['admin'], $body)));

    expect($again->status)->toBe(422)
        ->and(enrollCode($again, 'student'))->toBe('alum.validation.already_enrolled')
        ->and(En::liveCount($s['tenant']))->toBe(1);
});

test('CA-ALUM-021 RN-ALUM-20: un grupo de otro curso de etapa es group_not_in_course; un curso de etapa de otro curso académico, course_not_in_academic_year', function (): void {
    $s = En::scenario('enr-021');
    $plan = En::planningYear($s);

    $otherCourseGroup = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($s['admin'], En::body($s, 0, ['group' => $s['group2']]))));
    $otherYearCourse = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($s['admin'], En::body($s, 0, ['course' => $plan['api']['course'], 'group' => null]))));
    $groupOfOtherYear = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($s['admin'], En::body($s, 0, ['group' => $plan['api']['group']]))));

    expect(enrollCode($otherCourseGroup, 'group'))->toBe('alum.validation.group_not_in_course')
        ->and(enrollCode($otherYearCourse, 'course'))->toBe('alum.validation.course_not_in_academic_year')
        ->and(enrollCode($groupOfOtherYear, 'group'))->toBe('alum.validation.group_not_in_course')
        ->and(En::liveCount($s['tenant']))->toBe(0);
});

test('CA-ALUM-022 RN-ALUM-21: una carrera que ya insertó la matrícula activa del alumno se traduce a 422 already_enrolled, nunca 500', function (): void {
    $s = En::scenario('enr-022');

    // Simula la otra alta simultánea: justo antes del INSERT propio entra la del competidor (índice único parcial).
    $armed = true;
    Enrollment::creating(function (Enrollment $e) use (&$armed): void {
        if (! $armed) {
            return;
        }

        $armed = false;
        $rival = new Enrollment(['student_id' => $e->student_id, 'academic_year_id' => $e->academic_year_id, 'course_id' => $e->course_id, 'status' => 'activa', 'enrolled_on' => '2026-09-11']);
        $rival->save();
    });

    $e = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($s['admin'], En::body($s))));
    Enrollment::flushEventListeners();

    expect($e->status)->toBe(422)
        ->and(enrollCode($e, 'student'))->toBe('alum.validation.already_enrolled')
        // La del competidor se simuló dentro de la misma transacción, que se deshace entera: no queda ninguna.
        ->and(En::liveCount($s['tenant']))->toBe(0);
});

test('CA-ALUM-023 RN-ALUM-22: tras una baja por traslado se puede matricular de nuevo en el mismo curso y la anterior se conserva', function (): void {
    $s = En::scenario('enr-023');

    $first = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s)));
    En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->withdraw($s['admin'], $first->public_id, ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'traslado']));
    $second = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s, 0, ['enrolled_on' => '2026-10-02'])));

    expect($second->status)->toBe('activa')
        ->and(En::liveCount($s['tenant']))->toBe(2)
        ->and(Alum::row($s['tenant'], 'enrollments', $first->public_id)->status)->toBe('baja');
});

test('CA-ALUM-024 RN-ALUM-22: una baja sin fecha, con fecha anterior a la matrícula o con motivo fuera del vocabulario es 422 y no cambia nada', function (): void {
    $s = En::scenario('enr-024');
    $e = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s)));
    $withdraw = fn (array $b) => En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->withdraw($s['admin'], $e->public_id, $b)));

    expect(enrollCode($withdraw(['withdrawal_reason' => 'otro']), 'withdrawn_on'))->toBe('alum.validation.required')
        ->and(enrollCode($withdraw(['withdrawn_on' => '2026-13-40', 'withdrawal_reason' => 'otro']), 'withdrawn_on'))->toBe('alum.validation.date_invalid')
        ->and(enrollCode($withdraw(['withdrawn_on' => '2026-09-09', 'withdrawal_reason' => 'otro']), 'withdrawn_on'))->toBe('alum.validation.withdrawn_before_enrolled')
        ->and(enrollCode($withdraw(['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'vacaciones']), 'withdrawal_reason'))->toBe('alum.validation.withdrawal_reason_invalid')
        ->and(Alum::row($s['tenant'], 'enrollments', $e->public_id)->status)->toBe('activa');

    // Día de la matrícula: borde incluido.
    $done = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->withdraw($s['admin'], $e->public_id, ['withdrawn_on' => '2026-09-10', 'withdrawal_reason' => 'baja_voluntaria']));
    expect($done->status)->toBe('baja')->and($done->withdrawal_reason)->toBe('baja_voluntaria');
});

test('CA-ALUM-025 RN-ALUM-23 ADR-057: matricular, editar, dar de baja y anular en un curso cerrado lanza la excepción que curso traduce a 409 academic-year-closed', function (): void {
    $s = En::scenario('enr-025');
    $e = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s)));
    En::closeYear($s, $s['year']);
    $thrown = fn (Closure $fn) => En::as($s['tenant'], $s['admin'], function () use ($fn): string {
        try {
            $fn();
        } catch (AcademicYearReadOnlyException $x) {
            return $x->getMessage();
        }

        return 'sin excepción';
    });

    $expected = 'academic_year_closed:'.$s['year']->public_id;

    expect($thrown(fn () => enrollSvc()->create($s['admin'], En::body($s, 1))))->toBe($expected)
        ->and($thrown(fn () => enrollSvc()->update($s['admin'], $e->public_id, ['enrolled_on' => '2026-09-11'])))->toBe($expected)
        ->and($thrown(fn () => enrollSvc()->withdraw($s['admin'], $e->public_id, ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'otro'])))->toBe($expected)
        ->and($thrown(fn () => enrollSvc()->annul($s['admin'], $e->public_id)))->toBe($expected)
        ->and(Alum::row($s['tenant'], 'enrollments', $e->public_id)->deleted_at)->toBeNull();
});

test('CA-ALUM-026 RN-ALUM-23 RN-CURSO-25: sin curso_historico.leer, listado y detalle de un curso cerrado lanzan la denegación que curso traduce a 404; con él, los datos', function (): void {
    $s = En::scenario('enr-026');
    $e = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s)));
    En::closeYear($s, $s['year']);
    $reader = Alum::userWith($s['tenant'], $s['admin'], 'lector', ['matricula.leer']);
    $historian = Alum::userWith($s['tenant'], $s['admin'], 'historico', ['matricula.leer', 'curso_historico.leer']);
    $filters = ['academic_year' => $s['year']->public_id];

    $denied = fn (Closure $fn) => En::as($s['tenant'], $reader, function () use ($fn): bool {
        try {
            $fn();
        } catch (AcademicYearReadDeniedException) {
            return true;
        }

        return false;
    });
    $decide = fn ($user) => app(PermissionResolver::class)->decide($user, 'matricula.leer');

    expect($denied(fn () => enrollSvc()->page($decide($reader), $reader, $filters, 'name', 1, 25)))->toBeTrue()
        ->and($denied(fn () => enrollSvc()->findScoped($e->public_id, $decide($reader), $reader)))->toBeTrue();

    $page = En::as($s['tenant'], $historian, fn () => enrollSvc()->page($decide($historian), $historian, $filters, 'name', 1, 25));
    $one = En::as($s['tenant'], $historian, fn () => enrollSvc()->findScoped($e->public_id, $decide($historian), $historian));

    expect($page->total())->toBe(1)->and($one->public_id)->toBe($e->public_id);
});

test('CA-ALUM-028 CA-ALUM-035 RN-ALUM-28: PATCH asigna un primer grupo y cambia la fecha; cambiar un grupo ya asignado es group_change_requires_request; alumno, curso académico y curso de etapa son inmutables', function (): void {
    $s = En::scenario('enr-028');
    $noGroup = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s, 0, ['group' => null])));
    $withGroup = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s, 1)));

    expect($noGroup->group_id)->toBeNull();

    $assigned = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->update($s['admin'], $noGroup->public_id, ['group' => $s['api']['group'], 'enrolled_on' => '2026-09-12']));
    $change = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->update($s['admin'], $withGroup->public_id, ['group' => $s['groupB']])));
    $clear = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->update($s['admin'], $withGroup->public_id, ['group' => null])));
    $same = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->update($s['admin'], $withGroup->public_id, ['group' => $s['api']['group']]));

    expect($assigned->group_id)->not->toBeNull()
        ->and($assigned->enrolled_on->toDateString())->toBe('2026-09-12')
        ->and(enrollCode($change, 'group'))->toBe('alum.validation.group_change_requires_request')
        ->and(enrollCode($clear, 'group'))->toBe('alum.validation.group_change_requires_request')
        ->and($same->group_id)->toBe($withGroup->group_id);

    foreach (['student' => $s['students'][2]['public_id'], 'academic_year' => $s['year']->public_id, 'course' => $s['course2']] as $key => $value) {
        $e = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->update($s['admin'], $noGroup->public_id, [$key => $value])));
        expect(enrollCode($e, $key))->toBe('alum.validation.immutable');
    }

    $none = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->update($s['admin'], $noGroup->public_id, [])));
    expect($none->status)->toBe(422);

    // Un grupo de otro curso de etapa al asignar el primero.
    $other = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->update($s['admin'], Alum::row($s['tenant'], 'enrollments', $noGroup->public_id)->public_id, ['group' => $s['group2']])));
    expect(enrollCode($other, 'group'))->toBe('alum.validation.group_not_in_course');
});

test('CA-ALUM-029 RN-ALUM-29: la fecha de matrícula debe caer dentro del curso académico, bordes incluidos; también al editar', function (): void {
    $s = En::scenario('enr-029');
    $try = fn (int $student, string $date) => En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($s['admin'], En::body($s, $student, ['enrolled_on' => $date]))));

    expect(enrollCode($try(0, '2026-08-31'), 'enrolled_on'))->toBe('alum.validation.date_outside_academic_year')
        ->and(enrollCode($try(0, '2027-07-01'), 'enrolled_on'))->toBe('alum.validation.date_outside_academic_year')
        ->and(enrollCode($try(0, '2026-02-30'), 'enrolled_on'))->toBe('alum.validation.date_invalid')
        ->and(En::liveCount($s['tenant']))->toBe(0);

    $first = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s, 0, ['enrolled_on' => '2026-09-01'])));
    $last = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s, 1, ['enrolled_on' => '2027-06-30'])));
    $edit = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->update($s['admin'], $first->public_id, ['enrolled_on' => '2027-07-01'])));

    expect($first->enrolled_on->toDateString())->toBe('2026-09-01')
        ->and($last->enrolled_on->toDateString())->toBe('2027-06-30')
        ->and(enrollCode($edit, 'enrolled_on'))->toBe('alum.validation.date_outside_academic_year');
});

test('CA-ALUM-030 funcional.md §6: matricular a un alumno dado de baja (o inexistente) es 422 student_not_found y no deja fila', function (): void {
    $s = En::scenario('enr-030');
    Alum::call($s['tenant'], $s['admin'], 'delete', "/students/{$s['students'][0]['public_id']}")->assertNoContent();

    $gone = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($s['admin'], En::body($s))));
    $missing = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($s['admin'], En::body($s, 1, ['student' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))));

    expect(enrollCode($gone, 'student'))->toBe('alum.validation.student_not_found')
        ->and($gone->errors)->toBe($missing->errors)
        ->and(En::liveCount($s['tenant']))->toBe(0);
});

test('CA-ALUM-031 RN-ALUM-25: una matrícula anulada deja de aparecer en el listado y el alumno puede matricularse de nuevo en ese curso', function (): void {
    $s = En::scenario('enr-031');
    $decide = fn () => app(PermissionResolver::class)->decide($s['admin'], 'matricula.leer');
    $filters = ['academic_year' => $s['year']->public_id];
    $e = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s)));

    En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->annul($s['admin'], $e->public_id));

    $list = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->page($decide(), $s['admin'], $filters, 'name', 1, 25));
    $gone = En::as($s['tenant'], $s['admin'], fn () => enrollThrown404(fn () => enrollSvc()->findScoped($e->public_id, $decide(), $s['admin'])));
    $again = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s)));

    expect($list->total())->toBe(0)
        ->and($gone)->toBeTrue()
        ->and($again->status)->toBe('activa')
        ->and(Alum::auditCount($s['tenant'], 'alum_enrollment', 'deleted'))->toBe(1);
});

function enrollThrown404(Closure $fn): bool
{
    try {
        $fn();
    } catch (ModelNotFoundException) {
        return true;
    } catch (ApiException $e) {
        return $e->status === 404;
    }

    return false;
}

test('CA-ALUM-033 OPEN-ALUM-11: en planificación y sin grupos se matricula sin group (group null); aparece en el listado por curso de etapa y en ninguna lista de clase', function (): void {
    $s = En::scenario('enr-033');
    $plan = En::planningYear($s);
    $decide = fn () => app(PermissionResolver::class)->decide($s['admin'], 'matricula.leer');

    $e = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], [
        'student' => $s['students'][0]['public_id'], 'academic_year' => $plan['year']->public_id,
        'course' => $plan['api']['course'], 'enrolled_on' => '2027-09-01',
    ]));

    $byCourse = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->page($decide(), $s['admin'], ['academic_year' => $plan['year']->public_id, 'course' => $plan['api']['course']], 'name', 1, 25));
    $byGroup = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->page($decide(), $s['admin'], ['academic_year' => $plan['year']->public_id, 'group' => $plan['api']['group']], 'name', 1, 25));

    expect($e->group_id)->toBeNull()
        ->and($byCourse->total())->toBe(1)
        ->and($byGroup->total())->toBe(0);
});

test('CA-ALUM-034 RN-ALUM-22 RN-ALUM-27: otra baja sobre una matrícula en baja (también como reintento) es 409 already_withdrawn y nada cambia', function (): void {
    $s = En::scenario('enr-034');
    $e = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s)));
    $body = ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'traslado'];
    En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->withdraw($s['admin'], $e->public_id, $body));

    $retry = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->withdraw($s['admin'], $e->public_id, $body)));
    $other = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => enrollSvc()->withdraw($s['admin'], $e->public_id, ['withdrawn_on' => '2026-11-01', 'withdrawal_reason' => 'otro'])));
    $row = Alum::row($s['tenant'], 'enrollments', $e->public_id);

    expect($retry->status)->toBe(409)
        ->and(enrollCode($retry, 'enrollment'))->toBe('alum.conflict.already_withdrawn')
        ->and($other->status)->toBe(409)
        ->and($row->withdrawn_on)->toBe('2026-10-01')
        ->and($row->withdrawal_reason)->toBe('traslado');
});

test('CA-ALUM-036 RN-ALUM-24: curso de etapa o grupo inexistente, de otro centro o de un curso cerrado ilegible dan el mismo 422 parent_not_found', function (): void {
    $s = En::scenario('enr-036');
    $other = En::scenario('enr-036b');
    $plan = En::planningYear($s);
    En::closeYear($s, $s['year']);
    $reader = Alum::userWith($s['tenant'], $s['admin'], 'crea', ['matricula.crear']);
    $closedBody = ['student' => $s['students'][0]['public_id'], 'academic_year' => $plan['year']->public_id, 'enrolled_on' => '2027-09-10'];
    $try = fn (array $over) => En::as($s['tenant'], $reader, fn () => enrollThrown(fn () => enrollSvc()->create($reader, $over + $closedBody)));

    $courses = [
        $try(['course' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']),
        $try(['course' => $other['api']['course']]),
        $try(['course' => $s['api']['course']]),
    ];
    $groups = [
        $try(['course' => $plan['api']['course'], 'group' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']),
        $try(['course' => $plan['api']['course'], 'group' => $other['api']['group']]),
        $try(['course' => $plan['api']['course'], 'group' => $s['api']['group']]),
    ];

    foreach ($courses as $e) {
        expect(enrollCode($e, 'course'))->toBe('alum.validation.parent_not_found')->and($e->errors)->toBe($courses[0]->errors);
    }

    foreach ($groups as $e) {
        expect(enrollCode($e, 'group'))->toBe('alum.validation.parent_not_found')->and($e->errors)->toBe($groups[0]->errors);
    }

    // Y el curso académico ilegible o inexistente del cuerpo: academic_year_not_found, indistinguible.
    $years = [$try(['academic_year' => $s['year']->public_id, 'course' => $s['api']['course']]), $try(['academic_year' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'course' => $s['api']['course']])];
    expect(enrollCode($years[0], 'academic_year'))->toBe('alum.validation.academic_year_not_found')->and($years[0]->errors)->toBe($years[1]->errors);
});

test('CA-ALUM-004 RN-ALUM-05: un alumno con matrícula activa no se da de baja (409 has_active_enrollments); con la matrícula en baja o anulada, sí', function (): void {
    $s = En::scenario('enr-004');
    $e = En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->create($s['admin'], En::body($s)));
    $publicId = $s['students'][0]['public_id'];

    $blocked = En::as($s['tenant'], $s['admin'], fn () => enrollThrown(fn () => app(StudentService::class)->delete($publicId)));
    expect($blocked->status)->toBe(409)->and(enrollCode($blocked, 'student'))->toBe('alum.conflict.has_active_enrollments');
    Alum::call($s['tenant'], $s['admin'], 'delete', "/students/{$publicId}")->assertConflict();

    En::as($s['tenant'], $s['admin'], fn () => enrollSvc()->withdraw($s['admin'], $e->public_id, ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'traslado']));
    Alum::call($s['tenant'], $s['admin'], 'delete', "/students/{$publicId}")->assertNoContent();
});

test('CA-ALUM-018b INV-001: un centro no ve, no cuenta ni escribe matrículas, alumnos, cursos ni grupos del otro', function (): void {
    $a = En::scenario('enr-018a');
    $b = En::scenario('enr-018b');
    $eA = En::as($a['tenant'], $a['admin'], fn () => enrollSvc()->create($a['admin'], En::body($a)));
    $decide = fn () => app(PermissionResolver::class)->decide($b['admin'], 'matricula.leer');

    $list = En::as($b['tenant'], $b['admin'], fn () => enrollSvc()->page($decide(), $b['admin'], ['academic_year' => $b['year']->public_id], 'name', 1, 25));
    $find = En::as($b['tenant'], $b['admin'], fn () => enrollThrown404(fn () => enrollSvc()->find($eA->public_id)));
    $scoped = En::as($b['tenant'], $b['admin'], fn () => enrollThrown404(fn () => enrollSvc()->findScoped($eA->public_id, $decide(), $b['admin'])));
    $annul = En::as($b['tenant'], $b['admin'], fn () => enrollThrown404(fn () => enrollSvc()->annul($b['admin'], $eA->public_id)));
    $crossStudent = En::as($b['tenant'], $b['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($b['admin'], En::body($b, 0, ['student' => $a['students'][0]['public_id']]))));
    $crossYear = En::as($b['tenant'], $b['admin'], fn () => enrollThrown(fn () => enrollSvc()->create($b['admin'], En::body($b, 0, ['academic_year' => $a['year']->public_id]))));
    $yearList = En::as($b['tenant'], $b['admin'], fn () => enrollThrown404(fn () => enrollSvc()->page($decide(), $b['admin'], ['academic_year' => $a['year']->public_id], 'name', 1, 25)));

    expect($list->total())->toBe(0)
        ->and($find)->toBeTrue()->and($scoped)->toBeTrue()->and($annul)->toBeTrue()
        ->and(enrollCode($crossStudent, 'student'))->toBe('alum.validation.student_not_found')
        ->and(enrollCode($crossYear, 'academic_year'))->toBe('alum.validation.academic_year_not_found')
        ->and($yearList)->toBeTrue()
        ->and(En::liveCount($a['tenant']))->toBe(1)
        ->and(En::liveCount($b['tenant']))->toBe(0);
});
