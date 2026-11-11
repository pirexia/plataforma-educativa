<?php

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\AlumEnrollmentHelpers as En;
use Tests\Support\AlumTestHelpers as Alum;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-ALUM-002 (1.15-b T2b), docs/modulos/REQ-ALUM/api.md §1 y §2, funcional.md §4.2, §5.2 y §11.3: matrícula por la API.
// CA-ALUM-016, -018b, -020 a -026, -028 a -031, -033 a -036; RN-ALUM-20 a -29, -60, -62, -64; INV-001, INV-002, INV-003,
// INV-010, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** `POST /enrollments`. */
function apiEnroll(array $s, array $body, ?User $as = null): TestResponse
{
    return Alum::call($s['tenant'], $as ?? $s['admin'], 'post', '/enrollments', $body);
}

/** Cuerpo de alta del alumno `$student` (por defecto el 0). */
function apiBody(array $s, int $student = 0, array $overrides = []): array
{
    return En::body($s, $student, $overrides);
}

/** Alta ok; devuelve el `public_id` de la matrícula. */
function apiEnrolled(array $s, int $student = 0, array $overrides = []): string
{
    return apiEnroll($s, apiBody($s, $student, $overrides))->assertCreated()->json('public_id');
}

/** @return list<string> `public_id` de la respuesta de un listado */
function apiIds(TestResponse $r): array
{
    return array_column($r->assertOk()->json('data'), 'public_id');
}

function apiList(array $s, string $query, ?User $as = null): TestResponse
{
    return Alum::call($s['tenant'], $as ?? $s['admin'], 'get', "/enrollments?academic_year={$s['year']->public_id}".$query);
}

test('CA-ALUM-020 RN-ALUM-20 RN-ALUM-21 RN-ALUM-27 INV-003: POST /enrollments es 201 con Location y la matrícula completa; la repetición exacta es 422 already_enrolled sin segunda fila', function (): void {
    $s = En::scenario('enrapi-020');

    $r = apiEnroll($s, apiBody($s))->assertCreated();

    expect($r->json())->toHaveKeys(['public_id', 'student', 'academic_year', 'course', 'group', 'status', 'enrolled_on', 'withdrawn_on', 'withdrawal_reason', 'created_at', 'updated_at'])
        ->and($r->json('status'))->toBe('activa')
        ->and($r->json('enrolled_on'))->toBe('2026-09-10')
        ->and($r->json('withdrawn_on'))->toBeNull()
        ->and($r->json('student.public_id'))->toBe($s['students'][0]['public_id'])
        ->and($r->json('student.display_name'))->toBe('Alumno Uno')
        ->and($r->json('academic_year.public_id'))->toBe($s['year']->public_id)
        ->and($r->json('course.public_id'))->toBe($s['api']['course'])
        ->and($r->json('course.code'))->toBe('1ESO')
        ->and($r->json('group.public_id'))->toBe($s['api']['group'])
        ->and($r->json('group.code'))->toBe('A')
        ->and($r->json('group.name'))->toBe('Grupo A')
        ->and($r->headers->get('Location'))->toContain('/api/v1/enrollments/'.$r->json('public_id'))
        ->and(Alum::auditCount($s['tenant'], 'alum_enrollment', 'created'))->toBe(1);

    $again = apiEnroll($s, apiBody($s))->assertUnprocessable();

    expect(Alum::errorCode($again, 'student'))->toBe('alum.validation.already_enrolled')
        ->and(En::liveCount($s['tenant']))->toBe(1);
});

test('CA-ALUM-021 CA-ALUM-036 RN-ALUM-20 RN-ALUM-24: grupo de otro curso de etapa o académico, y un curso o grupo inexistente son 422 con su código; parent_not_found tiene el mismo cuerpo en los tres casos', function (): void {
    $s = En::scenario('enrapi-021');
    $plan = En::planningYear($s);

    expect(Alum::errorCode(apiEnroll($s, apiBody($s, 0, ['group' => $s['group2']]))->assertUnprocessable(), 'group'))->toBe('alum.validation.group_not_in_course')
        ->and(Alum::errorCode(apiEnroll($s, apiBody($s, 0, ['course' => $plan['api']['course'], 'group' => null]))->assertUnprocessable(), 'course'))->toBe('alum.validation.course_not_in_academic_year');

    // Tres formas de «no existe para ti»: curso inexistente, grupo inexistente, curso de un centro ajeno.
    $other = En::scenario('enrapi-021b');
    $bodies = [
        apiEnroll($s, apiBody($s, 0, ['course' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))->assertUnprocessable()->json('errors.course'),
        apiEnroll($s, apiBody($s, 0, ['course' => $other['api']['course']]))->assertUnprocessable()->json('errors.course'),
    ];

    expect(Alum::errorCode(apiEnroll($s, apiBody($s, 0, ['group' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))->assertUnprocessable(), 'group'))->toBe('alum.validation.parent_not_found')
        ->and($bodies[0][0]['code'])->toBe('alum.validation.parent_not_found')
        ->and($bodies[1])->toBe($bodies[0])
        ->and(En::liveCount($s['tenant']))->toBe(0);
});

test('CA-ALUM-022 CA-ALUM-023 RN-ALUM-21 RN-ALUM-22: una segunda matrícula activa en el curso es 422; tras una baja por traslado, 201 y la anterior se conserva', function (): void {
    $s = En::scenario('enrapi-023');
    $first = apiEnrolled($s);

    Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', apiBody($s, 0, ['enrolled_on' => '2026-09-11']))->assertUnprocessable();
    Alum::call($s['tenant'], $s['admin'], 'post', "/enrollments/{$first}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'traslado'])->assertOk();

    $second = apiEnroll($s, apiBody($s, 0, ['enrolled_on' => '2026-10-02']))->assertCreated()->json('public_id');

    expect(Alum::row($s['tenant'], 'enrollments', $first)->status)->toBe('baja')
        ->and(Alum::row($s['tenant'], 'enrollments', $second)->status)->toBe('activa')
        ->and(En::liveCount($s['tenant']))->toBe(2);
});

test('CA-ALUM-024 CA-ALUM-034 RN-ALUM-22 RN-ALUM-27 INV-003: la baja exige fecha válida, no anterior a la matrícula y motivo del vocabulario (422); válida es 200 y su reintento es 409 already_withdrawn sin cambios', function (): void {
    $s = En::scenario('enrapi-024');
    $id = apiEnrolled($s);
    $withdraw = fn (array $b) => Alum::call($s['tenant'], $s['admin'], 'post', "/enrollments/{$id}/withdrawal", $b);

    expect(Alum::errorCode($withdraw(['withdrawal_reason' => 'otro'])->assertUnprocessable(), 'withdrawn_on'))->toBe('alum.validation.required')
        ->and(Alum::errorCode($withdraw(['withdrawn_on' => '2026-02-30', 'withdrawal_reason' => 'otro'])->assertUnprocessable(), 'withdrawn_on'))->toBe('alum.validation.date_invalid')
        ->and(Alum::errorCode($withdraw(['withdrawn_on' => '2026-09-09', 'withdrawal_reason' => 'otro'])->assertUnprocessable(), 'withdrawn_on'))->toBe('alum.validation.withdrawn_before_enrolled')
        ->and(Alum::errorCode($withdraw(['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'vacaciones'])->assertUnprocessable(), 'withdrawal_reason'))->toBe('alum.validation.withdrawal_reason_invalid')
        ->and(Alum::row($s['tenant'], 'enrollments', $id)->status)->toBe('activa');

    $done = $withdraw(['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'baja_voluntaria'])->assertOk();

    expect($done->json('status'))->toBe('baja')
        ->and($done->json('withdrawn_on'))->toBe('2026-10-01')
        ->and($done->json('withdrawal_reason'))->toBe('baja_voluntaria')
        ->and(Alum::auditCount($s['tenant'], 'alum_enrollment', 'updated'))->toBe(1);

    $retry = $withdraw(['withdrawn_on' => '2026-10-05', 'withdrawal_reason' => 'otro'])->assertConflict();

    expect(Alum::errorCode($retry, 'enrollment'))->toBe('alum.conflict.already_withdrawn')
        ->and(Alum::row($s['tenant'], 'enrollments', $id)->withdrawn_on)->toBe('2026-10-01');
});

test('CA-ALUM-025 RN-ALUM-23: matricular, editar, dar de baja y anular en un curso cerrado es 409 academic-year-closed y no cambia nada', function (): void {
    $s = En::scenario('enrapi-025');
    $id = apiEnrolled($s);
    En::closeYear($s, $s['year']);
    $t = $s['tenant'];

    foreach ([
        ['post', '/enrollments', apiBody($s, 1)],
        ['patch', "/enrollments/{$id}", ['enrolled_on' => '2026-09-11']],
        ['post', "/enrollments/{$id}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'otro']],
        ['delete', "/enrollments/{$id}", []],
    ] as [$method, $path, $body]) {
        Alum::call($t, $s['admin'], $method, $path, $body)->assertConflict()->assertJsonPath('type', 'urn:pge:error:academic-year-closed');
    }

    $row = Alum::row($t, 'enrollments', $id);

    expect($row->deleted_at)->toBeNull()->and($row->status)->toBe('activa')->and(En::liveCount($t))->toBe(1);
});

test('CA-ALUM-026 RN-ALUM-23 RN-CURSO-33: sin curso_historico.leer, listado y detalle de un curso cerrado, y GET /students de ese curso, son 404 y la ficha omite esas matrículas; con él, los datos', function (): void {
    $s = En::scenario('enrapi-026');
    $id = apiEnrolled($s);
    En::closeYear($s, $s['year']);
    $reader = Alum::userWith($s['tenant'], $s['admin'], 'lector', ['matricula.leer', 'alumno.leer']);
    $historian = Alum::userWith($s['tenant'], $s['admin'], 'historico', ['matricula.leer', 'alumno.leer', 'curso_historico.leer']);
    $student = $s['students'][0]['public_id'];
    $year = $s['year']->public_id;
    $call = fn (User $u, string $path) => Alum::call($s['tenant'], $u, 'get', $path);

    $call($reader, "/enrollments?academic_year={$year}")->assertNotFound();
    $call($reader, "/enrollments/{$id}")->assertNotFound();
    $call($reader, "/students?academic_year={$year}&enrollment_status=activa")->assertNotFound();
    expect($call($reader, "/students/{$student}")->assertOk()->json('enrollments'))->toBe([]);

    expect(apiIds($call($historian, "/enrollments?academic_year={$year}")))->toBe([$id]);
    $call($historian, "/enrollments/{$id}")->assertOk()->assertJsonPath('public_id', $id);
    expect(array_column($call($historian, "/students/{$student}")->assertOk()->json('enrollments'), 'public_id'))->toBe([$id])
        ->and(array_column($call($historian, "/students?academic_year={$year}&enrollment_status=activa")->assertOk()->json('data'), 'public_id'))->toBe([$student]);
});

test('CA-ALUM-028 CA-ALUM-029 CA-ALUM-035 RN-ALUM-28 RN-ALUM-29 INV-003: PATCH cambia la fecha y asigna un primer grupo; cambiar un grupo ya asignado, o alumno, curso académico o de etapa (aunque coincidan) es 422; 404 antes que cualquier 422', function (): void {
    $s = En::scenario('enrapi-028');
    $noGroup = apiEnrolled($s, 0, ['group' => null]);
    $withGroup = apiEnrolled($s, 1);
    $patch = fn (string $id, array $b) => Alum::call($s['tenant'], $s['admin'], 'patch', "/enrollments/{$id}", $b);

    $ok = $patch($noGroup, ['group' => $s['api']['group'], 'enrolled_on' => '2026-09-12'])->assertOk();

    expect($ok->json('group.public_id'))->toBe($s['api']['group'])
        ->and($ok->json('enrolled_on'))->toBe('2026-09-12')
        ->and(Alum::auditCount($s['tenant'], 'alum_enrollment', 'updated'))->toBe(1)
        ->and(Alum::errorCode($patch($withGroup, ['group' => $s['groupB']])->assertUnprocessable(), 'group'))->toBe('alum.validation.group_change_requires_request')
        ->and(Alum::errorCode($patch($noGroup, ['group' => $s['groupB']])->assertUnprocessable(), 'group'))->toBe('alum.validation.group_change_requires_request');

    foreach (['student' => $s['students'][0]['public_id'], 'academic_year' => $s['year']->public_id, 'course' => $s['api']['course']] as $key => $value) {
        expect(Alum::errorCode($patch($withGroup, [$key => $value])->assertUnprocessable(), $key))->toBe('alum.validation.immutable');
    }

    expect(Alum::errorCode($patch($withGroup, ['enrolled_on' => '2027-07-01'])->assertUnprocessable(), 'enrolled_on'))->toBe('alum.validation.date_outside_academic_year')
        ->and(Alum::errorCode($patch($withGroup, [])->assertUnprocessable(), 'enrolled_on'))->toBe('alum.validation.no_changes');

    // 404 antes que 422.
    $patch('01JZZZZZZZZZZZZZZZZZZZZZZZ', ['student' => 'x'])->assertNotFound();
});

test('CA-ALUM-029 RN-ALUM-29: la fecha de matrícula debe caer dentro del curso académico, bordes incluidos', function (): void {
    $s = En::scenario('enrapi-029');

    expect(Alum::errorCode(apiEnroll($s, apiBody($s, 0, ['enrolled_on' => '2026-08-31']))->assertUnprocessable(), 'enrolled_on'))->toBe('alum.validation.date_outside_academic_year')
        ->and(Alum::errorCode(apiEnroll($s, apiBody($s, 0, ['enrolled_on' => '2027-07-01']))->assertUnprocessable(), 'enrolled_on'))->toBe('alum.validation.date_outside_academic_year')
        ->and(Alum::errorCode(apiEnroll($s, apiBody($s, 0, ['enrolled_on' => '2026-02-30']))->assertUnprocessable(), 'enrolled_on'))->toBe('alum.validation.date_invalid');

    apiEnroll($s, apiBody($s, 0, ['enrolled_on' => '2026-09-01']))->assertCreated();
    apiEnroll($s, apiBody($s, 1, ['enrolled_on' => '2027-06-30']))->assertCreated();
});

test('CA-ALUM-030 funcional.md §4.2: matricular a un alumno dado de baja o inexistente es 422 student_not_found con el mismo cuerpo y sin fila', function (): void {
    $s = En::scenario('enrapi-030');
    Alum::call($s['tenant'], $s['admin'], 'delete', "/students/{$s['students'][0]['public_id']}")->assertNoContent();

    $gone = apiEnroll($s, apiBody($s))->assertUnprocessable();
    $missing = apiEnroll($s, apiBody($s, 1, ['student' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))->assertUnprocessable();

    expect(Alum::errorCode($gone, 'student'))->toBe('alum.validation.student_not_found')
        ->and($gone->json('errors'))->toBe($missing->json('errors'))
        ->and(En::liveCount($s['tenant']))->toBe(0);
});

test('CA-ALUM-031 RN-ALUM-25 RN-ALUM-05 INV-003: DELETE anula (204): desaparece del listado, del detalle y de la ficha, el alumno puede matricularse de nuevo y se audita deleted', function (): void {
    $s = En::scenario('enrapi-031');
    $student = $s['students'][0]['public_id'];
    $id = apiEnrolled($s);

    // Con matrícula activa, el alumno no se da de baja (RN-ALUM-05).
    Alum::call($s['tenant'], $s['admin'], 'delete', "/students/{$student}")->assertConflict();
    Alum::call($s['tenant'], $s['admin'], 'delete', "/enrollments/{$id}")->assertNoContent();

    expect(apiIds(apiList($s, '')))->toBe([])
        ->and(Alum::auditCount($s['tenant'], 'alum_enrollment', 'deleted'))->toBe(1)
        ->and(Alum::call($s['tenant'], $s['admin'], 'get', "/students/{$student}")->assertOk()->json('enrollments'))->toBe([]);

    Alum::call($s['tenant'], $s['admin'], 'get', "/enrollments/{$id}")->assertNotFound();
    Alum::call($s['tenant'], $s['admin'], 'delete', "/enrollments/{$id}")->assertNotFound();
    apiEnroll($s, apiBody($s))->assertCreated();
});

test('CA-ALUM-033 OPEN-ALUM-11: sin group la matrícula es 201 con group null; aparece por curso de etapa y no en ninguna lista de clase', function (): void {
    $s = En::scenario('enrapi-033');
    $plan = En::planningYear($s);
    $planBody = ['student' => $s['students'][0]['public_id'], 'academic_year' => $plan['year']->public_id, 'course' => $plan['api']['course'], 'enrolled_on' => '2027-09-05'];

    $r = apiEnroll($s, $planBody)->assertCreated();

    expect($r->json('group'))->toBeNull();

    $list = fn (string $query) => Alum::call($s['tenant'], $s['admin'], 'get', "/enrollments?academic_year={$plan['year']->public_id}".$query);

    expect(apiIds($list("&course={$plan['api']['course']}")))->toBe([$r->json('public_id')])
        ->and(apiIds($list("&group={$plan['api']['group']}")))->toBe([]);
});

test('api.md §2: el listado exige academic_year (422), filtra por curso, grupo, alumno y estado, ordena por nombre o fecha, pagina y valida sus parámetros', function (): void {
    $s = En::scenario('enrapi-list');
    $e0 = apiEnrolled($s, 0, ['enrolled_on' => '2026-09-12']);
    $e1 = apiEnrolled($s, 1, ['enrolled_on' => '2026-09-11']);
    $e2 = apiEnrolled($s, 2, ['group' => $s['groupB'], 'enrolled_on' => '2026-09-10']);
    Alum::call($s['tenant'], $s['admin'], 'post', "/enrollments/{$e1}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'otro'])->assertOk();

    expect(Alum::errorCode(Alum::call($s['tenant'], $s['admin'], 'get', '/enrollments')->assertUnprocessable(), 'academic_year'))->toBe('alum.validation.required');
    Alum::call($s['tenant'], $s['admin'], 'get', '/enrollments?academic_year=01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();

    // Nombres: «Alumno Dos» (e1), «Alumno Tres» (e2), «Alumno Uno» (e0).
    expect(apiIds(apiList($s, '')))->toBe([$e1, $e2, $e0])
        ->and(apiIds(apiList($s, '&sort=-name')))->toBe([$e0, $e2, $e1])
        ->and(apiIds(apiList($s, '&sort=enrolled_on')))->toBe([$e2, $e1, $e0])
        ->and(apiIds(apiList($s, '&sort=-enrolled_on')))->toBe([$e0, $e1, $e2])
        ->and(apiIds(apiList($s, "&group={$s['groupB']}")))->toBe([$e2])
        ->and(apiIds(apiList($s, "&course={$s['api']['course']}")))->toBe([$e1, $e2, $e0])
        ->and(apiIds(apiList($s, "&course={$s['course2']}")))->toBe([])
        ->and(apiIds(apiList($s, "&student={$s['students'][1]['public_id']}")))->toBe([$e1])
        ->and(apiIds(apiList($s, '&status=baja')))->toBe([$e1])
        ->and(apiIds(apiList($s, '&status=activa')))->toBe([$e2, $e0])
        ->and(apiList($s, '&per_page=2')->json('meta.total'))->toBe(3)
        ->and(count(apiList($s, '&per_page=2&page=2')->json('data')))->toBe(1);

    apiList($s, '&sort=group')->assertUnprocessable();
    apiList($s, '&status=otra')->assertUnprocessable();
    apiList($s, '&per_page=101')->assertUnprocessable();

    Alum::call($s['tenant'], $s['admin'], 'get', "/enrollments/{$e0}")->assertOk()->assertJsonPath('student.display_name', 'Alumno Uno');
});

test('api.md §1 CA-ALUM-026: GET /students filtra por curso, grupo y estado de la matrícula del curso (por defecto el activo) y GET /students/{id} lleva enrollments[] con la forma de la matrícula', function (): void {
    $s = En::scenario('enrapi-students');
    $e0 = apiEnrolled($s, 0);
    $e1 = apiEnrolled($s, 1);
    $e2 = apiEnrolled($s, 2, ['group' => $s['groupB']]);
    Alum::call($s['tenant'], $s['admin'], 'post', "/enrollments/{$e1}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'otro'])->assertOk();
    $ids = array_map(fn (array $st): string => $st['public_id'], $s['students']);
    $list = fn (string $query) => array_column(Alum::call($s['tenant'], $s['admin'], 'get', '/students'.$query)->assertOk()->json('data'), 'public_id');
    $year = $s['year']->public_id;

    expect($list(''))->toHaveCount(3)
        // Solo `academic_year` no filtra.
        ->and($list("?academic_year={$year}"))->toHaveCount(3)
        // Por defecto, el curso activo.
        ->and($list('?enrollment_status=activa'))->toEqualCanonicalizing([$ids[0], $ids[2]])
        ->and($list('?enrollment_status=baja'))->toBe([$ids[1]])
        ->and($list("?group={$s['groupB']}"))->toBe([$ids[2]])
        ->and($list("?course={$s['api']['course']}&academic_year={$year}"))->toHaveCount(3)
        ->and($list("?course={$s['course2']}"))->toBe([])
        ->and($list('?group=01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBe([]);

    Alum::call($s['tenant'], $s['admin'], 'get', '/students?academic_year=01JZZZZZZZZZZZZZZZZZZZZZZZ&course=x')->assertNotFound();
    Alum::call($s['tenant'], $s['admin'], 'get', '/students?enrollment_status=otra')->assertUnprocessable();

    $ficha = Alum::call($s['tenant'], $s['admin'], 'get', "/students/{$ids[0]}")->assertOk();
    $item = $ficha->json('enrollments.0');
    $direct = Alum::call($s['tenant'], $s['admin'], 'get', "/enrollments/{$e0}")->assertOk()->json();

    expect($ficha->json('enrollments'))->toHaveCount(1)->and($item)->toBe($direct)->and($item['public_id'])->toBe($e0)
        ->and($e2)->not->toBe($e0);
});

test('CA-ALUM-016 CA-ALUM-010 RN-ALUM-60 RN-ALUM-64: un docente con matricula.leer de ámbito grupo lista solo las matrículas de su grupo; filtrar por otro grupo da lista vacía y el detalle de otro es 404 idéntico a un inexistente', function (): void {
    $s = En::scenario('enrapi-grupo');
    $a = apiEnrolled($s, 0);
    $b = apiEnrolled($s, 1, ['group' => $s['groupB']]);
    $teacher = Alum::userWithScopes($s['tenant'], $s['admin'], 'docente', ['alumno.leer' => 'grupo', 'matricula.leer' => 'grupo']);
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$s['api']['group']}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    Tt::postAssignment($s['tenant'], $s['admin'], $teacher, $s['api']['group'], $s['api']['subject'])->assertCreated();

    expect(apiIds(apiList($s, '', $teacher)))->toBe([$a])
        ->and(apiIds(apiList($s, "&group={$s['groupB']}", $teacher)))->toBe([]);

    $missing = Alum::call($s['tenant'], $teacher, 'get', '/enrollments/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    $other = Alum::call($s['tenant'], $teacher, 'get', "/enrollments/{$b}")->assertNotFound();
    Alum::call($s['tenant'], $teacher, 'get', "/enrollments/{$a}")->assertOk();

    expect(Arr::only($other->json(), ['type', 'title', 'status', 'detail']))->toBe(Arr::only($missing->json(), ['type', 'title', 'status', 'detail']));

    // La ficha de un alumno de su grupo lista sus matrículas; la de otro grupo es 404.
    Alum::call($s['tenant'], $teacher, 'get', "/students/{$s['students'][0]['public_id']}")->assertOk();
    Alum::call($s['tenant'], $teacher, 'get', "/students/{$s['students'][1]['public_id']}")->assertNotFound();
});

test('RN-ALUM-62 RN-ALUM-63 RN-ALUM-64: un tutor con matricula.leer de ámbito unidad_familiar solo ve las matrículas del alumno con vínculo activo y, tras revocarlo, ninguna', function (): void {
    $s = En::scenario('enrapi-uf');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $e0 = apiEnrolled($s, 0);
    $e1 = apiEnrolled($s, 1);
    $unit = Fam::unitId($tenant, $admin, 'U1');
    Fam::addStudent($tenant, $admin, $unit, Alum::person($tenant, $s['students'][0]['person']['public_id']))->assertCreated();
    Fam::addStudent($tenant, $admin, $unit, Alum::person($tenant, $s['students'][1]['person']['public_id']))->assertCreated();
    $tutor = Alum::userWithScopes($tenant, $admin, 'tutor', ['matricula.leer' => 'unidad_familiar', 'familia.leer' => 'unidad_familiar', 'vinculo_tutor.leer' => 'unidad_familiar']);
    $guardian = Fam::guardian($tenant, $admin, $tutor, $unit)->assertCreated()->json('public_id');
    $link = Fam::link($tenant, $admin, $guardian, Alum::person($tenant, $s['students'][0]['person']['public_id']))->assertCreated()->json('public_id');

    expect(apiIds(apiList($s, '', $tutor)))->toBe([$e0]);
    Alum::call($tenant, $tutor, 'get', "/enrollments/{$e0}")->assertOk();
    Alum::call($tenant, $tutor, 'get', "/enrollments/{$e1}")->assertNotFound();

    Fam::call($tenant, $admin, 'post', "/guardian-links/{$link}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    expect(apiIds(apiList($s, '', $tutor)))->toBe([]);
    Alum::call($tenant, $tutor, 'get', "/enrollments/{$e0}")->assertNotFound();
});

test('INV-002 api.md §2: cada endpoint de matrícula exige su permiso (403 sin él, 401 sin sesión) y las escrituras, ámbito todos', function (): void {
    $s = En::scenario('enrapi-perm');
    $id = apiEnrolled($s);
    $none = Alum::userWith($s['tenant'], $s['admin'], 'nada', ['familia.leer']);
    $reader = Alum::userWith($s['tenant'], $s['admin'], 'lector', ['matricula.leer']);
    $calls = [
        ['get', '/enrollments'], ['post', '/enrollments'], ['get', "/enrollments/{$id}"], ['patch', "/enrollments/{$id}"],
        ['post', "/enrollments/{$id}/withdrawal"], ['delete', "/enrollments/{$id}"],
    ];

    foreach ($calls as [$method, $path]) {
        Alum::call($s['tenant'], $none, $method, $path)->assertForbidden();
        resetSessionState();
        test()->json(strtoupper($method), coreApiUrl($s['tenant']->slug, $path))->assertUnauthorized();
    }

    Alum::call($s['tenant'], $reader, 'get', "/enrollments?academic_year={$s['year']->public_id}")->assertOk();
    Alum::call($s['tenant'], $reader, 'get', "/enrollments/{$id}")->assertOk();
    Alum::call($s['tenant'], $reader, 'post', '/enrollments', apiBody($s, 1))->assertForbidden();
    Alum::call($s['tenant'], $reader, 'patch', "/enrollments/{$id}", ['enrolled_on' => '2026-09-11'])->assertForbidden();
    Alum::call($s['tenant'], $reader, 'post', "/enrollments/{$id}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'otro'])->assertForbidden();
    Alum::call($s['tenant'], $reader, 'delete', "/enrollments/{$id}")->assertForbidden();

    // Cada acción tiene su permiso: crear no permite anular ni dar de baja.
    $creator = Alum::userWith($s['tenant'], $s['admin'], 'creador', ['matricula.crear']);
    Alum::call($s['tenant'], $creator, 'delete', "/enrollments/{$id}")->assertForbidden();
    Alum::call($s['tenant'], $creator, 'post', '/enrollments', apiBody($s, 1))->assertCreated();
    expect(Alum::row($s['tenant'], 'enrollments', $id)->deleted_at)->toBeNull();
});

test('CA-ALUM-018b INV-001: con dos centros equivalentes, ningún endpoint de matrícula del primero devuelve, modifica ni cuenta matrículas del segundo', function (): void {
    $a = En::scenario('enrapi-iso-a');
    $b = En::scenario('enrapi-iso-b');
    $idA = apiEnrolled($a);
    $idB = apiEnrolled($b);

    expect(apiIds(apiList($a, '')))->toBe([$idA])
        ->and(Alum::call($a['tenant'], $a['admin'], 'get', "/enrollments?academic_year={$b['year']->public_id}")->status())->toBe(404);

    Alum::call($a['tenant'], $a['admin'], 'get', "/enrollments/{$idB}")->assertNotFound();
    Alum::call($a['tenant'], $a['admin'], 'patch', "/enrollments/{$idB}", ['enrolled_on' => '2026-09-11'])->assertNotFound();
    Alum::call($a['tenant'], $a['admin'], 'post', "/enrollments/{$idB}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'otro'])->assertNotFound();
    Alum::call($a['tenant'], $a['admin'], 'delete', "/enrollments/{$idB}")->assertNotFound();

    // Alumno, curso y año de otro centro: 422 / 404 sin revelar su existencia.
    Alum::call($a['tenant'], $a['admin'], 'post', '/enrollments', apiBody($a, 0, ['student' => $b['students'][1]['public_id']]))->assertUnprocessable();
    Alum::call($a['tenant'], $a['admin'], 'post', '/enrollments', apiBody($a, 1, ['academic_year' => $b['year']->public_id]))->assertUnprocessable();

    expect(En::liveCount($b['tenant']))->toBe(1)
        ->and(Alum::row($b['tenant'], 'enrollments', $idB)->status)->toBe('activa')
        ->and(Alum::row($b['tenant'], 'enrollments', $idB)->enrolled_on)->toBe('2026-09-10')
        ->and(Alum::count($a['tenant'], 'enrollments'))->toBe(1);

    // Los alumnos del otro centro no salen en el listado ni en la ficha de este.
    expect(array_column(Alum::call($a['tenant'], $a['admin'], 'get', "/students?course={$a['api']['course']}")->assertOk()->json('data'), 'public_id'))->toBe([$a['students'][0]['public_id']]);
    Alum::call($a['tenant'], $a['admin'], 'get', "/students/{$b['students'][0]['public_id']}")->assertNotFound();
});

test('api.md §1 RN-ALUM-23 RN-ALUM-60: enrollments[] de la ficha se acota por el ámbito de matricula.leer del actor: sin el permiso es [], con grupo solo las de su grupo, con todos todas', function (): void {
    $s = En::scenario('enrapi-ficha');
    $plan = En::planningYear($s);
    $student = $s['students'][0]['public_id'];
    $active = apiEnrolled($s);
    $planned = apiEnroll($s, ['student' => $student, 'academic_year' => $plan['year']->public_id, 'course' => $plan['api']['course'], 'group' => $plan['api']['group'], 'enrolled_on' => '2027-09-05'])->assertCreated()->json('public_id');
    $teacher = Alum::userWithScopes($s['tenant'], $s['admin'], 'docente', ['alumno.leer' => 'grupo', 'matricula.leer' => 'grupo']);
    $noMatricula = Alum::userWithScopes($s['tenant'], $s['admin'], 'docente_sin', ['alumno.leer' => 'grupo']);

    foreach ([$teacher, $noMatricula] as $user) {
        Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$s['api']['group']}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
        Tt::postAssignment($s['tenant'], $s['admin'], $user, $s['api']['group'], $s['api']['subject'])->assertCreated();
    }

    $ids = fn (User $u) => array_column(Alum::call($s['tenant'], $u, 'get', "/students/{$student}")->assertOk()->json('enrollments'), 'public_id');

    expect($ids($noMatricula))->toBe([])
        ->and($ids($teacher))->toBe([$active])
        ->and($ids($s['admin']))->toEqualCanonicalizing([$active, $planned]);
});

test('api.md §1: StudentSummary.enrollment es la matrícula vigente del curso pedido con la forma de Enrollment, acotada por matricula.leer; null sin filtro de curso o año, sin matrícula o sin el permiso', function (): void {
    $s = En::scenario('enrapi-summary');
    $e0 = apiEnrolled($s, 0);
    $first = fn (array $r) => $r['data'][0] ?? null;
    $get = fn (User $u, string $q) => Alum::call($s['tenant'], $u, 'get', '/students'.$q)->assertOk()->json();
    $direct = Alum::call($s['tenant'], $s['admin'], 'get', "/enrollments/{$e0}")->assertOk()->json();
    $noMatricula = Alum::userWith($s['tenant'], $s['admin'], 'solo_alumno', ['alumno.leer']);
    $year = $s['year']->public_id;
    $byName = fn (array $r, string $name) => collect($r['data'])->firstWhere('display_name', $name);

    // Sin filtro de curso o año: null para todos.
    expect(collect($get($s['admin'], '')['data'])->pluck('enrollment')->filter()->all())->toBe([]);

    $withYear = $get($s['admin'], "?academic_year={$year}");
    $filtered = $get($s['admin'], "?course={$s['api']['course']}");

    expect($byName($withYear, 'Alumno Uno')['enrollment'])->toBe($direct)
        ->and($byName($withYear, 'Alumno Dos')['enrollment'])->toBeNull()
        ->and($byName($filtered, 'Alumno Uno')['enrollment'])->toBe($direct)
        // Sin matricula.leer, null aunque haya matrícula.
        ->and($first($get($noMatricula, "?academic_year={$year}&sort=name"))['enrollment'])->toBeNull()
        ->and(collect($get($noMatricula, "?academic_year={$year}")['data'])->pluck('enrollment')->filter()->all())->toBe([]);

    // Una baja y una matrícula nueva en el mismo curso: la vigente es la activa.
    Alum::call($s['tenant'], $s['admin'], 'post', "/enrollments/{$e0}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'traslado'])->assertOk();
    $e0b = apiEnroll($s, apiBody($s, 0, ['enrolled_on' => '2026-10-02']))->assertCreated()->json('public_id');

    expect($byName($get($s['admin'], "?academic_year={$year}&course={$s['api']['course']}"), 'Alumno Uno')['enrollment']['public_id'])->toBe($e0b);
});
