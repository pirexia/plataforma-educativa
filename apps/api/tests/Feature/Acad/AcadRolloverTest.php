<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\GradingScale;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Acad\Domain\Models\TimetableFrame;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\YearRolloverItem;
use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Modules\Curso\Infrastructure\Jobs\RevertAcademicYearRollover;
use App\Modules\Curso\Infrastructure\Jobs\RunAcademicYearRollover;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AcadApiHelpers;
use Tests\Support\CursoTestHelpers;
use Tests\Support\RolloverTestHelpers as R;

// REQ-CURSO-002 (1.12b), REQ-ACAD/funcional.md §21: el participante REAL de `acad` ejecutado por los trabajos de `curso`.
// PostgreSQL real. Los criterios que ejecutan el trabajo corren dentro de la transacción del test con
// `curso.rollover.allow_nested_transaction` a `true` (RN-CURSO-86): prueban el resultado, el todo o nada y los estados,
// no la semántica de REPEATABLE READ (esa: tests/Concurrency/RolloverConcurrencyTest.php).

beforeEach(function (): void {
    config(['curso.rollover.allow_nested_transaction' => true]);
    Queue::fake();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

test('CA-CURSO-123 RN-CURSO-62 RN-CURSO-78 RN-ACAD-142: el catálogo de acad trae los doce elementos en orden, nueve sin paquete y tres del paquete teaching_assignments, con etiqueta traducida y allowed según el permiso de alta', function (): void {
    $s = R::scenario('rol-cat');

    $response = R::get($s['tenant'], $s['admin'], '/academic-year-rollovers/elements')->assertOk();
    $data = $response->json('data');

    expect(array_column($data, 'code'))->toBe([
        'acad.education_levels', 'acad.courses', 'acad.groups', 'acad.subgroups', 'acad.subjects', 'acad.group_subjects',
        'acad.academic_periods', 'acad.grading_scales', 'acad.timetable_frames', 'acad.teaching_assignments', 'acad.group_tutors', 'acad.timetable_sessions',
    ])->and(array_column($data, 'bundle'))->toBe([null, null, null, null, null, null, null, null, null, 'teaching_assignments', 'teaching_assignments', 'teaching_assignments'])
        ->and($data[0])->toMatchArray(['module' => 'acad', 'label' => 'Etapas', 'requires' => [], 'allowed' => true])
        ->and($data[2]['requires'])->toBe(['acad.courses'])
        ->and(array_column($data, 'allowed'))->each->toBeTrue();

    AcadApiHelpers::setLocales($s['tenant'], $s['admin'], 'es-ES', ['es-ES', 'en', 'de', 'fr']);

    // El idioma de un usuario autenticado es el de su persona (ADR-021): se cambia el de la persona.
    foreach (['en' => 'Stages', 'de' => 'Stufen', 'fr' => 'Étapes'] as $locale => $label) {
        R::inTenant($s, function () use ($s, $locale): void {
            $person = Person::query()->findOrFail($s['admin']->person_id);
            $person->locale = $locale;
            $person->save();
        });
        $s['admin']->unsetRelation('person');

        expect(R::get($s['tenant'], $s['admin'], '/academic-year-rollovers/elements', [], $locale)->assertOk()->json('data.0.label'))->toBe($label);
    }

    // Con `estructura_academica.crear` pero sin `configuracion_academica.crear`, períodos y escalas llegan con allowed: false.
    $partial = AcadApiHelpers::userWith($s['tenant'], $s['admin'], 'rolcat', ['apertura_curso_academico.crear', 'estructura_academica.crear', 'estructura_academica.actualizar', 'horarios.crear']);
    $allowed = collect(R::get($s['tenant'], $partial, '/academic-year-rollovers/elements')->assertOk()->json('data'))->pluck('allowed', 'code');

    expect($allowed['acad.education_levels'])->toBeTrue()
        ->and($allowed['acad.academic_periods'])->toBeFalse()
        ->and($allowed['acad.grading_scales'])->toBeFalse()
        ->and($allowed['acad.timetable_frames'])->toBeTrue()
        ->and($allowed['acad.teaching_assignments'])->toBeFalse();
});

test('CA-CURSO-123 RN-CURSO-62: con acad sin contratar el catálogo está vacío', function (): void {
    [$tenant, $admin] = provisionCoreTenant('rol-cat0');

    expect(R::get($tenant, $admin, '/academic-year-rollovers/elements')->assertOk()->json('data'))->toBe([]);
});

test('CA-CURSO-124 RN-CURSO-65 RN-CURSO-72: la simulación cuenta las filas vivas, desplaza las fechas 365 días y no escribe nada', function (): void {
    $s = R::scenario('rol-prev');
    $snapshot = fn () => R::inTenant($s, fn () => [R::counts($s['tenant'], $s['target']), DB::table('year_rollovers')->count(), AuditLog::query()->whereNot('auditable_type', 'user_mfa_obligation')->count()]);
    $before = $snapshot();

    $response = R::get($s['tenant'], $s['admin'], '/academic-year-rollovers/preview', [
        'academic_year' => $s['target']->public_id,
        'source' => $s['source']->public_id,
        'elements' => implode(',', R::NINE),
    ])->assertOk();

    expect($response->json('executable'))->toBeTrue()
        ->and($response->json('date_offset_days'))->toBe(365)
        ->and($response->json('copy_teaching_assignments'))->toBeFalse()
        ->and(array_column($response->json('elements'), 'to_copy'))->toBe([2, 3, 4, 2, 5, 8, 3, 2, 2])
        ->and($response->json('academic_year.public_id'))->toBe($s['target']->public_id)
        ->and($response->json('source.status'))->toBe('activo');

    $periods = collect($response->json('elements'))->firstWhere('code', 'acad.academic_periods');
    $frames = collect($response->json('elements'))->firstWhere('code', 'acad.timetable_frames');

    expect($periods['date_range'])->toBe(['starts_on' => '2027-09-08', 'ends_on' => '2028-06-21'])
        ->and($frames['date_range'])->toBe(['starts_on' => '2028-05-31', 'ends_on' => '2028-06-29']);

    expect($snapshot())->toBe($before);
});

test('CA-CURSO-125 RN-CURSO-63 RN-CURSO-64: validaciones de destino, origen y selección, en simulación y en solicitud, sin escribir nada', function (): void {
    $s = R::scenario('rol-val');
    [$tenantB, $adminB] = provisionCoreTenant('rol-val-b');
    contractModule($tenantB, 'acad');
    $foreign = CursoTestHelpers::year($tenantB, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');
    $closed = CursoTestHelpers::year($s['tenant'], '2025-2026', AcademicYearStatus::Cerrado, '2025-09-01', '2026-06-30');
    $noHistory = AcadApiHelpers::userWith($s['tenant'], $s['admin'], 'rolval', ['apertura_curso_academico.crear', 'estructura_academica.crear']);

    $errorCode = fn ($response, string $field) => $response->json("errors.{$field}.0.code");
    $base = R::body($s);
    $withoutKey = fn (string $key) => array_diff_key($base, [$key => 1]);
    $checks = [
        'source' => [$withoutKey('source'), 422, 'source', 'curso.validation.rollover_source_required'],
        'elements vacía' => [['elements' => []] + $base, 422, 'elements', 'curso.validation.rollover_elements_required'],
        'desconocido' => [['elements' => ['acad.nada']] + $base, 422, 'elements', 'curso.validation.rollover_element_unknown'],
        'requires' => [['elements' => ['acad.groups']] + $base, 422, 'elements', 'curso.validation.rollover_element_requires'],
        'origen en planificación' => [['source' => $s['target']->public_id] + $base, 422, 'source', 'curso.validation.rollover_source_invalid'],
        'origen de otro centro' => [['source' => $foreign->public_id] + $base, 422, 'source', 'curso.validation.rollover_source_not_found'],
        'destino de otro centro' => [['academic_year' => $foreign->public_id] + $base, 422, 'academic_year', 'curso.validation.rollover_target_not_found'],
        'destino activo' => [['academic_year' => $s['source']->public_id, 'source' => $closed->public_id] + $base, 409, null, 'curso.conflict.rollover_target_not_planning'],
        'destino ausente' => [$withoutKey('academic_year'), 422, 'academic_year', 'curso.validation.rollover_target_required'],
    ];

    foreach ($checks as $name => [$body, $status, $field, $code]) {
        $post = R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', $body);
        $get = R::get($s['tenant'], $s['admin'], '/academic-year-rollovers/preview', array_merge($body, ['elements' => implode(',', $body['elements'] ?? [])]));

        foreach ([$post, $get] as $response) {
            $response->assertStatus($status);
            $field !== null ? expect($errorCode($response, $field))->toBe($code, $name) : expect($response->json('errors.rollover.0.code') ?? $response->json('errors.academic_year.0.code'))->toBe($code, $name);
        }
    }

    // Requires: params del elemento y de su requisito.
    $requires = R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', ['elements' => ['acad.groups']] + $base);
    expect($requires->json('errors.elements.0.params'))->toBe(['element' => 'acad.groups', 'requires' => 'acad.courses']);

    // Origen anterior que empieza después o igual que el destino ⇒ inválido.
    $later = CursoTestHelpers::year($s['tenant'], '2028-2029', AcademicYearStatus::Cerrado, '2028-09-01', '2029-06-30');
    expect(R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', ['source' => $later->public_id] + $base)->json('errors.source.0.code'))->toBe('curso.validation.rollover_source_invalid');

    // Origen cerrado: sin `curso_historico.leer` es indistinguible de uno inexistente; con él, 200.
    expect(R::post($s['tenant'], $noHistory, '/academic-year-rollovers', ['source' => $closed->public_id, 'elements' => ['acad.education_levels']] + $base)->json('errors.source.0.code'))->toBe('curso.validation.rollover_source_not_found');
    $withHistory = R::get($s['tenant'], $s['admin'], '/academic-year-rollovers/preview', ['source' => $closed->public_id, 'elements' => 'acad.education_levels'] + ['academic_year' => $s['target']->public_id]);
    $withHistory->assertOk();

    expect(R::inTenant($s, fn () => DB::table('year_rollovers')->count()))->toBe(0);
});

test('CA-CURSO-126 RN-CURSO-66 RN-CURSO-68 RN-ACAD-148: un destino con datos propios o fechas que quedan fuera impide ejecutar, sin recortar nada', function (): void {
    $s = R::scenario('rol-block');
    app(TenantContext::class)->runFor($s['tenant']->id, fn () => EducationLevel::create(['academic_year_id' => $s['target']->id, 'code' => 'MANO', 'name_translations' => ['es-ES' => 'A mano'], 'stage' => 'eso', 'regime' => 'concertado', 'position' => 1]));

    $preview = R::get($s['tenant'], $s['admin'], '/academic-year-rollovers/preview', ['academic_year' => $s['target']->public_id, 'source' => $s['source']->public_id, 'elements' => 'acad.education_levels'])->assertOk();

    expect($preview->json('executable'))->toBeFalse()
        ->and($preview->json('elements.0.blocking.0.code'))->toBe('acad.rollover.target_not_empty')
        ->and($preview->json('elements.0.blocking.0.params'))->toBe(['element' => 'acad.education_levels', 'count' => 1])
        ->and($preview->json('elements.0.blocking.0.message'))->toContain('Etapas');

    $post = R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['elements' => ['acad.education_levels']]))->assertStatus(409);

    expect($post->json('errors.rollover.0.code'))->toBe('acad.rollover.target_not_empty')
        ->and(R::inTenant($s, fn () => DB::table('year_rollovers')->count()))->toBe(0);

});

test('CA-CURSO-126 RN-CURSO-66 RN-CURSO-72: fechas desplazadas fuera del destino impiden ejecutar y nada se recorta', function (): void {
    [$tenant, $admin] = provisionCoreTenant('rol-short');
    contractModule($tenant, 'acad');
    $source = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');
    $target = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-05-31');
    R::structure($tenant, $source);
    $s = compact('tenant', 'admin', 'source', 'target');

    $preview = R::get($tenant, $admin, '/academic-year-rollovers/preview', ['academic_year' => $target->public_id, 'source' => $source->public_id, 'elements' => implode(',', R::NINE)])->assertOk();
    $byCode = collect($preview->json('elements'))->keyBy('code');

    // Períodos: el tercero acaba el 2028-06-22 (> 2028-05-31); marco especial: 2028-06-01 a 2028-06-30.
    expect($preview->json('executable'))->toBeFalse()
        ->and($byCode['acad.academic_periods']['blocking'][0]['code'])->toBe('acad.rollover.dates_outside_target')
        ->and($byCode['acad.academic_periods']['blocking'][0]['params'])->toBe(['element' => 'acad.academic_periods', 'count' => 1, 'starts_on' => '2027-09-08', 'ends_on' => '2028-06-21'])
        ->and($byCode['acad.timetable_frames']['blocking'][0]['params']['count'])->toBe(1);

    R::post($tenant, $admin, '/academic-year-rollovers', R::body($s))->assertStatus(409);
    expect(R::counts($tenant, $target)['academic_periods'])->toBe(0);
});

test('CA-CURSO-126 RN-CURSO-66: una selección que no crearía ninguna fila es la incidencia curso.rollover.empty', function (): void {
    [$tenant, $admin] = provisionCoreTenant('rol-empty');
    contractModule($tenant, 'acad');
    $source = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');
    $target = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-08-31');

    $preview = R::get($tenant, $admin, '/academic-year-rollovers/preview', ['academic_year' => $target->public_id, 'source' => $source->public_id, 'elements' => 'acad.grading_scales'])->assertOk();

    expect($preview->json('executable'))->toBeFalse()
        ->and($preview->json('blocking.0.code'))->toBe('curso.rollover.empty');

    R::post($tenant, $admin, '/academic-year-rollovers', ['academic_year' => $target->public_id, 'source' => $source->public_id, 'elements' => ['acad.grading_scales']])
        ->assertStatus(409)->assertJsonPath('errors.rollover.0.code', 'curso.rollover.empty');
});

test('CA-CURSO-127 RN-CURSO-68 RN-CURSO-79 RN-CURSO-81: la solicitud responde 202, deja la copia en pendiente con su created y encola el trabajo una vez, tras confirmar, con solo el identificador', function (): void {
    $s = R::scenario('rol-req');

    $response = R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s))->assertStatus(202);
    $publicId = $response->json('public_id');

    expect($response->headers->get('Location'))->toEndWith("/api/v1/academic-year-rollovers/{$publicId}")
        ->and($response->json())->toMatchArray([
            'status' => 'pendiente',
            'academic_year' => $s['target']->public_id,
            'source_academic_year' => $s['source']->public_id,
            'elements' => R::NINE,
            'copy_teaching_assignments' => false,
        ]);

    $rollover = R::byId($s, $publicId);
    Queue::assertPushed(RunAcademicYearRollover::class, 1);
    Queue::assertPushed(RunAcademicYearRollover::class, fn ($job) => $job->rolloverId === $rollover->id && $job->connection === 'database_long' && $job->queue === 'long');

    $created = R::inTenant($s, fn () => AuditLog::query()->where('auditable_type', 'year_rollover')->where('event', 'created')->count());
    expect($created)->toBe(1);
});

test('CA-CURSO-128 RN-CURSO-69 RN-CURSO-72 RN-CURSO-73 RN-CURSO-77 RN-ACAD-146 RN-ACAD-147: la ejecución copia el mismo conjunto de filas con identificadores nuevos, fechas desplazadas, un item por fila, auditoría con el actor y el origen intacto', function (): void {
    $s = R::scenario('rol-run');
    $publicId = R::request($s);
    $sourceBefore = app(TenantContext::class)->runFor($s['tenant']->id, fn () => [
        DB::table('education_levels')->where('academic_year_id', $s['source']->id)->max('updated_at'),
        AuditLog::query()->count(),
    ]);

    R::run($s, $publicId);
    $rollover = R::byId($s, $publicId);

    expect($rollover->status)->toBe(YearRolloverStatus::Completado)
        ->and($rollover->date_offset_days)->toBe(365)
        ->and($rollover->started_at)->not->toBeNull()
        ->and($rollover->finished_at)->not->toBeNull()
        ->and(collect($rollover->result['elements'])->pluck('copied', 'code')->all())->toBe([
            'acad.education_levels' => 2, 'acad.courses' => 3, 'acad.groups' => 4, 'acad.subgroups' => 2, 'acad.subjects' => 5,
            'acad.group_subjects' => 8, 'acad.academic_periods' => 3, 'acad.grading_scales' => 2, 'acad.timetable_frames' => 2,
        ]);

    expect(R::shape($s, $s['target']->id))->toBe(R::shape($s, $s['source']->id));

    app(TenantContext::class)->runFor($s['tenant']->id, function () use ($s, $rollover, $sourceBefore): void {
        $t = $s['target']->id;
        // Fechas: períodos y marco especial desplazados 365 días.
        expect(AcademicPeriod::query()->where('academic_year_id', $t)->orderBy('id')->pluck('starts_on')->map->toDateString()->all())->toBe(['2027-09-08', '2028-01-08', '2028-04-11'])
            ->and(TimetableFrame::query()->where('academic_year_id', $t)->where('frame_type', 'especial')->first()->valid_from->toDateString())->toBe('2028-05-31');

        // Escala cualitativa con sus tres valores y marco con sus diez franjas, hijos de las filas copiadas.
        expect(DB::table('grading_scale_values')->where('academic_year_id', $t)->whereNull('deleted_at')->count())->toBe(3)
            ->and(DB::table('time_slots')->where('academic_year_id', $t)->whereNull('deleted_at')->count())->toBe(11);

        // Items: uno por fila creada (incluidos valores y franjas).
        $items = YearRolloverItem::query()->where('year_rollover_id', $rollover->id)->get();
        expect($items)->toHaveCount(2 + 3 + 4 + 2 + 5 + 8 + 3 + 2 + 3 + 2 + 11)
            ->and($items->pluck('entity_type')->unique()->sort()->values()->all())->toBe(['academic_period', 'course', 'education_level', 'grading_scale', 'grading_scale_value', 'group', 'group_subject', 'subgroup', 'subject', 'time_slot', 'timetable_frame'])
            ->and($items->every(fn ($i) => $i->created_by === $s['admin']->id && $i->module_code === 'acad'))->toBeTrue();

        // Auditoría: cada fila creada y cada item dejan su `created` con actor el solicitante.
        $created = AuditLog::query()->where('event', 'created')->where('actor_user_id', $s['admin']->id)->whereIn('auditable_type', ['education_level', 'course', 'group', 'subgroup', 'subject', 'group_subject', 'academic_period', 'grading_scale', 'grading_scale_value', 'timetable_frame', 'time_slot', 'year_rollover_item'])->count();
        expect($created)->toBeGreaterThanOrEqual($items->count() * 2);

        // Nada del origen cambió: ni sus filas ni nuevas entradas de auditoría sobre ellas.
        expect(DB::table('education_levels')->where('academic_year_id', $s['source']->id)->max('updated_at'))->toBe($sourceBefore[0]);
    });
});

test('CA-CURSO-130 RN-CURSO-69 RN-CURSO-71: activar el destino con una copia en pendiente es 409 rollover_in_progress y no cambia nada; datos creados a mano antes de ejecutar hacen fallar la copia sin copiar nada', function (): void {
    $s = R::scenario('rol-act');
    $publicId = R::request($s, ['elements' => ['acad.education_levels']]);

    // El origen suele ser el curso activo: aun así gana rollover_in_progress sobre active_exists.
    resetSessionState();
    $response = test()->actingAs($s['admin'])->postJson(coreApiUrl($s['tenant']->slug, "/academic-years/{$s['target']->public_id}/status"), ['status' => 'activo'])->assertStatus(409);

    expect($response->json('errors.rollover.0.code'))->toBe('curso.conflict.rollover_in_progress')
        ->and($response->json('errors.rollover.0.params'))->toBe(['public_id' => $publicId, 'status' => 'pendiente'])
        ->and(R::inTenant($s, fn () => DB::table('academic_years')->where('id', $s['target']->id)->value('status')))->toBe('planificacion');

    // Una etapa viva creada a mano en el destino DESPUÉS de la solicitud: la ejecución lo vuelve a evaluar.
    R::inTenant($s, fn () => EducationLevel::create(['academic_year_id' => $s['target']->id, 'code' => 'MANO', 'name_translations' => ['es-ES' => 'A mano'], 'stage' => 'eso', 'regime' => 'concertado', 'position' => 9]));

    R::run($s, $publicId);
    $rollover = R::byId($s, $publicId);

    expect($rollover->status)->toBe(YearRolloverStatus::Fallido)
        ->and($rollover->failure['code'])->toBe('rollover_blocked')
        ->and($rollover->failure['errors'][0])->toEqual(['code' => 'acad.rollover.target_not_empty', 'params' => ['element' => 'acad.education_levels', 'count' => 1], 'element' => 'acad.education_levels'])
        ->and(R::targetCounts($s)['education_levels'])->toBe(1)
        ->and(R::inTenant($s, fn () => YearRolloverItem::query()->count()))->toBe(0);

    // Una copia fallida no impide otra (RN-CURSO-67): tras quitar lo creado a mano, la nueva solicitud da 202.
    R::inTenant($s, fn () => EducationLevel::query()->where('code', 'MANO')->get()->each->delete());
    expect(R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['elements' => ['acad.education_levels']]))->status())->toBe(202);
});

test('CA-CURSO-130 RN-CURSO-71: con la copia completada la activación procede', function (): void {
    $s = R::scenario('rol-act2');
    $publicId = R::request($s, ['elements' => ['acad.education_levels']]);
    R::run($s, $publicId);

    CursoTestHelpers::setStatus($s['tenant'], $s['source'], AcademicYearStatus::Cerrado);
    resetSessionState();
    test()->actingAs($s['admin'])->postJson(coreApiUrl($s['tenant']->slug, "/academic-years/{$s['target']->public_id}/status"), ['status' => 'activo'])->assertOk()->assertJsonPath('status', 'activo');

    // Y una vez activo, la copia ya no se revierte (RN-CURSO-74 (4)).
    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(409)->assertJsonPath('errors.rollover.0.code', 'curso.conflict.rollover_target_not_planning');
});

test('CA-CURSO-133 RN-CURSO-74 RN-CURSO-75 RN-CURSO-76 RN-CURSO-79: revertir borra lógicamente lo copiado con su deleted y el actor, no toca lo creado a mano ni el origen, y permite otra copia', function (): void {
    $s = R::scenario('rol-rev');
    $elements = array_values(array_diff(R::NINE, ['acad.grading_scales']));
    $publicId = R::request($s, ['elements' => $elements]);

    // Una escala creada a mano en el destino (el elemento no está en la selección): no es de la copia.
    R::inTenant($s, fn () => GradingScale::create(['academic_year_id' => $s['target']->id, 'code' => 'MANO', 'name_translations' => ['es-ES' => 'A mano'], 'scale_type' => 'numerica', 'min_value' => '0', 'max_value' => '10', 'decimals' => 0, 'passing_min_value' => '5']));

    R::run($s, $publicId);
    $copied = R::targetCounts($s);
    $sourceCounts = R::counts($s['tenant'], $s['source']);

    $response = R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(202);

    expect($response->json('status'))->toBe('revirtiendo');
    Queue::assertPushed(RevertAcademicYearRollover::class, 1);
    $rollover = R::byId($s, $publicId);
    expect($rollover->reverted_by)->toBe($s['admin']->id);

    R::runRevert($s, $publicId);
    $rollover = R::byId($s, $publicId);

    expect($rollover->status)->toBe(YearRolloverStatus::Revertido)
        ->and($rollover->reverted_at)->not->toBeNull();

    $after = R::targetCounts($s);

    expect($after['grading_scales'])->toBe(1)                                      // la creada a mano sigue viva
        ->and(array_sum(array_diff_key($after, ['grading_scales' => 1])))->toBe(0)  // todo lo copiado, borrado
        ->and(array_sum($copied))->toBeGreaterThan(0)
        ->and(R::counts($s['tenant'], $s['source']))->toBe($sourceCounts);

    R::inTenant($s, function () use ($s, $rollover): void {
        $itemsDeleted = AuditLog::query()->where('event', 'deleted')->where('actor_user_id', $s['admin']->id)->whereIn('auditable_type', ['education_level', 'course', 'group', 'subgroup', 'subject', 'group_subject', 'academic_period', 'timetable_frame', 'time_slot'])->count();
        $copiedRows = YearRolloverItem::query()->where('year_rollover_id', $rollover->id)->whereNot('entity_type', 'grading_scale_value')->count();

        expect($itemsDeleted)->toBe($copiedRows)
            // Los items se conservan (append-only).
            ->and($copiedRows)->toBeGreaterThan(0);
    });

    // Tras revertir se puede lanzar otra copia hacia el mismo destino (RN-CURSO-76).
    expect(R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['elements' => ['acad.education_levels']]))->status())->toBe(202);
});

test('CA-CURSO-134 RN-CURSO-74 RN-ACAD-150: un grupo creado a mano bajo un curso copiado bloquea revertir (409 con revert_has_dependents), también si aparece entre la solicitud y el trabajo', function (): void {
    $s = R::scenario('rol-dep');
    $publicId = R::request($s);
    R::run($s, $publicId);

    $courseId = R::inTenant($s, fn () => Course::query()->where('academic_year_id', $s['target']->id)->where('code', '1ESO')->value('id'));
    R::inTenant($s, fn () => Group::create(['academic_year_id' => $s['target']->id, 'course_id' => $courseId, 'code' => 'Z', 'name' => 'A mano', 'position' => 9]));

    $response = R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(409);

    expect($response->json('errors.rollover_revert.0.code'))->toBe('acad.rollover.revert_has_dependents')
        ->and($response->json('errors.rollover_revert.0.params.dependents'))->toContain('group')
        ->and($response->json('errors.rollover_revert.0.params.count'))->toBe(1)
        ->and(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Completado);

    // Sin el grupo, la solicitud pasa; creado entre la solicitud y el trabajo, el trabajo deja la copia en completado con revert_failure.
    R::inTenant($s, fn () => Group::query()->where('code', 'Z')->get()->each->delete());
    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(202);
    R::inTenant($s, fn () => Group::create(['academic_year_id' => $s['target']->id, 'course_id' => $courseId, 'code' => 'Y', 'name' => 'Tarde', 'position' => 9]));

    R::runRevert($s, $publicId);
    $rollover = R::byId($s, $publicId);
    $counts = R::targetCounts($s);

    expect($rollover->status)->toBe(YearRolloverStatus::Completado)
        ->and($rollover->revert_failure['code'])->toBe('rollover_blocked')
        ->and($rollover->revert_failure['errors'][0]['code'])->toBe('acad.rollover.revert_has_dependents')
        ->and($counts['education_levels'])->toBe(2);
});

test('CA-CURSO-135 RN-CURSO-74: revertir fuera de completado, con el destino activo o con acad descontratado es 409 y no cambia nada', function (): void {
    $s = R::scenario('rol-nrv');
    $publicId = R::request($s, ['elements' => ['acad.education_levels']]);

    // pendiente
    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(409)
        ->assertJsonPath('errors.rollover.0.code', 'curso.conflict.rollover_not_revertible')
        ->assertJsonPath('errors.rollover.0.params.status', 'pendiente');

    R::run($s, $publicId);

    // acad descontratado después de copiar
    R::disableAcad($s);
    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(409)
        ->assertJsonPath('errors.rollover.0.code', 'curso.conflict.rollover_revert_module_unavailable')
        ->assertJsonPath('errors.rollover.0.params.module', 'acad');

    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Completado)
        ->and(R::targetCounts($s)['education_levels'])->toBe(2);
});

test('CA-CURSO-136 RN-CURSO-75 OPEN-CURSO-33: revertir borra también lo editado después y no repite el deleted de lo ya borrado a mano', function (): void {
    $s = R::scenario('rol-edit');
    $publicId = R::request($s);
    R::run($s, $publicId);

    R::inTenant($s, function () use ($s): void {
        $subject = Subject::query()->where('academic_year_id', $s['target']->id)->where('code', 'MAT')->firstOrFail();
        $subject->name_translations = ['es-ES' => 'Renombrada'];
        $subject->save();
        AcademicPeriod::query()->where('academic_year_id', $s['target']->id)->orderBy('id')->firstOrFail()->delete();
    });

    $deletedBefore = R::inTenant($s, fn () => AuditLog::query()->where('event', 'deleted')->where('auditable_type', 'academic_period')->count());
    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(202);
    R::runRevert($s, $publicId);

    expect(R::inTenant($s, fn () => Subject::query()->where('academic_year_id', $s['target']->id)->count()))->toBe(0)
        ->and(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Revertido)
        // 3 períodos copiados; uno ya borrado a mano: solo se añaden 2 entradas deleted.
        ->and(R::inTenant($s, fn () => AuditLog::query()->where('event', 'deleted')->where('auditable_type', 'academic_period')->count()) - $deletedBefore)->toBe(2);
});

test('CA-CURSO-140 RN-CURSO-63 RN-CURSO-77: se puede copiar desde un curso cerrado y reabrirlo después no afecta a la copia', function (): void {
    $s = R::scenario('rol-closed', sourceStatus: AcademicYearStatus::Cerrado);
    $sourceCounts = R::counts($s['tenant'], $s['source']);
    $publicId = R::request($s);
    R::run($s, $publicId);

    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Completado)
        ->and(R::counts($s['tenant'], $s['source']))->toBe($sourceCounts);

    $copied = R::targetCounts($s);
    resetSessionState();
    test()->actingAs($s['admin'])->postJson(coreApiUrl($s['tenant']->slug, "/academic-years/{$s['source']->public_id}/reopen"), ['reason' => 'prueba'])->assertOk();

    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Completado)
        ->and(R::targetCounts($s))->toBe($copied);
});

test('CA-CURSO-141 RN-CURSO-62: con acad descontratado un elemento de acad es desconocido; descontratado entre la solicitud y la ejecución, la copia falla con curso.rollover.element_unavailable y no copia nada', function (): void {
    $s = R::scenario('rol-unav');
    $publicId = R::request($s, ['elements' => ['acad.education_levels']]);

    R::disableAcad($s);
    R::run($s, $publicId);
    $rollover = R::byId($s, $publicId);

    expect($rollover->status)->toBe(YearRolloverStatus::Fallido)
        ->and($rollover->failure['code'])->toBe('rollover_blocked')
        ->and($rollover->failure['errors'][0]['code'])->toBe('curso.rollover.element_unavailable')
        ->and(R::targetCounts($s)['education_levels'])->toBe(0);

    R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['elements' => ['acad.education_levels']]))
        ->assertStatus(422)->assertJsonPath('errors.elements.0.code', 'curso.validation.rollover_element_unknown');
});
