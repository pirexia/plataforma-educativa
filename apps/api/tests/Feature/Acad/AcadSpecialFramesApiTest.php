<?php

use App\Models\AuditLog;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-002 (1.12-b), docs/modulos/REQ-ACAD/api.md §12, funcional.md §18.4.4
// (RN-ACAD-111), §18.4.5 (RN-ACAD-124, -125), §18.9: marcos horarios ESPECIALES
// (jornada intensiva) y su efecto en los conflictos de sesiones. CA-ACAD-136 y
// CA-ACAD-137; CA-ACAD-128 (curso cerrado), INV-003, INV-010, INV-015. El año
// de los tests va del 2026-09-01 al 2027-06-30.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Cuerpo de un marco especial con una franja el lunes (por defecto 08:30-09:25). */
function spBody(string $level, string $from, string $to, array $overrides = []): array
{
    return Tt::frameBody($level, $overrides + [
        'frame_type' => 'especial',
        'name_translations' => ['es-ES' => 'Jornada intensiva'],
        'valid_from' => $from,
        'valid_to' => $to,
        'slots' => [['weekday' => 1, 'starts_at' => '08:30', 'ends_at' => '09:25']],
    ]);
}

/** Código del primer error de un campo. */
function spCode($response, string $field): ?string
{
    return Tt::errorCode($response->assertStatus(422), $field);
}

/**
 * Otra etapa con su grupo y su asignatura asignada.
 *
 * @return array<string, string>
 */
function spLevel(array $s, string $suffix): array
{
    $tree = Api::tree($s['tenant'], $s['admin'], $s['year'], $suffix);
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$tree['group']}/subjects", ['subjects' => [$tree['subject']]])->assertOk();

    return $tree;
}

test('CA-ACAD-136 RN-ACAD-111 RN-ACAD-110 INV-003: un marco especial con fechas dentro del curso es 201 con valid_from, valid_to y franjas, convive con el ordinario de su etapa y deja entradas created', function (): void {
    $s = Tt::scenario('sp-136a');
    $t = $s['tenant'];
    $a = $s['admin'];

    $r = Tt::postFrame($t, $a, spBody($s['api']['level'], '2027-06-01', '2027-06-20'))->assertCreated();

    expect($r->json('frame_type'))->toBe('especial')
        ->and($r->json('valid_from'))->toBe('2027-06-01')
        ->and($r->json('valid_to'))->toBe('2027-06-20')
        ->and($r->json('name'))->toBe('Jornada intensiva')
        ->and($r->json('slots'))->toHaveCount(1)
        ->and($r->json('education_level'))->toBe($s['api']['level'])
        ->and(Tt::count($t, 'timetable_frames'))->toBe(2)
        ->and(Tt::getFrame($t, $a, $r->json('public_id'))->assertOk()->json('valid_to'))->toBe('2027-06-20');

    // Un único día es válido (valid_to ≥ valid_from) y los extremos del curso también.
    Tt::postFrame($t, $a, spBody($s['api']['level'], '2026-09-01', '2026-09-01'))->assertCreated();
    Tt::postFrame($t, $a, spBody($s['api']['level'], '2027-06-30', '2027-06-30'))->assertCreated();

    app(TenantContext::class)->runFor($t->id, function (): void {
        expect(AuditLog::query()->where('auditable_type', 'timetable_frame')->where('event', 'created')->count())->toBe(4)
            ->and(AuditLog::query()->where('auditable_type', 'time_slot')->where('event', 'created')->count())->toBe(8);
    });

    // El filtro por tipo y el orden del listado.
    $list = Tt::send($t, $a, 'GET', '/timetable-frames?frame_type=especial')->assertOk()->json('data');
    expect($list)->toHaveCount(3)->and(array_unique(array_column($list, 'frame_type')))->toBe(['especial']);
});

test('CA-ACAD-136 RN-ACAD-111: fechas fuera del curso son 422 frame_outside_academic_year con las del curso; valid_to < valid_from, frame_dates_invalid; fechas ausentes, frame_dates_invalid; con formato inválido, date_invalid; un ordinario con fechas, frame_dates_invalid', function (): void {
    $s = Tt::scenario('sp-136b');
    $t = $s['tenant'];
    $a = $s['admin'];
    $level = $s['api']['level'];

    $before = spCode(Tt::postFrame($t, $a, spBody($level, '2026-08-31', '2026-12-01')), 'valid_from');
    $r = Tt::postFrame($t, $a, spBody($level, '2026-08-31', '2027-07-01'))->assertStatus(422);

    expect($before)->toBe('acad.validation.frame_outside_academic_year')
        ->and(Tt::errorCode($r, 'valid_from'))->toBe('acad.validation.frame_outside_academic_year')
        ->and(Tt::errorCode($r, 'valid_to'))->toBe('acad.validation.frame_outside_academic_year')
        ->and($r->json('errors.valid_to.0.params'))->toBe(['academic_year_starts_on' => '2026-09-01', 'academic_year_ends_on' => '2027-06-30'])
        ->and(spCode(Tt::postFrame($t, $a, spBody($level, '2027-01-01', '2027-07-01')), 'valid_to'))->toBe('acad.validation.frame_outside_academic_year')
        ->and(spCode(Tt::postFrame($t, $a, spBody($level, '2027-02-10', '2027-02-09')), 'valid_to'))->toBe('acad.validation.frame_dates_invalid')
        ->and(spCode(Tt::postFrame($t, $a, spBody($level, '2027-02-31', '2027-03-01')), 'valid_from'))->toBe('acad.validation.date_invalid')
        ->and(spCode(Tt::postFrame($t, $a, spBody($level, '2027-02-01', '01/03/2027')), 'valid_to'))->toBe('acad.validation.date_invalid');

    $missing = Tt::postFrame($t, $a, array_diff_key(spBody($level, '2027-02-01', '2027-02-02'), ['valid_from' => 1, 'valid_to' => 1]))->assertStatus(422);
    $nulls = Tt::postFrame($t, $a, spBody($level, '2027-02-01', '2027-02-02', ['valid_from' => null, 'valid_to' => null]))->assertStatus(422);

    expect(Tt::errorCode($missing, 'valid_from'))->toBe('acad.validation.frame_dates_invalid')
        ->and(Tt::errorCode($missing, 'valid_to'))->toBe('acad.validation.frame_dates_invalid')
        ->and(Tt::errorCode($nulls, 'valid_from'))->toBe('acad.validation.frame_dates_invalid');

    // Un ordinario no lleva fechas; frame_type fuera de vocabulario sigue siendo frame_type_invalid.
    $other = Api::tree($t, $a, $s['year'], 'Q');

    expect(spCode(Tt::postFrame($t, $a, Tt::frameBody($other['level'], ['valid_to' => '2027-01-01'])), 'valid_to'))->toBe('acad.validation.frame_dates_invalid')
        ->and(spCode(Tt::postFrame($t, $a, spBody($other['level'], '2027-02-01', '2027-02-02', ['frame_type' => 'otra'])), 'frame_type'))->toBe('acad.validation.frame_type_invalid')
        ->and(spCode(Tt::postFrame($t, $a, spBody($other['level'], '2027-02-01', '2027-02-02', ['academic_year' => $s['year']->public_id])), 'academic_year'))->toBe('acad.validation.academic_year_not_allowed')
        ->and(Tt::count($t, 'timetable_frames'))->toBe(1);
});

test('CA-ACAD-136 RN-ACAD-111: un especial solapado con otro vivo de la misma etapa es 422 frame_overlap con el marco (compartir un día solapa); contiguo, de otra etapa o con el otro borrado, 201', function (): void {
    $s = Tt::scenario('sp-136c');
    $t = $s['tenant'];
    $a = $s['admin'];
    $level = $s['api']['level'];
    $other = spLevel($s, 'B');

    $first = Tt::postFrame($t, $a, spBody($level, '2027-06-01', '2027-06-20'))->assertCreated()->json('public_id');

    $r = Tt::postFrame($t, $a, spBody($level, '2027-06-20', '2027-06-25'))->assertStatus(422);
    expect(Tt::errorCode($r, 'valid_from'))->toBe('acad.validation.frame_overlap')
        ->and($r->json('errors.valid_from.0.params.frame'))->toBe($first)
        ->and(spCode(Tt::postFrame($t, $a, spBody($level, '2027-05-25', '2027-06-01')), 'valid_from'))->toBe('acad.validation.frame_overlap')
        ->and(spCode(Tt::postFrame($t, $a, spBody($level, '2027-06-05', '2027-06-06')), 'valid_from'))->toBe('acad.validation.frame_overlap')
        ->and(spCode(Tt::postFrame($t, $a, spBody($level, '2027-05-01', '2027-06-30')), 'valid_from'))->toBe('acad.validation.frame_overlap')
        ->and(Tt::count($t, 'timetable_frames'))->toBe(2);

    Tt::postFrame($t, $a, spBody($level, '2027-06-21', '2027-06-25'))->assertCreated();
    Tt::postFrame($t, $a, spBody($level, '2027-05-25', '2027-05-31'))->assertCreated();
    Tt::postFrame($t, $a, spBody($other['level'], '2027-06-01', '2027-06-20'))->assertCreated();

    // Con el primero borrado ya no cuenta.
    Tt::deleteFrame($t, $a, $first)->assertNoContent();
    Tt::postFrame($t, $a, spBody($level, '2027-06-01', '2027-06-20'))->assertCreated();
});

test('CA-ACAD-136 RN-ACAD-111 INV-003: PATCH de las fechas de un especial cambia la vigencia (updated), con las mismas validaciones que el alta; null o un ordinario con fechas es frame_dates_invalid', function (): void {
    $s = Tt::scenario('sp-136d');
    $t = $s['tenant'];
    $a = $s['admin'];
    $level = $s['api']['level'];

    $one = Tt::postFrame($t, $a, spBody($level, '2027-06-01', '2027-06-10'))->assertCreated()->json('public_id');
    Tt::postFrame($t, $a, spBody($level, '2027-06-20', '2027-06-30'))->assertCreated();

    $r = Tt::patchFrame($t, $a, $one, ['valid_to' => '2027-06-15'])->assertOk();
    expect($r->json('valid_from'))->toBe('2027-06-01')->and($r->json('valid_to'))->toBe('2027-06-15');
    app(TenantContext::class)->runFor($t->id, fn () => expect(AuditLog::query()->where('auditable_type', 'timetable_frame')->where('auditable_id', DB::table('timetable_frames')->where('public_id', $one)->value('id'))->where('event', 'updated')->count())->toBe(1));

    // Moverse dentro de su propio rango no choca consigo mismo; ensancharlo hasta el vecino, sí.
    Tt::patchFrame($t, $a, $one, ['valid_from' => '2027-06-02', 'valid_to' => '2027-06-19'])->assertOk();
    expect(spCode(Tt::patchFrame($t, $a, $one, ['valid_to' => '2027-06-20']), 'valid_from'))->toBe('acad.validation.frame_overlap')
        ->and(spCode(Tt::patchFrame($t, $a, $one, ['valid_to' => null]), 'valid_to'))->toBe('acad.validation.frame_dates_invalid')
        ->and(spCode(Tt::patchFrame($t, $a, $one, ['valid_from' => '2027-06-19', 'valid_to' => '2027-06-18']), 'valid_to'))->toBe('acad.validation.frame_dates_invalid')
        ->and(spCode(Tt::patchFrame($t, $a, $one, ['valid_to' => '2027-07-01']), 'valid_to'))->toBe('acad.validation.frame_outside_academic_year')
        ->and(spCode(Tt::patchFrame($t, $a, $one, ['valid_from' => '2026-08-31']), 'valid_from'))->toBe('acad.validation.frame_outside_academic_year')
        ->and(spCode(Tt::patchFrame($t, $a, $one, ['valid_from' => 'mañana']), 'valid_from'))->toBe('acad.validation.date_invalid');

    // Solo el nombre: la vigencia no cambia. Y el tipo sigue siendo inmutable.
    $named = Tt::patchFrame($t, $a, $one, ['name_translations' => ['es-ES' => 'Jornada de junio']])->assertOk();
    expect($named->json('valid_from'))->toBe('2027-06-02')->and($named->json('valid_to'))->toBe('2027-06-19')
        ->and(spCode(Tt::patchFrame($t, $a, $one, ['frame_type' => 'ordinaria']), 'frame_type'))->toBe('acad.validation.frame_type_immutable');

    // El ordinario: fechas nulas no cambian nada; una fecha no nula es frame_dates_invalid.
    Tt::patchFrame($t, $a, $s['frame']['public_id'], ['valid_from' => null, 'valid_to' => null])->assertOk()->assertJsonPath('valid_from', null);
    expect(spCode(Tt::patchFrame($t, $a, $s['frame']['public_id'], ['valid_from' => '2027-01-01']), 'valid_from'))->toBe('acad.validation.frame_dates_invalid');
});

/**
 * Escenario de coexistencia: profesores t1, t2, t3; ESO (etapa A, con su ordinario y los grupos A, A2 y A3) y otra
 * etapa B con grupo propio. Devuelve las asignaciones por profesor y etapa.
 */
function spCoexistence(string $slug): array
{
    $s = Tt::scenario($slug);
    $t = $s['tenant'];
    $a = $s['admin'];

    foreach (['groupA2' => 'A2', 'groupA3' => 'A3'] as $key => $code) {
        $s[$key] = Tt::send($t, $a, 'POST', '/groups', ['course' => $s['api']['course'], 'code' => $code, 'name' => "Grupo {$code}"])->assertCreated()->json('public_id');
        Tt::send($t, $a, 'PUT', "/groups/{$s[$key]}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    }

    $s['b'] = spLevel($s, 'B');
    $s['t3'] = Api::userWith($t, $a, 'prof3', ['horarios.leer']);

    return $s;
}

test('CA-ACAD-137 RN-ACAD-124 §18.5: el mismo profesor a la misma hora en el ordinario y en el especial de SU etapa es 201 (el especial sustituye al ordinario); en el especial de otra etapa y el ordinario de ESO, 422 teacher', function (): void {
    $s = spCoexistence('sp-137a');
    $t = $s['tenant'];
    $a = $s['admin'];
    $monday = $s['slots'][0];

    // Mismo profesor, misma etapa: lunes 08:30 en el ordinario (grupo A) y lunes 08:30 en el especial (grupo A2).
    $special = Tt::postFrame($t, $a, spBody($s['api']['level'], '2026-12-01', '2026-12-11'))->assertCreated()->json();
    Tt::postSession($t, $a, $monday, Tt::postAssignment($t, $a, $s['t1'], $s['api']['group'], $s['api']['subject'])->json('public_id'))->assertCreated()->json('public_id');
    $inSpecial = Tt::postSession($t, $a, $special['slots'][0]['public_id'], Tt::postAssignment($t, $a, $s['t1'], $s['groupA2'], $s['api']['subject'])->json('public_id'))->assertCreated();
    expect($inSpecial->json('frame'))->toBe($special['public_id']);

    // Otra etapa (B), jornada especial: el profesor t2 con ESO ordinario y, a la hora que se corta, en el especial de B.
    $specialB = Tt::postFrame($t, $a, spBody($s['b']['level'], '2026-12-01', '2026-12-11', ['slots' => [['weekday' => 1, 'starts_at' => '08:45', 'ends_at' => '09:30']]]))->assertCreated()->json();
    $t2Eso = Tt::postAssignment($t, $a, $s['t2'], $s['groupA2'], $s['api']['subject'])->json('public_id');
    $t2B = Tt::postAssignment($t, $a, $s['t2'], $s['b']['group'], $s['b']['subject'])->json('public_id');
    $esoSession = Tt::postSession($t, $a, $monday, $t2Eso)->assertCreated()->json('public_id');

    $r = Tt::postSession($t, $a, $specialB['slots'][0]['public_id'], $t2B)->assertStatus(422);
    expect($r->json('errors.time_slot.0.code'))->toBe('acad.validation.timetable_conflict')
        ->and($r->json('errors.time_slot.0.params.conflicts'))->toBe([['kind' => 'teacher', 'session' => $esoSession]]);

    // El caso simétrico: primero la sesión del especial de B, luego la de ESO a una hora que se corta.
    $t3B = Tt::postAssignment($t, $a, $s['t3'], $s['b']['group'], $s['b']['subject'])->json('public_id');
    $t3Eso = Tt::postAssignment($t, $a, $s['t3'], $s['groupA3'], $s['api']['subject'])->json('public_id');
    $bSession = Tt::postSession($t, $a, $specialB['slots'][0]['public_id'], $t3B)->assertCreated()->json('public_id');
    $r = Tt::postSession($t, $a, $monday, $t3Eso)->assertStatus(422);
    expect($r->json('errors.time_slot.0.params.conflicts'))->toBe([['kind' => 'teacher', 'session' => $bSession]]);
});

test('CA-ACAD-137 RN-ACAD-124: dos especiales de etapas distintas coexisten solo si sus fechas se cortan: 422 si se cortan, 201 si no; y el aula y el grupo siguen las mismas reglas', function (): void {
    $s = spCoexistence('sp-137b');
    $t = $s['tenant'];
    $a = $s['admin'];
    $c = spLevel($s, 'C');

    $specialA = Tt::postFrame($t, $a, spBody($s['api']['level'], '2026-12-01', '2026-12-11'))->assertCreated()->json();
    $specialB = Tt::postFrame($t, $a, spBody($s['b']['level'], '2026-12-08', '2026-12-18', ['slots' => [['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '09:55']]]))->assertCreated()->json();
    $specialBLater = Tt::postFrame($t, $a, spBody($s['b']['level'], '2026-12-19', '2026-12-31', ['slots' => [['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '09:55']]]))->assertCreated()->json();
    $specialC = Tt::postFrame($t, $a, spBody($c['level'], '2026-12-10', '2026-12-12', ['slots' => [['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '09:55']]]))->assertCreated()->json();

    $inA = Tt::postSession($t, $a, $specialA['slots'][0]['public_id'], Tt::postAssignment($t, $a, $s['t1'], $s['api']['group'], $s['api']['subject'])->json('public_id'), $s['facility'])->assertCreated()->json('public_id');
    $b1 = Tt::postAssignment($t, $a, $s['t1'], $s['b']['group'], $s['b']['subject'])->json('public_id');

    // Fechas que se cortan (8-11 dic): profesor t1 en A (8:30-9:25) y en B (9:00-9:55) ⇒ 422.
    expect(Tt::postSession($t, $a, $specialB['slots'][0]['public_id'], $b1)->assertStatus(422)->json('errors.time_slot.0.params.conflicts'))->toBe([['kind' => 'teacher', 'session' => $inA]]);

    // Fechas que no se cortan (el especial de B del 19-31 dic) ⇒ 201.
    Tt::postSession($t, $a, $specialBLater['slots'][0]['public_id'], $b1)->assertCreated();

    // La misma aula a la hora que se corta, en C (10-12 dic) y en A (1-11 dic), con profesores distintos ⇒ 422 facility.
    $cAssignment = Tt::postAssignment($t, $a, $s['t2'], $c['group'], $c['subject'])->json('public_id');
    expect(Tt::postSession($t, $a, $specialC['slots'][0]['public_id'], $cAssignment, $s['facility'])->assertStatus(422)->json('errors.time_slot.0.params.conflicts'))->toBe([['kind' => 'facility', 'session' => $inA]]);
    Tt::postSession($t, $a, $specialC['slots'][0]['public_id'], $cAssignment)->assertCreated();
});

test('CA-ACAD-137 RN-ACAD-125: el cambio de fechas de un especial con sesiones se rechaza con 422 timetable_conflict en valid_from si hace coexistir sesiones en conflicto; si no, se aplica', function (): void {
    $s = spCoexistence('sp-137c');
    $t = $s['tenant'];
    $a = $s['admin'];
    $c = spLevel($s, 'C');
    $slot = fn (string $from, string $to) => [['weekday' => 1, 'starts_at' => $from, 'ends_at' => $to]];

    $frameB = Tt::postFrame($t, $a, spBody($s['b']['level'], '2026-12-01', '2026-12-10', ['slots' => $slot('09:00', '09:55')]))->assertCreated()->json();
    $frameC = Tt::postFrame($t, $a, spBody($c['level'], '2026-12-20', '2026-12-31', ['slots' => $slot('09:30', '10:25')]))->assertCreated()->json();
    $inB = Tt::postSession($t, $a, $frameB['slots'][0]['public_id'], Tt::postAssignment($t, $a, $s['t1'], $s['b']['group'], $s['b']['subject'])->json('public_id'))->assertCreated()->json('public_id');
    Tt::postSession($t, $a, $frameC['slots'][0]['public_id'], Tt::postAssignment($t, $a, $s['t1'], $c['group'], $c['subject'])->json('public_id'))->assertCreated();

    $r = Tt::patchFrame($t, $a, $frameC['public_id'], ['valid_from' => '2026-12-05'])->assertStatus(422);
    expect(Tt::errorCode($r, 'valid_from'))->toBe('acad.validation.timetable_conflict')
        ->and($r->json('errors.valid_from.0.params.conflicts'))->toBe([['kind' => 'teacher', 'session' => $inB]])
        ->and(Tt::getFrame($t, $a, $frameC['public_id'])->json('valid_from'))->toBe('2026-12-20');

    Tt::patchFrame($t, $a, $frameC['public_id'], ['valid_from' => '2026-12-11'])->assertOk()->assertJsonPath('valid_from', '2026-12-11');

    // También al estirar el otro: el especial de B hasta el 15 dic corta con el de C (desde el 11).
    expect(Tt::errorCode(Tt::patchFrame($t, $a, $frameB['public_id'], ['valid_to' => '2026-12-15'])->assertStatus(422), 'valid_from'))->toBe('acad.validation.timetable_conflict');
    Tt::patchFrame($t, $a, $frameB['public_id'], ['valid_to' => '2026-12-10', 'name_translations' => ['es-ES' => 'Renombrado']])->assertOk();
});

test('CA-ACAD-128 RN-ACAD-90 RN-ACAD-111: con el curso cerrado, crear o cambiar las fechas de un especial es 409 academic-year-closed y nada cambia', function (): void {
    $s = Tt::scenario('sp-128');
    $t = $s['tenant'];
    $a = $s['admin'];
    $special = Tt::postFrame($t, $a, spBody($s['api']['level'], '2027-06-01', '2027-06-10'))->assertCreated()->json('public_id');

    CursoTestHelpers::setStatus($t, $s['year'], AcademicYearStatus::Cerrado);

    foreach ([
        Tt::postFrame($t, $a, spBody($s['api']['level'], '2027-06-20', '2027-06-25')),
        Tt::patchFrame($t, $a, $special, ['valid_to' => '2027-06-12']),
        Tt::patchFrame($t, $a, $special, ['name_translations' => ['es-ES' => 'Otro']]),
    ] as $response) {
        $response->assertStatus(409);
        expect($response->json('type'))->toBe('urn:pge:error:academic-year-closed');
    }

    expect(Tt::count($t, 'timetable_frames'))->toBe(2)
        ->and(Tt::getFrame($t, $a, $special)->assertOk()->json('valid_to'))->toBe('2027-06-10');
});
