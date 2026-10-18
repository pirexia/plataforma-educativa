<?php

use App\Models\AuditLog;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTestHelpers;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-002 (1.12-a), docs/modulos/REQ-ACAD/api.md §12, funcional.md §18.4.4,
// §18.9: marcos horarios ordinarios y sus franjas. CA-ACAD-117 a -120,
// CA-ACAD-128 y -129 (curso cerrado), INV-003, INV-010, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ACAD-117 RN-ACAD-110 RN-ACAD-115 INV-003: el alta de un marco ordinario con cinco franjas responde 201 con Location, las franjas ordenadas, crea la fila de timetables y deja entradas created', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-117');
    $level = Api::level($tenant, $admin, $year);

    expect(Tt::count($tenant, 'timetables'))->toBe(0);

    $response = Tt::postFrame($tenant, $admin, Tt::frameBody($level, ['slots' => [
        ['weekday' => 2, 'starts_at' => '08:30', 'ends_at' => '09:25'],
        ['weekday' => 1, 'starts_at' => '09:25', 'ends_at' => '10:20'],
        ['weekday' => 1, 'starts_at' => '08:30', 'ends_at' => '09:25'],
    ]]))->assertCreated();

    expect($response->json('public_id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($response->headers->get('Location'))->toEndWith('/api/v1/timetable-frames/'.$response->json('public_id'))
        ->and($response->json('academic_year'))->toBe($year->public_id)
        ->and($response->json('education_level'))->toBe($level)
        ->and($response->json('frame_type'))->toBe('ordinaria')
        ->and($response->json('name'))->toBe('Horario general')
        ->and($response->json('valid_from'))->toBeNull()
        ->and($response->json('valid_to'))->toBeNull()
        ->and(array_map(fn (array $s): string => "{$s['weekday']} {$s['starts_at']}-{$s['ends_at']}", $response->json('slots')))
        ->toBe(['1 08:30-09:25', '1 09:25-10:20', '2 08:30-09:25'])
        ->and(array_keys($response->json('slots.0')))->toEqualCanonicalizing(['public_id', 'weekday', 'starts_at', 'ends_at'])
        ->and(array_keys($response->json()))->not->toContain('id', 'tenant_id', 'academic_year_id', 'education_level_id', 'created_by', 'deleted_at')
        ->and(Tt::count($tenant, 'timetables'))->toBe(1)
        ->and(Tt::getFrame($tenant, $admin, $response->json('public_id'))->assertOk()->json('slots'))->toBe($response->json('slots'));

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(AuditLog::query()->where('auditable_type', 'timetable_frame')->where('event', 'created')->count())->toBe(1)
            ->and(AuditLog::query()->where('auditable_type', 'time_slot')->where('event', 'created')->count())->toBe(3)
            ->and(AuditLog::query()->where('auditable_type', 'timetable')->where('event', 'created')->count())->toBe(1);
    });
});

test('CA-ACAD-117 RN-ACAD-110 RN-ACAD-91: un segundo ordinaria vivo en la etapa es 422 frame_ordinary_exists (y uno borrado no cuenta); especial es 422 frame_type_invalid en 1.12-a; academic_year en el cuerpo es academic_year_not_allowed; el ancla es una sola', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-117b');
    $level = Api::level($tenant, $admin, $year);
    $otherLevel = Api::level($tenant, $admin, $year, 'BACH');

    $first = Tt::postFrame($tenant, $admin, Tt::frameBody($level))->assertCreated()->json('public_id');

    expect(Tt::errorCode(Tt::postFrame($tenant, $admin, Tt::frameBody($level))->assertStatus(422), 'frame_type'))->toBe('acad.validation.frame_ordinary_exists');

    // Otra etapa: válido, y el ancla del curso sigue siendo una sola (RN-ACAD-115).
    Tt::postFrame($tenant, $admin, Tt::frameBody($otherLevel))->assertCreated();
    expect(Tt::count($tenant, 'timetables'))->toBe(1);

    // Borrar el primero libera el único parcial.
    Tt::deleteFrame($tenant, $admin, $first)->assertNoContent();
    Tt::postFrame($tenant, $admin, Tt::frameBody($level))->assertCreated();

    $level3 = Api::level($tenant, $admin, $year, 'FP');
    expect(Tt::errorCode(Tt::postFrame($tenant, $admin, Tt::frameBody($level3, ['frame_type' => 'especial']))->assertStatus(422), 'frame_type'))->toBe('acad.validation.frame_type_invalid')
        ->and(Tt::errorCode(Tt::postFrame($tenant, $admin, Tt::frameBody($level3, ['frame_type' => 'otra']))->assertStatus(422), 'frame_type'))->toBe('acad.validation.frame_type_invalid')
        ->and(Tt::errorCode(Tt::postFrame($tenant, $admin, array_diff_key(Tt::frameBody($level3), ['frame_type' => 1]))->assertStatus(422), 'frame_type'))->toBe('acad.validation.frame_type_invalid')
        ->and(Tt::errorCode(Tt::postFrame($tenant, $admin, Tt::frameBody($level3, ['academic_year' => $year->public_id]))->assertStatus(422), 'academic_year'))->toBe('acad.validation.academic_year_not_allowed')
        ->and(Tt::errorCode(Tt::postFrame($tenant, $admin, Tt::frameBody($level3, ['valid_from' => '2026-12-01']))->assertStatus(422), 'valid_from'))->toBe('acad.validation.frame_dates_invalid')
        ->and(Tt::errorCode(Tt::postFrame($tenant, $admin, Tt::frameBody('01JA0000000000000000000099'))->assertStatus(422), 'education_level'))->toBe('acad.validation.parent_not_found')
        ->and(Tt::errorCode(Tt::postFrame($tenant, $admin, Tt::frameBody($level3, ['name_translations' => []]))->assertStatus(422), 'name_translations'))->toBe('translations.default_locale_required');

    expect(Tt::count($tenant, 'timetable_frames'))->toBe(2);
});

test('CA-ACAD-118 RN-ACAD-112 INV-010: weekday 8, hora mal formada, fin <= inicio y solape dentro del marco y día son 422 en slots.<n>.<campo>; 08:30-09:25 con 09:25-10:20 es válido (semiabierto)', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-118');
    $level = Api::level($tenant, $admin, $year);
    $post = fn (array $slots) => Tt::postFrame($tenant, $admin, Tt::frameBody($level, ['slots' => $slots]))->assertStatus(422);
    $slot = fn (array $o = []) => $o + ['weekday' => 1, 'starts_at' => '08:30', 'ends_at' => '09:25'];

    expect(Tt::errorCode($post([$slot(['weekday' => 8])]), 'slots.0.weekday'))->toBe('acad.validation.weekday_invalid')
        ->and(Tt::errorCode($post([$slot(['weekday' => 0])]), 'slots.0.weekday'))->toBe('acad.validation.weekday_invalid')
        ->and(Tt::errorCode($post([$slot(['weekday' => '1'])]), 'slots.0.weekday'))->toBe('acad.validation.weekday_invalid')
        ->and(Tt::errorCode($post([$slot(['starts_at' => '8:5'])]), 'slots.0.starts_at'))->toBe('acad.validation.time_invalid')
        ->and(Tt::errorCode($post([$slot(['ends_at' => '24:00'])]), 'slots.0.ends_at'))->toBe('acad.validation.time_invalid')
        ->and(Tt::errorCode($post([$slot(['starts_at' => '08:30:00'])]), 'slots.0.starts_at'))->toBe('acad.validation.time_invalid')
        ->and(Tt::errorCode($post([$slot(['ends_at' => '08:30'])]), 'slots.0.ends_at'))->toBe('acad.validation.slot_ends_before_start')
        ->and(Tt::errorCode($post([$slot(['ends_at' => '08:00'])]), 'slots.0.ends_at'))->toBe('acad.validation.slot_ends_before_start');

    $overlap = $post([$slot(), $slot(['starts_at' => '09:00', 'ends_at' => '09:55'])]);
    expect(Tt::errorCode($overlap, 'slots.1.starts_at'))->toBe('acad.validation.slot_overlap')
        ->and($overlap->json('errors')['slots.1.starts_at'][0]['params'])->toBe(['slot' => 0]);

    // Mismas horas en otro día: sin solape.
    Tt::postFrame($tenant, $admin, Tt::frameBody($level, ['slots' => [
        $slot(), $slot(['starts_at' => '09:25', 'ends_at' => '10:20']), $slot(['weekday' => 2]),
    ]]))->assertCreated();

    expect(Tt::count($tenant, 'timetable_frames'))->toBe(1)->and(Tt::count($tenant, 'time_slots'))->toBe(3);
});

test('CA-ACAD-119 RN-ACAD-113: PATCH con slots que reordena, actualiza una por public_id, añade una y omite otra responde 200 con una updated, una created y una deleted; no escribe lo que no cambia', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-119');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);
    $level = Api::level($tenant, $admin, $year);
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody($level, ['slots' => [
        ['weekday' => 1, 'starts_at' => '08:30', 'ends_at' => '09:25'],
        ['weekday' => 2, 'starts_at' => '08:30', 'ends_at' => '09:25'],
        ['weekday' => 3, 'starts_at' => '08:30', 'ends_at' => '09:25'],
    ]]))->assertCreated()->json();
    [$mon, $tue, $wed] = $frame['slots'];

    $lastAudit = app(TenantContext::class)->runFor($tenant->id, fn (): int => (int) AuditLog::query()->max('id'));

    $response = Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => [
        ['weekday' => 4, 'starts_at' => '10:00', 'ends_at' => '10:55'],                          // alta
        ['public_id' => $tue['public_id'], 'weekday' => 2, 'starts_at' => '08:30', 'ends_at' => '09:25'], // sin cambios
        ['public_id' => $mon['public_id'], 'weekday' => 1, 'starts_at' => '08:45', 'ends_at' => '09:40'], // actualiza
    ]])->assertOk();

    expect(array_map(fn (array $s): string => "{$s['weekday']} {$s['starts_at']}", $response->json('slots')))->toBe(['1 08:45', '2 08:30', '4 10:00'])
        ->and(collect($response->json('slots'))->pluck('public_id')->all())->toContain($mon['public_id'], $tue['public_id'])->not->toContain($wed['public_id']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($lastAudit): void {
        $events = AuditLog::query()->where('id', '>', $lastAudit)->where('auditable_type', 'time_slot')->pluck('event')->sort()->values()->all();

        expect($events)->toBe(['created', 'deleted', 'updated']);
    });

    // Un PATCH idéntico no escribe nada.
    $lastAudit = app(TenantContext::class)->runFor($tenant->id, fn (): int => (int) AuditLog::query()->max('id'));
    Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => $response->json('slots')])->assertOk();
    app(TenantContext::class)->runFor($tenant->id, fn () => expect(AuditLog::query()->where('id', '>', $lastAudit)->count())->toBe(0));

    // name_translations se fusiona y no toca las franjas.
    Tt::patchFrame($tenant, $admin, $frame['public_id'], ['name_translations' => ['en' => 'General timetable']])->assertOk()
        ->assertJsonPath('name_translations', ['es-ES' => 'Horario general', 'en' => 'General timetable'])
        ->assertJsonCount(3, 'slots');
});

test('CA-ACAD-119 RN-ACAD-113: un public_id de franja de otro marco, inexistente o repetido es 422 slot_not_in_frame y nada cambia; 101 franjas es 422 slots_too_many; slots no lista es 422 slots_invalid', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-119b');
    $level = Api::level($tenant, $admin, $year);
    $other = Api::level($tenant, $admin, $year, 'BACH');
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody($level))->assertCreated()->json();
    $foreign = Tt::postFrame($tenant, $admin, Tt::frameBody($other))->assertCreated()->json();
    $own = $frame['slots'][0];

    $patch = fn (array $slots) => Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => $slots])->assertStatus(422);

    expect(Tt::errorCode($patch([$own, $foreign['slots'][0]]), 'slots.1.public_id'))->toBe('acad.validation.slot_not_in_frame')
        ->and(Tt::errorCode($patch([$own, ['public_id' => '01JA0000000000000000000099', 'weekday' => 2, 'starts_at' => '08:30', 'ends_at' => '09:25']]), 'slots.1.public_id'))->toBe('acad.validation.slot_not_in_frame')
        ->and(Tt::errorCode($patch([$own, $own]), 'slots.1.public_id'))->toBe('acad.validation.slot_not_in_frame')
        ->and(Tt::errorCode($patch(array_fill(0, 101, ['weekday' => 1, 'starts_at' => '08:30', 'ends_at' => '09:25'])), 'slots'))->toBe('acad.validation.slots_too_many')
        ->and(Tt::errorCode(Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => 'x'])->assertStatus(422), 'slots'))->toBe('acad.validation.slots_invalid');

    // Nada cambió.
    expect(Tt::getFrame($tenant, $admin, $frame['public_id'])->json('slots'))->toBe($frame['slots']);

    // 100 franjas es el tope válido.
    $hundred = [];

    for ($i = 0; $i < 100; $i++) {
        $hundred[] = ['weekday' => ($i % 7) + 1, 'starts_at' => sprintf('%02d:%02d', 7 + intdiv($i, 7), 0), 'ends_at' => sprintf('%02d:%02d', 7 + intdiv($i, 7), 50)];
    }

    Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => $hundred])->assertOk()->assertJsonCount(100, 'slots');
});

test('CA-ACAD-119 RN-ACAD-91 RN-ACAD-110: PATCH con education_level o academic_year es 422 parent_immutable; con frame_type, frame_type_immutable; con fechas, frame_dates_invalid', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-119c');
    $level = Api::level($tenant, $admin, $year);
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody($level))->assertCreated()->json('public_id');

    expect(Tt::errorCode(Tt::patchFrame($tenant, $admin, $frame, ['education_level' => $level])->assertStatus(422), 'education_level'))->toBe('acad.validation.parent_immutable')
        ->and(Tt::errorCode(Tt::patchFrame($tenant, $admin, $frame, ['academic_year' => $year->public_id])->assertStatus(422), 'academic_year'))->toBe('acad.validation.parent_immutable')
        ->and(Tt::errorCode(Tt::patchFrame($tenant, $admin, $frame, ['frame_type' => 'ordinaria'])->assertStatus(422), 'frame_type'))->toBe('acad.validation.frame_type_immutable')
        ->and(Tt::errorCode(Tt::patchFrame($tenant, $admin, $frame, ['valid_to' => '2027-01-01'])->assertStatus(422), 'valid_to'))->toBe('acad.validation.frame_dates_invalid');
});

test('CA-ACAD-120 RN-ACAD-114: una franja con una sesión viva no se omite ni cambia de hora en el PATCH, y el marco no se borra: 409 has_dependents con timetable_sessions; el resto de franjas sí se edita', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-120');
    $tree = AcadTestHelpers::tree($tenant, $year);
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody($tree['level']->public_id))->assertCreated()->json();
    [$used, $free] = $frame['slots'];
    Tt::session($tenant, $year, $used['public_id'], Tt::assignment($tenant, $year, $tree['group'], $tree['groupSubject'], $admin));

    $dependents = fn ($response) => $response->assertStatus(409)->json('errors.dependents.0.params.dependents');

    // Omitir la franja con sesión.
    expect($dependents(Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => array_slice($frame['slots'], 1)])))->toBe(['timetable_sessions']);

    // Cambiar su hora o su día.
    $moved = array_map(fn (array $s): array => $s['public_id'] === $used['public_id'] ? ['starts_at' => '08:45'] + $s : $s, $frame['slots']);
    expect($dependents(Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => $moved])))->toBe(['timetable_sessions']);
    $moved = array_map(fn (array $s): array => $s['public_id'] === $used['public_id'] ? ['weekday' => 6] + $s : $s, $frame['slots']);
    expect($dependents(Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => $moved])))->toBe(['timetable_sessions']);

    // DELETE del marco.
    expect($dependents(Tt::deleteFrame($tenant, $admin, $frame['public_id'])))->toBe(['timetable_sessions']);

    // Nada cambió; la franja libre sí se puede mover y la usada se conserva sin cambios.
    expect(Tt::getFrame($tenant, $admin, $frame['public_id'])->json('slots'))->toBe($frame['slots']);
    $keepUsed = array_map(fn (array $s): array => $s['public_id'] === $free['public_id'] ? ['starts_at' => '10:00', 'ends_at' => '10:55'] + $s : $s, $frame['slots']);
    Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => $keepUsed])->assertOk();
});

test('RN-ACAD-114: borrar un marco sin sesiones borra también sus franjas (por instancia, con auditoría) y un segundo DELETE responde 404', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-114');
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody(Api::level($tenant, $admin, $year)))->assertCreated()->json('public_id');

    Tt::deleteFrame($tenant, $admin, $frame)->assertNoContent();

    expect(Tt::count($tenant, 'timetable_frames'))->toBe(0)
        ->and(Tt::count($tenant, 'time_slots'))->toBe(0)
        ->and(Tt::count($tenant, 'time_slots', alive: false))->toBe(5);

    app(TenantContext::class)->runFor($tenant->id, fn () => expect(AuditLog::query()->where('auditable_type', 'time_slot')->where('event', 'deleted')->count())->toBe(5));
    Tt::deleteFrame($tenant, $admin, $frame)->assertNotFound();
});

test('CA-ACAD-128 RN-ACAD-90: con el curso cerrado, crear, modificar (con y sin slots) y borrar un marco responde 409 academic-year-closed y nada cambia', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-128');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);
    $level = Api::level($tenant, $admin, $year);
    $other = Api::level($tenant, $admin, $year, 'BACH');
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody($level))->assertCreated()->json();

    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);

    // Con `curso_historico.leer` el administrador lee el curso cerrado.
    foreach ([
        Tt::postFrame($tenant, $admin, Tt::frameBody($other)),
        Tt::patchFrame($tenant, $admin, $frame['public_id'], ['name_translations' => ['en' => 'X']]),
        Tt::patchFrame($tenant, $admin, $frame['public_id'], ['slots' => [['weekday' => 1, 'starts_at' => '08:30', 'ends_at' => '09:25']]]),
        Tt::deleteFrame($tenant, $admin, $frame['public_id']),
    ] as $response) {
        $response->assertStatus(409);
        expect($response->json('type'))->toBe('urn:pge:error:academic-year-closed');
    }

    expect(Tt::count($tenant, 'timetable_frames'))->toBe(1)->and(Tt::count($tenant, 'time_slots'))->toBe(5);
    expect(Tt::getFrame($tenant, $admin, $frame['public_id'])->assertOk()->json('slots'))->toBe($frame['slots']);
});

test('CA-ACAD-129 RN-ACAD-90 RN-CURSO-33: listado y detalle de un marco de un curso cerrado sin curso_historico.leer son 404; con él, los datos', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-129');
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody(Api::level($tenant, $admin, $year)))->assertCreated()->json('public_id');
    $reader = Api::userWith($tenant, $admin, 'solo129', ['horarios.leer']);
    $historian = Api::userWith($tenant, $admin, 'hist129', ['horarios.leer', 'curso_historico.leer']);

    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);

    foreach (["/timetable-frames/{$frame}", '/timetable-frames?academic_year='.$year->public_id] as $path) {
        resetSessionState();
        test()->actingAs($reader)->getJson(Api::url($tenant, $path))->assertNotFound();
        resetSessionState();
        test()->actingAs($historian)->getJson(Api::url($tenant, $path))->assertOk();
    }
});

test('CA-ACAD-132 INV-001: un marco de otro centro es 404 en detalle, PATCH y DELETE; una etapa de otro centro en el cuerpo es 422 parent_not_found y nunca aparece en el listado', function (): void {
    [$tenantA, $adminA, $yearA] = Api::setup('frm-132a');
    [$tenantB, $adminB, $yearB] = Api::setup('frm-132b');
    $levelA = Api::level($tenantA, $adminA, $yearA);
    $levelB = Api::level($tenantB, $adminB, $yearB);
    $foreign = Tt::postFrame($tenantB, $adminB, Tt::frameBody($levelB))->assertCreated()->json('public_id');
    $own = Tt::postFrame($tenantA, $adminA, Tt::frameBody($levelA))->assertCreated()->json('public_id');

    Tt::getFrame($tenantA, $adminA, $foreign)->assertNotFound();
    Tt::patchFrame($tenantA, $adminA, $foreign, ['name_translations' => ['en' => 'X']])->assertNotFound();
    Tt::deleteFrame($tenantA, $adminA, $foreign)->assertNotFound();
    expect(Tt::errorCode(Tt::postFrame($tenantA, $adminA, Tt::frameBody($levelB))->assertStatus(422), 'education_level'))->toBe('acad.validation.parent_not_found');

    resetSessionState();
    $list = test()->actingAs($adminA)->getJson(Api::url($tenantA, '/timetable-frames'))->assertOk();
    expect(array_column($list->json('data'), 'public_id'))->toBe([$own])
        ->and(Tt::count($tenantB, 'timetable_frames'))->toBe(1);
});

test('api.md §12.4: GET /timetable-frames filtra por education_level y frame_type, ordena por la posición de la etapa y un sort ajeno es 422', function (): void {
    [$tenant, $admin, $year] = Api::setup('frm-list');
    $eso = Api::level($tenant, $admin, $year);
    $bach = Api::level($tenant, $admin, $year, 'BACH');
    Tt::postFrame($tenant, $admin, Tt::frameBody($bach))->assertCreated();
    Tt::postFrame($tenant, $admin, Tt::frameBody($eso))->assertCreated();

    $get = function (string $query) use ($tenant, $admin) {
        resetSessionState();

        return test()->actingAs($admin)->getJson(Api::url($tenant, '/timetable-frames'.$query));
    };

    expect(array_column($get('')->assertOk()->json('data'), 'education_level'))->toBe([$eso, $bach])
        ->and(array_column($get('?sort=-education_level')->assertOk()->json('data'), 'education_level'))->toBe([$bach, $eso])
        ->and(array_column($get("?education_level={$bach}")->assertOk()->json('data'), 'education_level'))->toBe([$bach])
        ->and($get('?education_level=01JA0000000000000000000099')->assertOk()->json('data'))->toBe([])
        ->and($get('?frame_type=ordinaria')->assertOk()->json('meta.total'))->toBe(2);

    $get('?frame_type=especial,otra')->assertStatus(422);
    $get('?sort=name')->assertStatus(422);
});
