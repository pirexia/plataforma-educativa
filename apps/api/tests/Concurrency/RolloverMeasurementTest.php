<?php

use App\Models\UserStatus;
use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Facility;
use App\Modules\Acad\Domain\Models\GradingScale;
use App\Modules\Acad\Domain\Models\GradingScaleValue;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\GroupTutor;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use App\Modules\Acad\Domain\Models\TimeSlot;
use App\Modules\Acad\Domain\Models\Timetable;
use App\Modules\Acad\Domain\Models\TimetableFrame;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Modules\Curso\Application\Rollover\RolloverExecutor;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\CursoTestHelpers;
use Tests\Support\RolloverTestHelpers as R;

/**
 * `CA-CURSO-149` (`INV-012`, medición; precedente `CA-057-09`): duración de los trabajos de copia y de reversión con el
 * volumen de un centro de 40 grupos —estructura (3 etapas, 12 cursos de etapa, 40 grupos, 10 subgrupos, 120 asignaturas,
 * 400 asignaturas de grupo), 9 períodos, 3 escalas con 15 valores y 6 marcos con sus franjas—, **y otra serie con la casilla de
 * asignación docente** (400 asignaciones, 40 tutorías y ≈ 1.200 sesiones). Tres corridas de cada una; mediana. La cifra fija el
 * tiempo máximo del trabajo (`operacion.md §4.1`): **no se ajusta**.
 *
 * Opt-in (`ROLLOVER_MEASURE=1`): construye miles de filas y tarda varios minutos, así que no entra en `composer test`. Se
 * ejecuta una vez por entrega y la cifra se anota en `CHANGELOG.md`:
 *
 *   ROLLOVER_MEASURE=1 php -d memory_limit=-1 vendor/bin/pest tests/Concurrency/RolloverMeasurementTest.php
 */
afterEach(function (): void {
    $owner = DB::connection('pgsql_owner');

    foreach (DB::connection('pgsql_platform')->table('tenants')->pluck('id') as $tenantId) {
        $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

        foreach (['year_rollover_items', 'year_rollovers', 'timetable_sessions', 'teaching_assignments', 'group_tutors', 'time_slots', 'timetable_frames', 'timetables', 'facilities', 'grading_scale_values', 'grading_scales', 'academic_periods', 'group_subjects', 'subgroups', 'groups', 'subjects', 'courses', 'education_levels'] as $table) {
            $owner->table($table)->delete();
        }
    }

    $owner->statement("select set_config('app.tenant_id', '', false)");
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @param  list<float>  $values */
function rolloverMedian(array $values): float
{
    sort($values);

    return $values[intdiv(count($values), 2)];
}

/**
 * Construye el centro de 40 grupos en el curso origen. Devuelve el número de filas creadas.
 *
 * @return array<string, int>
 */
function rolloverBuildLargeCentre(array $s): array
{
    return app(TenantContext::class)->runFor($s['tenant']->id, function () use ($s): array {
        $y = $s['source']->id;
        $teachers = [];

        for ($i = 1; $i <= 20; $i++) {
            $teachers[] = R::user($s['tenant'], "medida{$i}@example.com");
        }

        $levels = [];
        $courses = [];

        foreach (['INF', 'PRI', 'ESO'] as $li => $code) {
            $levels[$li] = EducationLevel::create(['academic_year_id' => $y, 'code' => $code, 'name_translations' => ['es-ES' => $code], 'stage' => 'eso', 'regime' => 'concertado', 'position' => $li + 1]);

            for ($c = 1; $c <= 4; $c++) {
                $courses[] = Course::create(['academic_year_id' => $y, 'education_level_id' => $levels[$li]->id, 'code' => "{$code}{$c}", 'name_translations' => ['es-ES' => "{$code} {$c}"], 'position' => $c]);
            }
        }

        $groups = [];
        $subjectsByCourse = [];

        foreach ($courses as $ci => $course) {
            for ($sj = 1; $sj <= 10; $sj++) {
                $subjectsByCourse[$ci][] = Subject::create(['academic_year_id' => $y, 'course_id' => $course->id, 'code' => "M{$sj}", 'name_translations' => ['es-ES' => "Asignatura {$sj}"], 'position' => $sj]);
            }
        }

        // 40 grupos: 3 por curso y uno más en los cuatro primeros.
        foreach ($courses as $ci => $course) {
            $count = $ci < 4 ? 4 : 3;

            for ($g = 1; $g <= $count; $g++) {
                $groups[] = [$ci, Group::create(['academic_year_id' => $y, 'course_id' => $course->id, 'code' => "G{$g}", 'name' => "Grupo {$g}", 'position' => $g])];
            }
        }

        $subgroups = 0;

        foreach (array_slice($groups, 0, 10) as [, $group]) {
            Subgroup::create(['academic_year_id' => $y, 'group_id' => $group->id, 'code' => 'D1', 'name' => 'Desdoble', 'position' => 1]);
            $subgroups++;
        }

        $groupSubjects = [];

        foreach ($groups as [$ci, $group]) {
            foreach ($subjectsByCourse[$ci] as $subject) {
                $groupSubjects[] = [$group, GroupSubject::create(['academic_year_id' => $y, 'course_id' => $courses[$ci]->id, 'group_id' => $group->id, 'subject_id' => $subject->id])];
            }
        }

        foreach ($levels as $li => $level) {
            for ($p = 1; $p <= 3; $p++) {
                AcademicPeriod::create(['academic_year_id' => $y, 'education_level_id' => $level->id, 'period_type' => 'trimestre', 'name_translations' => ['es-ES' => "T{$p}"], 'starts_on' => '2026-09-08', 'ends_on' => '2026-12-22', 'position' => $p]);
            }
        }

        for ($sc = 1; $sc <= 3; $sc++) {
            $scale = GradingScale::create(['academic_year_id' => $y, 'code' => "E{$sc}", 'name_translations' => ['es-ES' => "Escala {$sc}"], 'scale_type' => 'cualitativa']);

            for ($v = 1; $v <= 5; $v++) {
                GradingScaleValue::create(['academic_year_id' => $y, 'grading_scale_id' => $scale->id, 'scale_type' => 'cualitativa', 'code' => "V{$v}", 'label_translations' => ['es-ES' => "Valor {$v}"], 'position' => $v, 'is_passing' => $v > 1]);
            }
        }

        Timetable::create(['academic_year_id' => $y]);
        $slots = [];

        foreach ($levels as $level) {
            $frame = TimetableFrame::create(['academic_year_id' => $y, 'education_level_id' => $level->id, 'frame_type' => 'ordinaria', 'name_translations' => ['es-ES' => 'Horario']]);

            foreach ([1, 2, 3, 4, 5] as $weekday) {
                foreach (range(0, 5) as $k) {
                    $from = sprintf('%02d:00', 8 + $k);
                    $to = sprintf('%02d:00', 9 + $k);
                    $slots[] = TimeSlot::create(['academic_year_id' => $y, 'timetable_frame_id' => $frame->id, 'weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to]);
                }
            }

            $special = TimetableFrame::create(['academic_year_id' => $y, 'education_level_id' => $level->id, 'frame_type' => 'especial', 'name_translations' => ['es-ES' => 'Jornada reducida'], 'valid_from' => '2027-06-01', 'valid_to' => '2027-06-30']);
            TimeSlot::create(['academic_year_id' => $y, 'timetable_frame_id' => $special->id, 'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00']);
        }

        $room = Facility::create(['facility_type' => 'aula', 'code' => 'M-'.substr(uniqid(), -5), 'name' => 'Aula de medida']);
        $assignments = 0;
        $sessions = 0;

        foreach ($groupSubjects as $i => [$group, $gs]) {
            $assignment = TeachingAssignment::create(['academic_year_id' => $y, 'user_id' => $teachers[$i % 20]->id, 'group_id' => $group->id, 'group_subject_id' => $gs->id]);
            $assignments++;

            for ($k = 0; $k < 3; $k++) {
                TimetableSession::create(['academic_year_id' => $y, 'time_slot_id' => $slots[($i * 3 + $k) % count($slots)]->id, 'teaching_assignment_id' => $assignment->id, 'facility_id' => $k === 0 ? $room->id : null, 'facility_type' => $k === 0 ? 'aula' : null]);
                $sessions++;
            }
        }

        foreach (array_slice($groups, 0, 40) as $gi => [, $group]) {
            GroupTutor::create(['academic_year_id' => $y, 'group_id' => $group->id, 'user_id' => $teachers[$gi % 20]->id]);
        }

        return ['groups' => count($groups), 'group_subjects' => count($groupSubjects), 'assignments' => $assignments, 'sessions' => $sessions, 'subgroups' => $subgroups];
    });
}

test('CA-CURSO-149 INV-012: medición de la copia y de la reversión con el volumen de un centro de 40 grupos, sin y con la casilla de asignación docente (tres corridas, mediana)', function (): void {
    [$tenant, $admin] = provisionCoreTenant('rcc-149');
    contractModule($tenant, 'acad');
    R::setUserStatus($tenant, $admin, UserStatus::Activo);
    $source = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');
    $target = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-08-31');
    $s = ['tenant' => $tenant, 'admin' => $admin, 'source' => $source, 'target' => $target];

    $built = rolloverBuildLargeCentre($s);

    expect($built['groups'])->toBe(40)->and($built['sessions'])->toBeGreaterThan(1100);

    $series = [
        'estructura, períodos, escalas y marcos' => [R::NINE, false],
        'con la casilla de asignación docente' => [[...R::NINE, 'acad.teaching_assignments', 'acad.group_tutors', 'acad.timetable_sessions'], true],
    ];

    foreach ($series as $name => [$elements, $copyTeaching]) {
        $copyTimes = [];
        $revertTimes = [];

        for ($run = 1; $run <= 3; $run++) {
            $rollover = app(TenantContext::class)->runFor($tenant->id, function () use ($target, $source, $admin, $elements, $copyTeaching): YearRollover {
                Auth::setUser($admin);

                return YearRollover::create([
                    'academic_year_id' => $target->id,
                    'source_academic_year_id' => $source->id,
                    'status' => YearRolloverStatus::Pendiente,
                    'elements' => $elements,
                    'copy_teaching_assignments' => $copyTeaching,
                ]);
            });

            $started = microtime(true);
            app(TenantContext::class)->runFor($tenant->id, function () use ($rollover): void {
                Auth::forgetUser();
                app(RolloverExecutor::class)->run($rollover->id);
            });
            $copyTimes[] = microtime(true) - $started;

            expect(R::byId($s, $rollover->public_id)->status)->toBe(YearRolloverStatus::Completado);

            app(TenantContext::class)->runFor($tenant->id, function () use ($rollover, $admin): void {
                $row = YearRollover::query()->findOrFail($rollover->id);
                Auth::setUser($admin);
                $row->status = YearRolloverStatus::Revirtiendo;
                $row->reverted_by = $admin->id;
                $row->save();
            });

            $started = microtime(true);
            app(TenantContext::class)->runFor($tenant->id, function () use ($rollover): void {
                Auth::forgetUser();
                app(RolloverExecutor::class)->runRevert($rollover->id);
            });
            $revertTimes[] = microtime(true) - $started;

            expect(R::byId($s, $rollover->public_id)->status)->toBe(YearRolloverStatus::Revertido);
        }

        fwrite(STDERR, sprintf(
            "\n[CA-CURSO-149] %s: copia mediana %.1f s (%s), reversión mediana %.1f s (%s)\n",
            $name,
            rolloverMedian($copyTimes),
            implode(' / ', array_map(fn (float $t): string => sprintf('%.1f', $t), $copyTimes)),
            rolloverMedian($revertTimes),
            implode(' / ', array_map(fn (float $t): string => sprintf('%.1f', $t), $revertTimes)),
        ));
    }
})->skip(fn (): bool => getenv('ROLLOVER_MEASURE') !== '1', 'Medición de CA-CURSO-149: ejecútala con ROLLOVER_MEASURE=1 (tarda varios minutos y construye miles de filas).');
