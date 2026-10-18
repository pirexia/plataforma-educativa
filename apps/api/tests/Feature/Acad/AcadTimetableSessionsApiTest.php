<?php

use App\Models\AuditLog;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-002 (1.12-a), funcional.md §18.4.5 (RN-ACAD-120 a -128), §18.5,
// §18.9: CA-ACAD-116 (aula dada de baja), CA-ACAD-122 a -127 (sesiones,
// conflictos y vistas semanales). INV-003, INV-010, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Escenario con un segundo grupo B de la misma etapa, un segundo subgrupo D2,
 * un laboratorio y los profesores t1, t2 y t3, con las asignaturas MAT asignadas
 * a ambos grupos.
 *
 * @return array<string, mixed>
 */
function ttSessions(string $slug): array
{
    $s = Tt::scenario($slug);
    $t = $s['tenant'];
    $a = $s['admin'];
    $post = fn (string $path, array $body) => Tt::send($t, $a, 'POST', $path, $body)->assertCreated()->json('public_id');

    $s['groupB'] = $post('/groups', ['course' => $s['api']['course'], 'code' => 'B', 'name' => 'Grupo B']);
    Tt::send($t, $a, 'PUT', "/groups/{$s['groupB']}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    $s['d2'] = $post("/groups/{$s['api']['group']}/subgroups", ['code' => 'D2', 'name' => 'Desdoble 2']);
    $s['lab'] = $post('/facilities', ['facility_type' => 'aula', 'code' => 'LAB', 'name' => 'Laboratorio']);
    $s['t3'] = Api::userWith($t, $a, 'prof3', ['horarios.leer']);

    return $s;
}

function ttAssign(array $s, $teacher, ?string $group = null, ?string $subgroup = null): string
{
    return Tt::postAssignment($s['tenant'], $s['admin'], $teacher, $group ?? $s['api']['group'], $s['api']['subject'], $subgroup)->assertCreated()->json('public_id');
}

function ttConflicts($response): array
{
    $response->assertStatus(422);
    expect($response->json('errors.time_slot.0.code'))->toBe('acad.validation.timetable_conflict');

    return $response->json('errors.time_slot.0.params.conflicts');
}

test('CA-ACAD-122 RN-ACAD-120 RN-ACAD-121: el alta con franja, asignación y aula es 201 con los resúmenes de franja, profesor, grupo, asignatura y aula, y deja una entrada created', function (): void {
    $s = ttSessions('se-122');
    $assignment = ttAssign($s, $s['t1']);

    $r = Tt::postSession($s['tenant'], $s['admin'], $s['slots'][0], $assignment, $s['facility'])->assertCreated();

    expect($r->json('frame'))->toBe($s['frame']['public_id'])
        ->and($r->json('slot'))->toBe(['public_id' => $s['slots'][0], 'weekday' => 1, 'starts_at' => '08:30', 'ends_at' => '09:25'])
        ->and($r->json('teaching_assignment'))->toBe($assignment)
        ->and($r->json('teacher.public_id'))->toBe($s['t1']->public_id)
        ->and($r->json('teacher.name'))->toBe('Persona Prueba')
        ->and($r->json('group'))->toBe(['public_id' => $s['api']['group'], 'code' => 'A', 'name' => 'Grupo A'])
        ->and($r->json('subgroup'))->toBeNull()
        ->and($r->json('subject.code'))->toBe('MAT')
        ->and($r->json('subject.name'))->toBe('Matemáticas')
        ->and($r->json('facility'))->toBe(['public_id' => $s['facility'], 'code' => 'A21', 'name' => 'Aula 2.1', 'deleted' => false])
        ->and($r->headers->get('Location'))->toEndWith('/timetable-sessions/'.$r->json('public_id'));

    app(TenantContext::class)->runFor($s['tenant']->id, fn () => expect(AuditLog::query()->where('auditable_type', 'timetable_session')->where('event', 'created')->count())->toBe(1));

    Tt::send($s['tenant'], $s['admin'], 'GET', '/timetable-sessions/'.$r->json('public_id'))->assertOk()->assertJsonPath('slot.weekday', 1);
});

test('CA-ACAD-122 RN-ACAD-120 RN-ACAD-121: franja de un marco de otra etapa es slot_not_for_group; un recurso es facility_not_room; un aula borrada o inexistente es facility_not_found; franja o asignación ajenas son parent_not_found', function (): void {
    $s = ttSessions('se-122b');
    $t = $s['tenant'];
    $a = $s['admin'];
    $assignment = ttAssign($s, $s['t1']);

    // Otra etapa con su marco.
    $other = Api::tree($t, $a, $s['year'], 'X');
    $otherFrame = Tt::postFrame($t, $a, Tt::frameBody($other['level']))->assertCreated()->json();
    $r = Tt::postSession($t, $a, $otherFrame['slots'][0]['public_id'], $assignment)->assertStatus(422);
    expect($r->json('errors.time_slot.0.code'))->toBe('acad.validation.slot_not_for_group');

    $resource = Tt::postFacility($t, $a, ['facility_type' => 'recurso', 'code' => 'CARRO', 'name' => 'Carro'])->json('public_id');
    expect(Tt::postSession($t, $a, $s['slots'][0], $assignment, $resource)->assertStatus(422)->json('errors.facility.0.code'))->toBe('acad.validation.facility_not_room');

    Tt::send($t, $a, 'DELETE', "/facilities/{$s['lab']}")->assertNoContent();
    expect(Tt::postSession($t, $a, $s['slots'][0], $assignment, $s['lab'])->assertStatus(422)->json('errors.facility.0.code'))->toBe('acad.validation.facility_not_found');
    expect(Tt::postSession($t, $a, $s['slots'][0], $assignment, '01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertStatus(422)->json('errors.facility.0.code'))->toBe('acad.validation.facility_not_found');

    expect(Tt::postSession($t, $a, '01JZZZZZZZZZZZZZZZZZZZZZZZ', $assignment)->assertStatus(422)->json('errors.time_slot.0.code'))->toBe('acad.validation.parent_not_found');
    expect(Tt::postSession($t, $a, $s['slots'][0], '01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertStatus(422)->json('errors.teaching_assignment.0.code'))->toBe('acad.validation.parent_not_found');
    expect(Tt::send($t, $a, 'POST', '/timetable-sessions', ['time_slot' => $s['slots'][0], 'teaching_assignment' => $assignment, 'academic_year' => $s['year']->public_id])->assertStatus(422)->json('errors.academic_year.0.code'))->toBe('acad.validation.academic_year_not_allowed');

    expect(Tt::count($t, 'timetable_sessions'))->toBe(0);
});

test('CA-ACAD-122 datos.md §9.1: la inserción directa de una sesión con un espacio recurso es 23503', function (): void {
    $s = ttSessions('se-122c');
    $resource = Tt::postFacility($s['tenant'], $s['admin'], ['facility_type' => 'recurso', 'code' => 'CARRO', 'name' => 'Carro'])->json('public_id');
    $assignment = ttAssign($s, $s['t1']);

    app(TenantContext::class)->runFor($s['tenant']->id, function () use ($s, $resource, $assignment): void {
        $row = [
            'tenant_id' => $s['tenant']->id, 'public_id' => (string) Str::ulid(), 'academic_year_id' => $s['year']->id,
            'time_slot_id' => DB::table('time_slots')->where('public_id', $s['slots'][0])->value('id'),
            'teaching_assignment_id' => DB::table('teaching_assignments')->where('public_id', $assignment)->value('id'),
            'facility_id' => DB::table('facilities')->where('public_id', $resource)->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ];
        $codes = [];

        // Con facility_type 'recurso' falla el CHECK; con 'aula' o NULL, la FK con discriminador.
        foreach (['recurso' => '23514', 'aula' => '23503'] as $type => $expected) {
            try {
                DB::transaction(fn () => DB::table('timetable_sessions')->insert($row + ['facility_type' => $type]));
            } catch (QueryException $e) {
                $codes[$type] = $e->errorInfo[0];
            }

            expect($codes[$type] ?? null)->toBe($expected);
        }
    });
});

test('CA-ACAD-123 RN-ACAD-123 RN-ACAD-124: los seis casos de §18.5 dan exactamente el resultado indicado, con kind y public_id de la sesión con la que choca, y los rechazos no escriben', function (): void {
    $s = ttSessions('se-123');
    $t = $s['tenant'];
    $a = $s['admin'];
    $monday = $s['slots'][0];
    $tuesday = $s['slots'][1];
    $wednesday = $s['slots'][2];
    $thursday = $s['slots'][3];
    $friday = $s['slots'][4];

    // Etapa B con su marco: lunes 09:00-09:55 y martes 09:25-10:20.
    $b = Api::tree($t, $a, $s['year'], 'B');
    Tt::send($t, $a, 'PUT', "/groups/{$b['group']}/subjects", ['subjects' => [$b['subject']]])->assertOk();
    $frameB = Tt::postFrame($t, $a, Tt::frameBody($b['level'], ['slots' => [
        ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '09:55'],
        ['weekday' => 2, 'starts_at' => '09:25', 'ends_at' => '10:20'],
    ]]))->assertCreated()->json();

    // 1) Profesora P: grupo A lunes 08:30-09:25 y se le asigna la etapa B el lunes 09:00-09:55 ⇒ 422 teacher.
    $pA = ttAssign($s, $s['t1']);
    $pB = Tt::postAssignment($t, $a, $s['t1'], $b['group'], $b['subject'])->assertCreated()->json('public_id');
    $first = Tt::postSession($t, $a, $monday, $pA)->assertCreated()->json('public_id');
    $before = Tt::count($t, 'timetable_sessions');
    expect(ttConflicts(Tt::postSession($t, $a, $frameB['slots'][0]['public_id'], $pB)))->toBe([['kind' => 'teacher', 'session' => $first]])
        ->and(Tt::count($t, 'timetable_sessions'))->toBe($before);

    // 2) Semiabierto: martes 08:30-09:25 (A) y 09:25-10:20 (B), mismo profesor ⇒ 201.
    Tt::postSession($t, $a, $tuesday, $pA)->assertCreated();
    Tt::postSession($t, $a, $frameB['slots'][1]['public_id'], $pB)->assertCreated();

    // 3) Desdoble: D1 en el laboratorio y D2 en el aula 1, mismo grupo y franja ⇒ 201.
    $d1 = ttAssign($s, $s['t2'], null, $s['api']['subgroup']);
    $d2 = ttAssign($s, $s['t3'], null, $s['d2']);
    Tt::postSession($t, $a, $wednesday, $d1, $s['lab'])->assertCreated();
    Tt::postSession($t, $a, $wednesday, $d2, $s['facility'])->assertCreated();

    // 4) Sesión del grupo entero y de su subgrupo D1 a la vez ⇒ 422 group (jueves, profesores distintos).
    $whole = ttAssign($s, $s['t3']);
    $wholeSession = Tt::postSession($t, $a, $thursday, $whole)->assertCreated()->json('public_id');
    expect(ttConflicts(Tt::postSession($t, $a, $thursday, $d1)))->toBe([['kind' => 'group', 'session' => $wholeSession]]);

    // 5) Dos sesiones en el aula 1 a la misma hora con grupos y profesores distintos ⇒ 422 facility (viernes).
    $inRoom = Tt::postSession($t, $a, $friday, ttAssign($s, $s['t2'], $s['groupB']), $s['facility'])->assertCreated()->json('public_id');
    expect(ttConflicts(Tt::postSession($t, $a, $friday, $pA, $s['facility'])))->toBe([['kind' => 'facility', 'session' => $inRoom]]);

    // 6) Sin aula a la misma hora que otra sin aula: sin choque de aula (lunes, grupo y profesor distintos).
    Tt::postSession($t, $a, $monday, ttAssign($s, $s['t3'], $s['groupB']))->assertCreated();
});

test('CA-ACAD-123 RN-ACAD-123: una sesión que choca por profesor, aula y grupo a la vez los devuelve todos, en ese orden', function (): void {
    $s = ttSessions('se-123b');
    $assignment = ttAssign($s, $s['t1']);
    $first = Tt::postSession($s['tenant'], $s['admin'], $s['slots'][0], $assignment, $s['facility'])->assertCreated()->json('public_id');

    expect(ttConflicts(Tt::postSession($s['tenant'], $s['admin'], $s['slots'][0], $assignment, $s['facility'])))->toBe([
        ['kind' => 'teacher', 'session' => $first], ['kind' => 'facility', 'session' => $first], ['kind' => 'group', 'session' => $first],
    ]);
});

test('CA-ACAD-124 RN-ACAD-124: el mismo profesor en el ordinario de ESO (lunes 09:00-09:55) y en el de otra etapa (lunes 09:30-10:25, franjas distintas) es 422 timetable_conflict kind teacher', function (): void {
    $s = ttSessions('se-124');
    $t = $s['tenant'];
    $a = $s['admin'];
    Tt::send($t, $a, 'PATCH', "/timetable-frames/{$s['frame']['public_id']}", ['slots' => [['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '09:55']]])->assertOk();
    $slotA = Tt::send($t, $a, 'GET', "/timetable-frames/{$s['frame']['public_id']}")->json('slots.0.public_id');

    $b = Api::tree($t, $a, $s['year'], 'B');
    Tt::send($t, $a, 'PUT', "/groups/{$b['group']}/subjects", ['subjects' => [$b['subject']]])->assertOk();
    $frameB = Tt::postFrame($t, $a, Tt::frameBody($b['level'], ['slots' => [['weekday' => 1, 'starts_at' => '09:30', 'ends_at' => '10:25']]]))->assertCreated()->json();

    $first = Tt::postSession($t, $a, $slotA, ttAssign($s, $s['t1']))->assertCreated()->json('public_id');
    $other = Tt::postAssignment($t, $a, $s['t1'], $b['group'], $b['subject'])->assertCreated()->json('public_id');

    expect(ttConflicts(Tt::postSession($t, $a, $frameB['slots'][0]['public_id'], $other)))->toBe([['kind' => 'teacher', 'session' => $first]]);
});

test('CA-ACAD-125 RN-ACAD-122: PATCH de time_slot a una franja libre es 200 con updated; a una que choca es 422 y la sesión no se mueve; facility null quita el aula; teaching_assignment es parent_immutable', function (): void {
    $s = ttSessions('se-125');
    $t = $s['tenant'];
    $a = $s['admin'];
    $mine = Tt::postSession($t, $a, $s['slots'][0], ttAssign($s, $s['t1']), $s['facility'])->assertCreated()->json('public_id');
    $blocker = Tt::postSession($t, $a, $s['slots'][1], ttAssign($s, $s['t1'], $s['groupB']))->assertCreated()->json('public_id');

    $moved = Tt::send($t, $a, 'PATCH', "/timetable-sessions/{$mine}", ['time_slot' => $s['slots'][2]])->assertOk();
    expect($moved->json('slot.public_id'))->toBe($s['slots'][2]);
    app(TenantContext::class)->runFor($t->id, fn () => expect(AuditLog::query()->where('auditable_type', 'timetable_session')->where('event', 'updated')->count())->toBe(1));

    expect(ttConflicts(Tt::send($t, $a, 'PATCH', "/timetable-sessions/{$mine}", ['time_slot' => $s['slots'][1]])))->toBe([['kind' => 'teacher', 'session' => $blocker]]);
    Tt::send($t, $a, 'GET', "/timetable-sessions/{$mine}")->assertJsonPath('slot.public_id', $s['slots'][2]);

    // Una sesión no choca consigo misma al guardarse sin mover.
    Tt::send($t, $a, 'PATCH', "/timetable-sessions/{$mine}", ['time_slot' => $s['slots'][2]])->assertOk();

    $r = Tt::send($t, $a, 'PATCH', "/timetable-sessions/{$mine}", ['facility' => null])->assertOk();
    expect($r->json('facility'))->toBeNull()->and(DB::table('timetable_sessions')->where('public_id', $mine)->value('facility_type'))->toBeNull();
    expect(Tt::send($t, $a, 'PATCH', "/timetable-sessions/{$mine}", ['facility' => $s['lab']])->assertOk()->json('facility.code'))->toBe('LAB');

    $r = Tt::send($t, $a, 'PATCH', "/timetable-sessions/{$mine}", ['teaching_assignment' => ttAssign($s, $s['t2'])])->assertStatus(422);
    expect($r->json('errors.teaching_assignment.0.code'))->toBe('acad.validation.parent_immutable');

    Tt::send($t, $a, 'DELETE', "/timetable-sessions/{$mine}")->assertNoContent();
    Tt::send($t, $a, 'GET', "/timetable-sessions/{$mine}")->assertNotFound();
});

test('CA-ACAD-127 RN-ACAD-127: GET /timetable-sessions sin filtro es 422 view_filter_required; group incluye los subgrupos, subject todos los grupos, teacher y facility las suyas; orden por día y hora', function (): void {
    $s = ttSessions('se-127');
    $t = $s['tenant'];
    $a = $s['admin'];

    $whole = Tt::postSession($t, $a, $s['slots'][1], ttAssign($s, $s['t1']), $s['facility'])->json('public_id');
    $sub = Tt::postSession($t, $a, $s['slots'][0], ttAssign($s, $s['t2'], null, $s['api']['subgroup']), $s['lab'])->json('public_id');
    $groupB = Tt::postSession($t, $a, $s['slots'][2], ttAssign($s, $s['t3'], $s['groupB']))->json('public_id');

    $ids = fn (string $query) => array_column(Tt::send($t, $a, 'GET', '/timetable-sessions?'.$query)->assertOk()->json('data'), 'public_id');

    expect(Tt::send($t, $a, 'GET', '/timetable-sessions')->assertStatus(422)->json('errors.teacher.0.code'))->toBe('acad.validation.view_filter_required')
        ->and(Tt::send($t, $a, 'GET', '/timetable-sessions?academic_year='.$s['year']->public_id)->assertStatus(422)->json('errors.teacher.0.code'))->toBe('acad.validation.view_filter_required')
        ->and($ids('group='.$s['api']['group']))->toBe([$sub, $whole])
        ->and($ids('subject='.$s['api']['subject']))->toBe([$sub, $whole, $groupB])
        ->and($ids('teacher='.$s['t1']->public_id))->toBe([$whole])
        ->and($ids('facility='.$s['lab']))->toBe([$sub])
        ->and($ids('frame='.$s['frame']['public_id'].'&facility='.$s['facility']))->toBe([$whole])
        ->and($ids('group=01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBe([])
        ->and($ids('teacher=01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBe([]);
});

test('CA-ACAD-127 RN-ACAD-127: una vista con más de 500 sesiones es 422 view_too_large', function (): void {
    $s = ttSessions('se-127b');
    $assignment = ttAssign($s, $s['t1']);

    app(TenantContext::class)->runFor($s['tenant']->id, function () use ($s, $assignment): void {
        $slotId = DB::table('time_slots')->where('public_id', $s['slots'][0])->value('id');
        $assignmentId = DB::table('teaching_assignments')->where('public_id', $assignment)->value('id');
        $rows = [];

        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['tenant_id' => $s['tenant']->id, 'public_id' => (string) Str::ulid(), 'academic_year_id' => $s['year']->id, 'time_slot_id' => $slotId, 'teaching_assignment_id' => $assignmentId, 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('timetable_sessions')->insert($rows);
    });

    $r = Tt::send($s['tenant'], $s['admin'], 'GET', '/timetable-sessions?teacher='.$s['t1']->public_id)->assertStatus(422);
    expect($r->json('errors.teacher.0.code'))->toBe('acad.validation.view_too_large')
        ->and($r->json('errors.teacher.0.params.max'))->toBe(500);
});

test('CA-ACAD-116 RN-ACAD-102 RN-ACAD-128: con el curso cerrado, un aula con sesiones solo en él se borra (204), su sesión se sigue leyendo con facility.deleted true y no se puede crear ni mover una sesión a un aula borrada', function (): void {
    $s = ttSessions('se-116');
    $t = $s['tenant'];
    $a = $s['admin'];
    $session = Tt::postSession($t, $a, $s['slots'][0], ttAssign($s, $s['t1']), $s['facility'])->assertCreated()->json('public_id');

    expect(Tt::send($t, $a, 'DELETE', "/facilities/{$s['facility']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))->toBe(['timetable_sessions']);

    CursoTestHelpers::setStatus($t, $s['year'], AcademicYearStatus::Cerrado);
    Tt::send($t, $a, 'DELETE', "/facilities/{$s['facility']}")->assertNoContent();

    $reader = Api::userWith($t, $a, 'historico116', ['horarios.leer', 'curso_historico.leer']);
    $r = Tt::send($t, $reader, 'GET', "/timetable-sessions/{$session}")->assertOk();
    expect($r->json('facility'))->toBe(['public_id' => $s['facility'], 'code' => 'A21', 'name' => 'Aula 2.1', 'deleted' => true]);
});

test('CA-ACAD-122 RN-ACAD-128: crear o mover una sesión a un aula borrada de un curso activo es 422 facility_not_found', function (): void {
    $s = ttSessions('se-128');
    $session = Tt::postSession($s['tenant'], $s['admin'], $s['slots'][0], ttAssign($s, $s['t1']))->assertCreated()->json('public_id');
    Tt::send($s['tenant'], $s['admin'], 'DELETE', "/facilities/{$s['lab']}")->assertNoContent();

    $r = Tt::send($s['tenant'], $s['admin'], 'PATCH', "/timetable-sessions/{$session}", ['facility' => $s['lab']])->assertStatus(422);
    expect($r->json('errors.facility.0.code'))->toBe('acad.validation.facility_not_found');
});
