<?php

use App\Modules\Curso\Domain\AcademicYearStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-002 (1.12-a), funcional.md §18.9: CA-ACAD-128 (curso cerrado,
// escritura), CA-ACAD-129 (curso cerrado, lectura) y CA-ACAD-132 (aislamiento
// entre centros) para asignaciones, tutorías, marcos y sesiones. INV-001,
// INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Escenario con una asignación, una sesión y un tutor. */
function ttYear(string $slug): array
{
    $s = Tt::scenario($slug);
    $s['assignment'] = Tt::postAssignment($s['tenant'], $s['admin'], $s['t1'], $s['api']['group'], $s['api']['subject'])->assertCreated()->json('public_id');
    $s['session'] = Tt::postSession($s['tenant'], $s['admin'], $s['slots'][0], $s['assignment'])->assertCreated()->json('public_id');
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$s['api']['group']}/tutors", ['tutors' => [$s['t1']->public_id]])->assertOk();

    // Una asignación y un marco sin sesiones: son lo único que se puede borrar sin chocar con `has_dependents`.
    $s['free'] = Tt::postAssignment($s['tenant'], $s['admin'], $s['t2'], $s['api']['group'], $s['api']['subject'])->assertCreated()->json('public_id');
    $other = Api::tree($s['tenant'], $s['admin'], $s['year'], 'Z');
    $s['freeFrame'] = Tt::postFrame($s['tenant'], $s['admin'], Tt::frameBody($other['level']))->assertCreated()->json('public_id');

    return $s;
}

test('CA-ACAD-128 RN-ACAD-90: con un curso cerrado, crear, modificar o borrar asignaciones, tutorías, marcos (con y sin slots) y sesiones es 409 academic-year-closed y nada cambia', function (): void {
    $s = ttYear('yr-128');
    $t = $s['tenant'];
    $a = $s['admin'];
    $bare = Api::tree($t, $a, $s['year'], 'Y');
    $third = Api::userWith($t, $a, 'prof3yr', ['horarios.leer']);
    $counts = fn (): array => array_map(fn (string $table): int => Tt::count($t, $table), ['teaching_assignments', 'group_tutors', 'timetable_frames', 'time_slots', 'timetable_sessions']);
    $before = $counts();

    CursoTestHelpers::setStatus($t, $s['year'], AcademicYearStatus::Cerrado);

    $attempts = [
        ['PUT', "/groups/{$s['api']['group']}/tutors", ['tutors' => []]],
        ['DELETE', "/teaching-assignments/{$s['free']}", []],
        ['PATCH', "/timetable-frames/{$s['frame']['public_id']}", ['name_translations' => ['es-ES' => 'Nuevo']]],
        ['PATCH', "/timetable-frames/{$s['freeFrame']}", ['slots' => []]],
        ['DELETE', "/timetable-frames/{$s['freeFrame']}", []],
        ['PATCH', "/timetable-sessions/{$s['session']}", ['time_slot' => $s['slots'][1]]],
        ['DELETE', "/timetable-sessions/{$s['session']}", []],
        ['POST', '/timetable-sessions', ['time_slot' => $s['slots'][1], 'teaching_assignment' => $s['assignment']]],
        ['POST', '/teaching-assignments', ['teacher' => $third->public_id, 'group' => $s['api']['group'], 'subject' => $s['api']['subject']]],
        ['POST', '/timetable-frames', Tt::frameBody($bare['level'])],
    ];

    foreach ($attempts as [$method, $path, $body]) {
        $r = Tt::send($t, $a, $method, $path, $body);

        // Un padre en el cuerpo de un curso de solo lectura: el administrador lo lee (409); sin él sería 422 (S-3).
        expect($r->status())->toBe(409, "{$method} {$path}")
            ->and($r->json('type'))->toBe('urn:pge:error:academic-year-closed', "{$method} {$path}");
    }

    expect($counts())->toBe($before);
});

test('CA-ACAD-128 RN-ACAD-90: con un curso cerrado, un padre del cuerpo sin curso_historico.leer es 422 *_not_found, como el inexistente', function (): void {
    $s = ttYear('yr-128b');
    $t = $s['tenant'];
    $writer = Api::userWith($t, $s['admin'], 'escritor128', ['asignacion_docente.crear', 'horarios.crear']);
    CursoTestHelpers::setStatus($t, $s['year'], AcademicYearStatus::Cerrado);

    $r = Tt::send($t, $writer, 'POST', '/teaching-assignments', ['teacher' => $s['t2']->public_id, 'group' => $s['api']['group'], 'subject' => $s['api']['subject']])->assertStatus(422);
    expect($r->json('errors.group.0.code'))->toBe('acad.validation.parent_not_found');

    $r = Tt::send($t, $writer, 'POST', '/timetable-sessions', ['time_slot' => $s['slots'][1], 'teaching_assignment' => $s['assignment']])->assertStatus(422);
    expect($r->json('errors.time_slot.0.code'))->toBe('acad.validation.parent_not_found');
});

test('CA-ACAD-129 RN-ACAD-90 RN-CURSO-33: listado y detalle de un curso cerrado sin curso_historico.leer son 404 (mismo cuerpo que un recurso inexistente); con él, los datos', function (): void {
    $s = ttYear('yr-129');
    $t = $s['tenant'];
    $reader = Api::userWith($t, $s['admin'], 'lector129', ['asignacion_docente.leer', 'horarios.leer']);
    $historian = Api::userWith($t, $s['admin'], 'historiador129', ['asignacion_docente.leer', 'horarios.leer', 'curso_historico.leer']);
    CursoTestHelpers::setStatus($t, $s['year'], AcademicYearStatus::Cerrado);

    $paths = [
        '/teaching-assignments?academic_year='.$s['year']->public_id,
        "/teaching-assignments/{$s['assignment']}",
        "/groups/{$s['api']['group']}/tutors",
        '/timetable-frames?academic_year='.$s['year']->public_id,
        "/timetable-frames/{$s['frame']['public_id']}",
        "/timetable-sessions/{$s['session']}",
        '/timetable-sessions?group='.$s['api']['group'].'&academic_year='.$s['year']->public_id,
    ];

    $missing = Tt::send($t, $reader, 'GET', '/timetable-frames/01JA0000000000000000000009')->assertNotFound()->json();
    unset($missing['request_id'], $missing['instance']);

    foreach ($paths as $path) {
        $denied = Tt::send($t, $reader, 'GET', $path)->assertNotFound()->json();
        unset($denied['request_id'], $denied['instance']);

        expect($denied)->toBe($missing, $path);
        Tt::send($t, $historian, 'GET', $path)->assertOk();
    }
});

test('CA-ACAD-132 INV-001: con dos centros equivalentes, ningún endpoint lee, cuenta, modifica ni borra datos del otro; un public_id ajeno en ruta es 404 y en cuerpo, 422 de su campo', function (): void {
    $a = ttYear('iso-a');
    $b = ttYear('iso-b');
    $tb = $b['tenant'];
    $ab = $b['admin'];

    // Autenticado en B, los recursos de A no existen.
    foreach ([
        ['GET', "/teaching-assignments/{$a['assignment']}"],
        ['DELETE', "/teaching-assignments/{$a['assignment']}"],
        ['GET', "/groups/{$a['api']['group']}/tutors"],
        ['PUT', "/groups/{$a['api']['group']}/tutors"],
        ['GET', "/timetable-frames/{$a['frame']['public_id']}"],
        ['PATCH', "/timetable-frames/{$a['frame']['public_id']}"],
        ['DELETE', "/timetable-frames/{$a['frame']['public_id']}"],
        ['GET', "/timetable-sessions/{$a['session']}"],
        ['PATCH', "/timetable-sessions/{$a['session']}"],
        ['DELETE', "/timetable-sessions/{$a['session']}"],
        ['GET', "/facilities/{$a['facility']}"],
    ] as [$method, $path]) {
        Tt::send($tb, $ab, $method, $path, $method === 'PUT' ? ['tutors' => []] : [])->assertNotFound();
    }

    // Los listados de B no incluyen nada de A.
    $ids = fn (string $path): array => array_column(Tt::send($tb, $ab, 'GET', $path)->assertOk()->json('data'), 'public_id');
    expect($ids('/teaching-assignments'))->toBe([$b['assignment'], $b['free']])
        ->and(collect($ids('/timetable-frames'))->sort()->values()->all())->toBe(collect([$b['frame']['public_id'], $b['freeFrame']])->sort()->values()->all())
        ->and($ids('/timetable-sessions?group='.$b['api']['group']))->toBe([$b['session']])
        ->and($ids('/timetable-sessions?group='.$a['api']['group']))->toBe([])
        ->and($ids('/timetable-sessions?teacher='.$a['t1']->public_id))->toBe([])
        ->and($ids('/teaching-assignments?teacher='.$a['t1']->public_id))->toBe([])
        ->and($ids('/teaching-assignments?group='.$a['api']['group']))->toBe([]);

    // En cuerpo: usuario, grupo, asignatura, subgrupo, franja, asignación y aula de A ⇒ 422 de su campo.
    $r = Tt::send($tb, $ab, 'POST', '/teaching-assignments', ['teacher' => $a['t1']->public_id, 'group' => $b['api']['group'], 'subject' => $b['api']['subject']])->assertStatus(422);
    expect($r->json('errors.teacher.0.code'))->toBe('acad.validation.user_not_found');
    expect(Tt::postAssignment($tb, $ab, $b['t2'], $a['api']['group'], $b['api']['subject'])->assertStatus(422)->json('errors.group.0.code'))->toBe('acad.validation.parent_not_found');
    expect(Tt::postAssignment($tb, $ab, $b['t2'], $b['api']['group'], $a['api']['subject'])->assertStatus(422)->json('errors.subject.0.code'))->toBe('acad.validation.subject_not_in_group');
    expect(Tt::postAssignment($tb, $ab, $b['t2'], $b['api']['group'], $b['api']['subject'], $a['api']['subgroup'])->assertStatus(422)->json('errors.subgroup.0.code'))->toBe('acad.validation.subgroup_not_in_group');
    expect(Tt::send($tb, $ab, 'PUT', "/groups/{$b['api']['group']}/tutors", ['tutors' => [$a['t1']->public_id]])->assertStatus(422)->json('errors')['tutors.0'][0]['code'])->toBe('acad.validation.user_not_found');

    $bAssignment2 = $b['free'];
    expect(Tt::postSession($tb, $ab, $a['slots'][1], $bAssignment2)->assertStatus(422)->json('errors.time_slot.0.code'))->toBe('acad.validation.parent_not_found');
    expect(Tt::postSession($tb, $ab, $b['slots'][1], $a['assignment'])->assertStatus(422)->json('errors.teaching_assignment.0.code'))->toBe('acad.validation.parent_not_found');
    expect(Tt::postSession($tb, $ab, $b['slots'][1], $bAssignment2, $a['facility'])->assertStatus(422)->json('errors.facility.0.code'))->toBe('acad.validation.facility_not_found');
    expect(Tt::send($tb, $ab, 'PATCH', "/timetable-sessions/{$b['session']}", ['time_slot' => $a['slots'][1]])->assertStatus(422)->json('errors.time_slot.0.code'))->toBe('acad.validation.parent_not_found');

    // Lo de A sigue intacto.
    expect(Tt::count($a['tenant'], 'teaching_assignments'))->toBe(2)
        ->and(Tt::count($a['tenant'], 'timetable_sessions'))->toBe(1)
        ->and(Tt::count($a['tenant'], 'group_tutors'))->toBe(1)
        ->and(Tt::count($a['tenant'], 'timetable_frames'))->toBe(2);
});
