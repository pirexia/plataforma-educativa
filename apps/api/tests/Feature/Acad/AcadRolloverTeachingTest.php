<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\UserStatus;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\GroupTutor;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use App\Modules\Acad\Domain\Models\TimeSlot;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Modules\Acad\Infrastructure\AcadRolloverParticipant;
use App\Modules\Curso\Domain\AcademicYearRolloverPlan;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\Models\YearRolloverItem;
use App\Modules\Curso\Domain\YearRolloverStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\AcadApiHelpers;
use Tests\Support\RolloverTestHelpers as R;

// REQ-CURSO-002 (1.12b), REQ-ACAD-002, REQ-ACAD/funcional.md §21.5: la casilla «copiar la asignación docente»
// (`RN-CURSO-82`, `RN-ACAD-153` a `-157`). Origen de prueba con docencia: cuatro asignaciones de tres usuarios activos,
// dos tutorías, seis sesiones (una en un aula dada de baja) y una asignación, una tutoría y una sesión borradas.
// Las funciones auxiliares (`rolloverGet`, `requestRollover`, `runRollover`…) viven en AcadRolloverTest.php.

beforeEach(function (): void {
    config(['curso.rollover.allow_nested_transaction' => true]);
    Queue::fake();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/** @return array<string, mixed> */
function teachingBody(array $scenario, array $overrides = []): array
{
    return $overrides + R::body($scenario, ['copy_teaching_assignments' => true]);
}

/** @return array<string, mixed> */
function teachingPreview(array $scenario, array $elements = R::NINE, bool $copy = true, ?object $user = null): TestResponse
{
    return R::get($scenario['tenant'], $user ?? $scenario['admin'], '/academic-year-rollovers/preview', [
        'academic_year' => $scenario['target']->public_id,
        'source' => $scenario['source']->public_id,
        'elements' => implode(',', $elements),
        'copy_teaching_assignments' => $copy ? 'true' : 'false',
    ]);
}

function previewElement(TestResponse $response, string $code): array
{
    return collect($response->json('elements'))->firstWhere('code', $code);
}

test('CA-ACAD-142 RN-ACAD-145 RN-ACAD-153: el catálogo trae los tres elementos del paquete teaching_assignments con sus requisitos y su etiqueta en los cuatro idiomas', function (): void {
    $s = R::scenario('rot-cat');
    AcadApiHelpers::setLocales($s['tenant'], $s['admin'], 'es-ES', ['es-ES', 'en', 'de', 'fr']);

    $labels = ['es-ES' => 'Asignaciones docentes', 'en' => 'Teaching assignments', 'de' => 'Lehraufträge', 'fr' => 'Affectations d’enseignement'];

    foreach ($labels as $locale => $label) {
        R::inTenant($s, function () use ($s, $locale): void {
            $person = Person::query()->findOrFail($s['admin']->person_id);
            $person->locale = $locale;
            $person->save();
        });
        $s['admin']->unsetRelation('person');

        $data = collect(R::get($s['tenant'], $s['admin'], '/academic-year-rollovers/elements')->assertOk()->json('data'))->keyBy('code');

        expect($data['acad.teaching_assignments']['label'])->toBe($label)
            ->and($data['acad.teaching_assignments']['requires'])->toBe(['acad.group_subjects', 'acad.subgroups'])
            ->and($data['acad.group_tutors']['requires'])->toBe(['acad.groups'])
            ->and($data['acad.timetable_sessions']['requires'])->toBe(['acad.teaching_assignments'])
            ->and(collect($data)->where('bundle', 'teaching_assignments')->keys()->all())->toBe(['acad.teaching_assignments', 'acad.group_tutors', 'acad.timetable_sessions']);
    }
});

test('CA-ACAD-143 RN-ACAD-146 RN-ACAD-153: con la casilla se copian las asignaciones (mismo usuario, padres copiados), las tutorías y las seis sesiones, con su created y el origen intacto', function (): void {
    $s = R::scenario('rot-run', teaching: true);
    $publicId = R::request($s, ['copy_teaching_assignments' => true]);

    $rollover = R::byId($s, $publicId);
    expect($rollover->copy_teaching_assignments)->toBeTrue()
        ->and($rollover->elements)->toBe([...R::NINE, 'acad.teaching_assignments', 'acad.group_tutors', 'acad.timetable_sessions']);

    $sourceCounts = R::counts($s['tenant'], $s['source']);
    R::run($s, $publicId);
    $rollover = R::byId($s, $publicId);

    expect($rollover->status)->toBe(YearRolloverStatus::Completado)
        ->and(collect($rollover->result['elements'])->pluck('copied', 'code')->only(['acad.teaching_assignments', 'acad.group_tutors', 'acad.timetable_sessions'])->all())
        ->toBe(['acad.teaching_assignments' => 4, 'acad.group_tutors' => 2, 'acad.timetable_sessions' => 6]);

    R::inTenant($s, function () use ($s, $rollover): void {
        $t = $s['target']->id;
        $teacherIds = collect($s['data']['teachers'])->pluck('id')->all();

        // Asignaciones: mismo user_id, asignatura del grupo y subgrupo copiados (verificado por los códigos de sus padres).
        $assignments = TeachingAssignment::query()->where('academic_year_id', $t)->with(['group.course', 'groupSubject.subject', 'subgroup'])->get()
            ->map(fn ($a) => [$a->user_id, "{$a->group->course->code}/{$a->group->code}", $a->groupSubject->subject->code, $a->subgroup?->code])->sort()->values()->all();
        $expected = collect($s['data']['assignments'])->map(fn ($a) => $a->load(['group.course', 'groupSubject.subject', 'subgroup']))
            ->map(fn ($a) => [$a->user_id, "{$a->group->course->code}/{$a->group->code}", $a->groupSubject->subject->code, $a->subgroup?->code])->sort()->values()->all();

        expect($assignments)->toBe($expected)
            ->and(array_unique(array_column($assignments, 0)))->each->toBeIn($teacherIds);

        // Tutorías: grupo copiado y mismo usuario.
        expect(GroupTutor::query()->where('academic_year_id', $t)->with('group.course')->get()->map(fn ($g) => [$g->user_id, "{$g->group->course->code}/{$g->group->code}"])->sort()->values()->all())
            ->toBe(collect($s['data']['tutors'])->map(fn ($g) => $g->load('group.course'))->map(fn ($g) => [$g->user_id, "{$g->group->course->code}/{$g->group->code}"])->sort()->values()->all());

        // Sesiones: franja y asignación copiadas; la misma aula salvo la dada de baja.
        $sessions = TimetableSession::query()->where('academic_year_id', $t)->get();
        expect($sessions)->toHaveCount(6)
            ->and($sessions->every(fn ($x) => TimeSlot::query()->where('academic_year_id', $t)->whereKey($x->time_slot_id)->exists() && TeachingAssignment::query()->where('academic_year_id', $t)->whereKey($x->teaching_assignment_id)->exists()))->toBeTrue()
            ->and($sessions->whereNotNull('facility_id')->pluck('facility_id')->all())->toBe([$s['data']['room']->id]);

        // Items con sus alias, y su created con actor el solicitante.
        $items = YearRolloverItem::query()->where('year_rollover_id', $rollover->id)->get()->countBy('entity_type');
        expect($items['teaching_assignment'])->toBe(4)->and($items['group_tutor'])->toBe(2)->and($items['timetable_session'])->toBe(6);

        foreach (['teaching_assignment', 'group_tutor', 'timetable_session'] as $type) {
            expect(AuditLog::query()->where('event', 'created')->where('auditable_type', $type)->where('actor_user_id', $s['admin']->id)->whereIn('auditable_id', DB::table(match ($type) {
                'teaching_assignment' => 'teaching_assignments',
                'group_tutor' => 'group_tutors',
                'timetable_session' => 'timetable_sessions',
            })->where('academic_year_id', $t)->pluck('id'))->count())->toBe($items[$type]);
        }
    });

    expect(R::counts($s['tenant'], $s['source']))->toBe($sourceCounts);
});

test('CA-ACAD-144 RN-ACAD-153: sin la casilla (ausente o false) no se copia ninguna asignación, tutoría ni sesión y la simulación no lista los elementos del paquete', function (): void {
    $s = R::scenario('rot-off', teaching: true);

    foreach ([false, true] as $explicit) {
        $preview = teachingPreview($s, R::NINE, false)->assertOk();
        expect(array_column($preview->json('elements'), 'code'))->toBe(R::NINE)
            ->and($preview->json('copy_teaching_assignments'))->toBeFalse();
    }

    $publicId = R::request($s);
    R::run($s, $publicId);
    $counts = R::targetCounts($s);

    expect($counts['teaching_assignments'])->toBe(0)->and($counts['group_tutors'])->toBe(0)->and($counts['timetable_sessions'])->toBe(0)
        ->and(R::byId($s, $publicId)->copy_teaching_assignments)->toBeFalse();
});

test('CA-ACAD-145 RN-ACAD-154 RN-CURSO-73: un profesor inactivo, pendiente o borrado se omite con la cuenta por motivo, sin nombres ni identificadores; la copia no falla y cuenta el estado de la ejecución', function (): void {
    $s = R::scenario('rot-inact', teaching: true);
    [, $t2] = $s['data']['teachers'];
    R::setUserStatus($s['tenant'], $t2, UserStatus::Inactivo);

    $preview = teachingPreview($s)->assertOk();

    expect($preview->json('executable'))->toBeTrue()
        ->and(previewElement($preview, 'acad.teaching_assignments'))->toMatchArray(['to_copy' => 3])
        ->and(previewElement($preview, 'acad.teaching_assignments')['skipped'][0])->toMatchArray(['reason' => 'teacher_inactive', 'count' => 1])
        ->and(previewElement($preview, 'acad.timetable_sessions')['to_copy'])->toBe(4)
        ->and(previewElement($preview, 'acad.timetable_sessions')['skipped'][0])->toMatchArray(['reason' => 'teacher_inactive', 'count' => 2])
        ->and(previewElement($preview, 'acad.group_tutors')['to_copy'])->toBe(1)
        ->and(previewElement($preview, 'acad.group_tutors')['skipped'][0])->toMatchArray(['reason' => 'tutor_inactive', 'count' => 1]);

    $json = $preview->getContent();
    expect($json)->not->toContain('prof2@example.com')->not->toContain($t2->public_id)->not->toContain('Docente Prueba');

    $publicId = R::request($s, ['copy_teaching_assignments' => true]);
    R::run($s, $publicId);
    $rollover = R::byId($s, $publicId);
    $result = collect($rollover->result['elements'])->keyBy('code');

    expect($rollover->status)->toBe(YearRolloverStatus::Completado)
        ->and($result['acad.teaching_assignments']['copied'])->toBe(3)
        ->and($result['acad.teaching_assignments']['skipped'])->toEqual([['reason' => 'teacher_inactive', 'count' => 1]])
        ->and($result['acad.timetable_sessions']['skipped'])->toEqual([['reason' => 'teacher_inactive', 'count' => 2]])
        ->and($result['acad.group_tutors']['skipped'])->toEqual([['reason' => 'tutor_inactive', 'count' => 1]])
        ->and(json_encode($rollover->result))->not->toContain('prof2');

    R::inTenant($s, fn () => expect(TeachingAssignment::query()->where('academic_year_id', $s['target']->id)->where('user_id', $t2->id)->count())->toBe(0)
        ->and(GroupTutor::query()->where('academic_year_id', $s['target']->id)->where('user_id', $t2->id)->count())->toBe(0));
});

test('CA-ACAD-145 RN-ACAD-154: un usuario pendiente se omite igual, y uno reactivado entre la simulación y la ejecución se copia', function (): void {
    $s = R::scenario('rot-inact2', teaching: true);
    [, $t2] = $s['data']['teachers'];

    R::setUserStatus($s['tenant'], $t2, UserStatus::Pendiente);
    expect(previewElement(teachingPreview($s)->assertOk(), 'acad.teaching_assignments')['skipped'][0]['count'])->toBe(1);

    $publicId = R::request($s, ['copy_teaching_assignments' => true]);
    R::setUserStatus($s['tenant'], $t2, UserStatus::Activo);
    R::run($s, $publicId);

    expect(collect(R::byId($s, $publicId)->result['elements'])->keyBy('code')['acad.teaching_assignments']['copied'])->toBe(4);
});

test('CA-ACAD-145 RN-ACAD-154: un profesor borrado se omite', function (): void {
    $s = R::scenario('rot-inact3', teaching: true);
    [, $t2] = $s['data']['teachers'];
    R::inTenant($s, fn () => $t2->refresh()->delete());

    expect(previewElement(teachingPreview($s)->assertOk(), 'acad.teaching_assignments')['skipped'][0])->toMatchArray(['reason' => 'teacher_inactive', 'count' => 1]);
});

test('CA-ACAD-146 RN-ACAD-155: con la casilla y sin los marcos horarios se copian asignaciones y tutorías y las seis sesiones se omiten con frames_not_copied sin impedir ejecutar', function (): void {
    $s = R::scenario('rot-noframes', teaching: true);
    $elements = array_values(array_diff(R::NINE, ['acad.timetable_frames']));

    $preview = teachingPreview($s, $elements)->assertOk();
    expect($preview->json('executable'))->toBeTrue()
        ->and(previewElement($preview, 'acad.timetable_sessions')['to_copy'])->toBe(0)
        ->and(previewElement($preview, 'acad.timetable_sessions')['skipped'])->toBe([['reason' => 'frames_not_copied', 'label' => 'No se copian los marcos horarios', 'count' => 6]]);

    $publicId = R::request($s, ['elements' => $elements, 'copy_teaching_assignments' => true]);
    R::run($s, $publicId);
    $result = collect(R::byId($s, $publicId)->result['elements'])->keyBy('code');

    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Completado)
        ->and($result['acad.timetable_sessions']['copied'])->toBe(0)
        ->and($result['acad.timetable_sessions']['skipped'])->toEqual([['reason' => 'frames_not_copied', 'count' => 6]])
        ->and($result['acad.teaching_assignments']['copied'])->toBe(4)
        ->and(R::targetCounts($s)['timetable_sessions'])->toBe(0)
        ->and(R::targetCounts($s)['group_tutors'])->toBe(2);
});

test('CA-ACAD-147 RN-ACAD-155: una sesión en un aula dada de baja se copia sin aula y se cuenta como ajuste facility_removed; las demás conservan su aula', function (): void {
    $s = R::scenario('rot-room', teaching: true);

    $preview = teachingPreview($s)->assertOk();
    expect(previewElement($preview, 'acad.timetable_sessions')['adjusted'])->toBe([['reason' => 'facility_removed', 'label' => 'Se copian sin aula (aula dada de baja)', 'count' => 1]]);

    $publicId = R::request($s, ['copy_teaching_assignments' => true]);
    R::run($s, $publicId);

    expect(collect(R::byId($s, $publicId)->result['elements'])->keyBy('code')['acad.timetable_sessions']['adjusted'])->toEqual([['reason' => 'facility_removed', 'count' => 1]]);

    R::inTenant($s, function () use ($s): void {
        $sessions = TimetableSession::query()->where('academic_year_id', $s['target']->id)->get();

        expect($sessions->whereNull('facility_id')->count())->toBe(5)
            ->and($sessions->whereNull('facility_type')->count())->toBe(5)
            ->and($sessions->where('facility_id', $s['data']['room']->id)->count())->toBe(1);
    });
});

test('CA-ACAD-148 RN-ACAD-147: tras copiar la docencia no hay violaciones de asignación única, tutoría única ni conflictos de profesor, aula o grupo en el destino', function (): void {
    $s = R::scenario('rot-viol', teaching: true);
    $publicId = R::request($s, ['copy_teaching_assignments' => true]);
    R::run($s, $publicId);

    R::inTenant($s, function () use ($s): void {
        $y = $s['target']->id;
        $count = fn (string $sql): int => (int) DB::selectOne($sql, [$y])->n;

        // RN-ACAD-94: una asignación por (grupo, asignatura de grupo, subgrupo, usuario) vivas.
        expect($count('select count(*) n from (select 1 from teaching_assignments where academic_year_id = ? and deleted_at is null group by group_subject_id, coalesce(subgroup_id, 0), user_id having count(*) > 1) x'))->toBe(0)
            // Tutoría única por grupo.
            ->and($count('select count(*) n from (select 1 from group_tutors where academic_year_id = ? and deleted_at is null group by group_id, user_id having count(*) > 1) x'))->toBe(0)
            // RN-ACAD-123/124: en la misma franja, ni el mismo profesor, ni la misma aula, ni el mismo grupo dos veces.
            ->and($count('select count(*) n from (select 1 from timetable_sessions s join teaching_assignments a on a.id = s.teaching_assignment_id where s.academic_year_id = ? and s.deleted_at is null group by s.time_slot_id, a.user_id having count(*) > 1) x'))->toBe(0)
            ->and($count('select count(*) n from (select 1 from timetable_sessions s where s.academic_year_id = ? and s.deleted_at is null and s.facility_id is not null group by s.time_slot_id, s.facility_id having count(*) > 1) x'))->toBe(0)
            ->and($count('select count(*) n from (select 1 from timetable_sessions s join teaching_assignments a on a.id = s.teaching_assignment_id where s.academic_year_id = ? and s.deleted_at is null group by s.time_slot_id, a.group_id having count(*) > 1) x'))->toBe(0);
    });
});

test('CA-ACAD-149 RN-ACAD-150 RN-ACAD-151: revertir borra sesiones, tutorías y asignaciones antes que franjas, grupos y asignaturas; una sesión o una asignación creadas a mano bloquean la reversión', function (): void {
    $s = R::scenario('rot-rev', teaching: true);
    $publicId = R::request($s, ['copy_teaching_assignments' => true]);
    R::run($s, $publicId);

    // Una sesión creada a mano que usa una asignación copiada ⇒ 409 con timetable_session.
    $manual = R::inTenant($s, function () use ($s): TimetableSession {
        $assignment = TeachingAssignment::query()->where('academic_year_id', $s['target']->id)->orderBy('id')->firstOrFail();
        $slot = TimeSlot::query()->where('academic_year_id', $s['target']->id)->orderByDesc('id')->firstOrFail();

        return TimetableSession::create(['academic_year_id' => $s['target']->id, 'time_slot_id' => $slot->id, 'teaching_assignment_id' => $assignment->id]);
    });

    $response = R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(409);
    expect($response->json('errors.rollover_revert.0.code'))->toBe('acad.rollover.revert_has_dependents')
        ->and($response->json('errors.rollover_revert.0.params.dependents'))->toContain('timetable_session');

    R::inTenant($s, fn () => $manual->delete());

    // Una asignación creada a mano en un grupo copiado ⇒ 409 con teaching_assignment.
    $assignment = R::inTenant($s, function () use ($s): TeachingAssignment {
        $group = Group::query()->where('academic_year_id', $s['target']->id)->where('code', 'B')->firstOrFail();
        $groupSubject = GroupSubject::query()->where('academic_year_id', $s['target']->id)->where('group_id', $group->id)->orderBy('id')->firstOrFail();

        return TeachingAssignment::create(['academic_year_id' => $s['target']->id, 'user_id' => $s['data']['teachers'][0]->id, 'group_id' => $group->id, 'group_subject_id' => $groupSubject->id]);
    });

    $response = R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(409);
    expect($response->json('errors.rollover_revert.0.params.dependents'))->toContain('teaching_assignment');
    R::inTenant($s, fn () => $assignment->delete());

    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(202);
    $mark = R::inTenant($s, fn () => (int) AuditLog::query()->max('id'));
    R::runRevert($s, $publicId);

    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Revertido)
        ->and(array_sum(R::targetCounts($s)))->toBe(0);

    // Orden de borrado (RN-ACAD-151), comprobado por el orden de las entradas de audit_logs.
    $order = R::inTenant($s, fn () => AuditLog::query()->where('id', '>', $mark)->where('event', 'deleted')->where('actor_user_id', $s['admin']->id)
        ->whereIn('auditable_type', ['timetable_session', 'group_tutor', 'teaching_assignment', 'time_slot', 'timetable_frame', 'group_subject', 'group', 'course', 'education_level'])
        ->orderBy('id')->pluck('auditable_type')->unique()->values()->all());

    $rank = array_flip(['timetable_session', 'group_tutor', 'teaching_assignment', 'time_slot', 'timetable_frame', 'group_subject', 'group', 'course', 'education_level']);
    $ranks = array_map(fn (string $type): int => $rank[$type], $order);

    expect($ranks)->toBe(collect($ranks)->sort()->values()->all());
});

test('CA-ACAD-150 RN-ACAD-156 RN-CURSO-78: sin el permiso de alta de un elemento del paquete, la simulación y la solicitud con la casilla son 403 por elemento y no crean nada; sin la casilla, pasan', function (): void {
    $s = R::scenario('rot-perm', teaching: true);
    $base = ['apertura_curso_academico.crear', 'estructura_academica.crear', 'estructura_academica.actualizar', 'configuracion_academica.crear', 'horarios.crear', 'asignacion_docente.crear', 'asignacion_docente.actualizar'];

    $cases = [
        'asignacion_docente.crear' => 'acad.teaching_assignments',
        'asignacion_docente.actualizar' => 'acad.group_tutors',
        'horarios.crear' => ['acad.timetable_frames', 'acad.timetable_sessions'],
    ];

    foreach ($cases as $missing => $elements) {
        $user = AcadApiHelpers::userWith($s['tenant'], $s['admin'], 'p'.substr(md5($missing), 0, 6), array_values(array_diff($base, [$missing])));

        $response = teachingPreview($s, R::NINE, true, $user)->assertStatus(403);
        $forbidden = array_column($response->json('errors.elements'), 'params');
        $expected = array_map(fn ($e) => ['element' => $e], (array) $elements);

        expect($forbidden)->toEqual($expected)
            ->and($response->json('errors.elements.0.code'))->toBe('curso.rollover.element_forbidden');

        $post = R::post($s['tenant'], $user, '/academic-year-rollovers', teachingBody($s))->assertStatus(403);
        expect(array_column($post->json('errors.elements'), 'params'))->toEqual($expected);

        $catalog = collect(R::get($s['tenant'], $user, '/academic-year-rollovers/elements')->json('data'))->pluck('allowed', 'code');
        foreach ((array) $elements as $element) {
            expect($catalog[$element])->toBeFalse();
        }
    }

    expect(R::inTenant($s, fn () => YearRollover::query()->count()))->toBe(0);

    // Sin la casilla y sin el permiso del paquete, 200: el paquete no se evalúa.
    $limited = AcadApiHelpers::userWith($s['tenant'], $s['admin'], 'plim', array_values(array_diff($base, ['asignacion_docente.crear', 'asignacion_docente.actualizar'])));
    teachingPreview($s, R::NINE, false, $limited)->assertOk();
});

test('CA-ACAD-151 INV-001 RN-ACAD-157: la copia en un centro no lee la docencia de otro, y la consulta de usuarios activos se hace una vez por preview() y una por copy()', function (): void {
    $a = R::scenario('rot-iso-a', teaching: true);
    $b = R::scenario('rot-iso-b', teaching: true);

    $plan = fn (array $s): AcademicYearRolloverPlan => R::inTenant($s, fn () => new AcademicYearRolloverPlan(
        AcademicYear::query()->findOrFail($s['source']->id)->toSummary(),
        AcademicYear::query()->findOrFail($s['target']->id)->toSummary(),
        [...R::NINE, 'acad.teaching_assignments', 'acad.group_tutors', 'acad.timetable_sessions'],
        365,
    ));

    $queriesOnUsers = function (array $s, Closure $call) use (&$queriesOnUsers): int {
        return R::inTenant($s, function () use ($call): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $call();
            DB::disableQueryLog();

            return count(array_filter(DB::getQueryLog(), fn (array $q): bool => (bool) preg_match('/from "users"/i', $q['query'])));
        });
    };

    $participant = app(AcadRolloverParticipant::class);
    $planA = $plan($a);

    expect($queriesOnUsers($a, fn () => $participant->preview($planA)))->toBe(1)
        ->and($queriesOnUsers($a, fn () => DB::transaction(fn () => $participant->copy($planA))))->toBe(1);

    // Nada de B aparece en A: el destino de A solo tiene usuarios de A, y B sigue vacío.
    $foreign = collect($b['data']['teachers'])->pluck('id')->all();

    R::inTenant($a, fn () => expect(TeachingAssignment::query()->where('academic_year_id', $a['target']->id)->whereIn('user_id', $foreign)->count())->toBe(0)
        ->and(TeachingAssignment::query()->where('academic_year_id', $a['target']->id)->count())->toBe(4));
    expect(array_sum(R::targetCounts($b)))->toBe(0);
});

test('CA-ACAD-152 INV-009 AR-12: las claves de acad.rollover existen con texto en los cuatro idiomas', function (): void {
    $keys = [
        ...array_map(fn (string $n) => "elements.{$n}", ['education_levels', 'courses', 'groups', 'subgroups', 'subjects', 'group_subjects', 'academic_periods', 'grading_scales', 'timetable_frames', 'teaching_assignments', 'group_tutors', 'timetable_sessions']),
        'target_not_empty', 'dates_outside_target', 'revert_has_dependents',
        'skipped.teacher_inactive', 'skipped.tutor_inactive', 'skipped.frames_not_copied', 'adjusted.facility_removed',
    ];

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        $lang = require base_path("lang/{$locale}/acad.php");

        foreach ($keys as $key) {
            expect(Arr::get($lang['rollover'], $key))->toBeString()->not->toBeEmpty("{$locale}: acad.rollover.{$key}");
        }
    }
});
