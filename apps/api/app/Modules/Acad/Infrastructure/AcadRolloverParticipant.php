<?php

namespace App\Modules\Acad\Infrastructure;

use App\Modules\Acad\Application\TimetableAnchor;
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
use App\Modules\Acad\Domain\Models\TimetableFrame;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Modules\Core\Domain\UserDirectory;
use App\Modules\Curso\Domain\AcademicYearRolloverElement;
use App\Modules\Curso\Domain\AcademicYearRolloverElementReport;
use App\Modules\Curso\Domain\AcademicYearRolloverElementResult;
use App\Modules\Curso\Domain\AcademicYearRolloverIssue;
use App\Modules\Curso\Domain\AcademicYearRolloverItem;
use App\Modules\Curso\Domain\AcademicYearRolloverParticipant;
use App\Modules\Curso\Domain\AcademicYearRolloverPlan;
use App\Modules\Curso\Domain\AcademicYearRolloverReport;
use App\Modules\Curso\Domain\AcademicYearRolloverResult;
use App\Modules\Curso\Domain\AcademicYearRolloverReversal;
use App\Modules\Curso\Domain\RolloverBlockedException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * REQ-ACAD/funcional.md §21 (`1.12b`, `REQ-CURSO-002`, `RN-ACAD-145` a `-157`): participante de `acad` en la
 * apertura del curso siguiente. Implementa `AcademicYearRolloverParticipant` (contrato de `curso`,
 * `RN-CURSO-61`), declara los doce elementos de §21.2 y se registra en `AcadServiceProvider::boot()`. Lee y escribe
 * **solo tablas de `acad`**; las fechas del curso las recibe en el plan, nunca de `academic_years`; el estado de los
 * usuarios, de `UserDirectory::activeIdsAmong()` (`RN-ACAD-157`), nunca de `users` (`AR-01`, `INV-007`).
 *
 * **Fidelidad** (`RN-ACAD-146`): lo copiado reproduce el origen —mismos códigos, atributos, posiciones y relaciones
 * entre filas copiadas— con identificadores nuevos. **Reglas por construcción** (`RN-ACAD-147`): el origen las
 * cumple y la copia conserva el conjunto, así que se cumplen en el destino sin revalidar una a una; por eso las
 * sesiones se insertan sin pasar por la detección de conflictos del servicio de sesiones, bajo el ancla
 * `timetables` (`RN-ACAD-149`).
 *
 * **Escritura** (`RN-ACAD-149`): por instancia a través de los modelos (cada fila deja su `created`,
 * `RN-CURSO-79`), de padres a hijos, en el orden de `elements()`. Nunca escribe filas del origen
 * (`RN-CURSO-77`).
 */
final class AcadRolloverParticipant implements AcademicYearRolloverParticipant
{
    private const MODULE = 'acad';

    /** `RN-CURSO-82`: el único paquete que existe. */
    private const BUNDLE = 'teaching_assignments';

    /**
     * §21.2, en el orden en que se declaran y se escriben: nombre ⇒ [requires, permiso de alta, paquete].
     *
     * @var array<string, array{0: list<string>, 1: string, 2: string|null}>
     */
    private const ELEMENTS = [
        'education_levels' => [[], 'estructura_academica.crear', null],
        'courses' => [['education_levels'], 'estructura_academica.crear', null],
        'groups' => [['courses'], 'estructura_academica.crear', null],
        'subgroups' => [['groups'], 'estructura_academica.crear', null],
        'subjects' => [['courses'], 'estructura_academica.crear', null],
        'group_subjects' => [['groups', 'subjects'], 'estructura_academica.actualizar', null],
        'academic_periods' => [['education_levels'], 'configuracion_academica.crear', null],
        'grading_scales' => [[], 'configuracion_academica.crear', null],
        'timetable_frames' => [['education_levels'], 'horarios.crear', null],
        'teaching_assignments' => [['group_subjects', 'subgroups'], 'asignacion_docente.crear', self::BUNDLE],
        'group_tutors' => [['groups'], 'asignacion_docente.actualizar', self::BUNDLE],
        'timetable_sessions' => [['teaching_assignments'], 'horarios.crear', self::BUNDLE],
    ];

    /** Elemento ⇒ modelo cuyas filas vivas del destino cuentan para `target_not_empty` (`RN-ACAD-148`). */
    private const TARGET_MODELS = [
        'education_levels' => EducationLevel::class,
        'courses' => Course::class,
        'groups' => Group::class,
        'subgroups' => Subgroup::class,
        'subjects' => Subject::class,
        'group_subjects' => GroupSubject::class,
        'academic_periods' => AcademicPeriod::class,
        'grading_scales' => GradingScale::class,
        'timetable_frames' => TimetableFrame::class,
        'teaching_assignments' => TeachingAssignment::class,
        'group_tutors' => GroupTutor::class,
        'timetable_sessions' => TimetableSession::class,
    ];

    public function __construct(
        private readonly UserDirectory $users,
        private readonly TimetableAnchor $anchor,
    ) {}

    public function moduleCode(): string
    {
        return self::MODULE;
    }

    public function elements(): array
    {
        $elements = [];

        foreach (self::ELEMENTS as $name => [$requires, $permission, $bundle]) {
            $elements[] = new AcademicYearRolloverElement(
                self::MODULE.".{$name}",
                "acad.rollover.elements.{$name}",
                array_map(static fn (string $required): string => self::MODULE.".{$required}", $requires),
                $permission,
                $bundle,
            );
        }

        return $elements;
    }

    // ───────────────────────────── simulación ─────────────────────────────

    public function preview(AcademicYearRolloverPlan $plan): AcademicYearRolloverReport
    {
        $source = $plan->source->id;
        $active = $this->activeUsers($plan);
        $reports = [];

        foreach (array_keys(self::ELEMENTS) as $name) {
            if (! $plan->includes(self::MODULE.".{$name}")) {
                continue;
            }

            $code = self::MODULE.".{$name}";
            $issues = [];
            $skipped = [];
            $adjusted = [];
            $dateRange = null;

            $toCopy = match ($name) {
                'academic_periods' => $this->previewDated(AcademicPeriod::query()->where('academic_year_id', $source)->get(), 'starts_on', 'ends_on', $plan, $code, $issues, $dateRange),
                'timetable_frames' => $this->previewFrames($plan, $code, $issues, $dateRange),
                'teaching_assignments' => $this->previewAssignments($source, $active, $skipped),
                'group_tutors' => $this->previewTutors($source, $active, $skipped),
                'timetable_sessions' => $this->previewSessions($plan, $active, $skipped, $adjusted),
                default => $this->sourceCount($name, $source),
            };

            $targetCount = (int) $this->targetModel($name)::query()->where('academic_year_id', $plan->target->id)->count();

            if ($targetCount > 0) {
                array_unshift($issues, $this->targetNotEmpty($code, $targetCount));
            }

            $reports[] = new AcademicYearRolloverElementReport($code, $toCopy, $skipped, $adjusted, $dateRange, $issues);
        }

        return new AcademicYearRolloverReport($reports);
    }

    /** @return int filas de un elemento sin tratamiento especial */
    private function sourceCount(string $name, int $source): int
    {
        return (int) $this->targetModel($name)::query()->where('academic_year_id', $source)->count();
    }

    /**
     * Períodos: cuenta, rango desplazado e incidencia `dates_outside_target` (`RN-ACAD-148`, `RN-CURSO-72`).
     *
     * @param  iterable<Model>  $rows
     * @param  list<AcademicYearRolloverIssue>  $issues
     * @param  array{starts_on: string, ends_on: string}|null  $dateRange
     */
    private function previewDated(iterable $rows, string $from, string $to, AcademicYearRolloverPlan $plan, string $code, array &$issues, ?array &$dateRange): int
    {
        $count = 0;
        $outside = 0;
        $starts = [];
        $ends = [];

        foreach ($rows as $row) {
            $count++;
            $start = $this->shift($row->getAttribute($from), $plan);
            $end = $this->shift($row->getAttribute($to), $plan);

            if ($start->lessThan($plan->target->startsOn) || $end->greaterThan($plan->target->endsOn)) {
                $outside++;
            }

            $starts[] = $start;
            $ends[] = $end;
        }

        if ($starts !== []) {
            $dateRange = ['starts_on' => min($starts)->toDateString(), 'ends_on' => max($ends)->toDateString()];
        }

        if ($outside > 0 && $dateRange !== null) {
            $issues[] = new AcademicYearRolloverIssue(
                'acad.rollover.dates_outside_target',
                (string) __('acad.rollover.dates_outside_target', ['element' => $this->label($code), 'count' => $outside, 'starts_on' => $dateRange['starts_on'], 'ends_on' => $dateRange['ends_on']]),
                ['element' => $code, 'count' => $outside, 'starts_on' => $dateRange['starts_on'], 'ends_on' => $dateRange['ends_on']],
                $code,
            );
        }

        return $count;
    }

    /**
     * Marcos: cuenta de marcos; las fechas solo en los `especial` (`RN-ACAD-111`).
     *
     * @param  list<AcademicYearRolloverIssue>  $issues
     * @param  array{starts_on: string, ends_on: string}|null  $dateRange
     */
    private function previewFrames(AcademicYearRolloverPlan $plan, string $code, array &$issues, ?array &$dateRange): int
    {
        $frames = TimetableFrame::query()->where('academic_year_id', $plan->source->id)->get();
        $special = $frames->where('frame_type', 'especial');

        $this->previewDated($special, 'valid_from', 'valid_to', $plan, $code, $issues, $dateRange);

        return $frames->count();
    }

    /**
     * @param  array<int, int>  $active  user_id ⇒ user_id de los usuarios activos
     * @param  list<array{reason: string, count: int}>  $skipped
     */
    private function previewAssignments(int $source, array $active, array &$skipped): int
    {
        $copy = 0;
        $inactive = 0;

        foreach (TeachingAssignment::query()->where('academic_year_id', $source)->pluck('user_id') as $userId) {
            isset($active[(int) $userId]) ? $copy++ : $inactive++;
        }

        if ($inactive > 0) {
            $skipped[] = ['reason' => 'teacher_inactive', 'count' => $inactive];
        }

        return $copy;
    }

    /**
     * @param  array<int, int>  $active
     * @param  list<array{reason: string, count: int}>  $skipped
     */
    private function previewTutors(int $source, array $active, array &$skipped): int
    {
        $copy = 0;
        $inactive = 0;

        foreach (GroupTutor::query()->where('academic_year_id', $source)->pluck('user_id') as $userId) {
            isset($active[(int) $userId]) ? $copy++ : $inactive++;
        }

        if ($inactive > 0) {
            $skipped[] = ['reason' => 'tutor_inactive', 'count' => $inactive];
        }

        return $copy;
    }

    /**
     * RN-ACAD-155: sin marcos copiados, todas las sesiones se omiten (`frames_not_copied`); una sesión cuya asignación
     * se omite, con el mismo motivo que ella; una con el aula dada de baja se copia sin aula (`adjusted`).
     *
     * @param  array<int, int>  $active
     * @param  list<array{reason: string, count: int}>  $skipped
     * @param  list<array{reason: string, count: int}>  $adjusted
     */
    private function previewSessions(AcademicYearRolloverPlan $plan, array $active, array &$skipped, array &$adjusted): int
    {
        $sessions = TimetableSession::query()->where('academic_year_id', $plan->source->id)->get(['id', 'teaching_assignment_id', 'facility_id']);

        if (! $plan->includes(self::MODULE.'.timetable_frames')) {
            if ($sessions->count() > 0) {
                $skipped[] = ['reason' => 'frames_not_copied', 'count' => $sessions->count()];
            }

            return 0;
        }

        $owners = TeachingAssignment::query()->where('academic_year_id', $plan->source->id)->pluck('user_id', 'id');
        $aliveFacilities = $this->aliveFacilities($sessions->pluck('facility_id')->filter()->all());
        $copy = 0;
        $inactive = 0;
        $withoutRoom = 0;

        foreach ($sessions as $session) {
            if (! isset($active[(int) ($owners[$session->teaching_assignment_id] ?? 0)])) {
                $inactive++;

                continue;
            }

            $copy++;

            if ($session->facility_id !== null && ! isset($aliveFacilities[(int) $session->facility_id])) {
                $withoutRoom++;
            }
        }

        if ($inactive > 0) {
            $skipped[] = ['reason' => 'teacher_inactive', 'count' => $inactive];
        }

        if ($withoutRoom > 0) {
            $adjusted[] = ['reason' => 'facility_removed', 'count' => $withoutRoom];
        }

        return $copy;
    }

    // ───────────────────────────── copia ─────────────────────────────

    public function copy(AcademicYearRolloverPlan $plan): AcademicYearRolloverResult
    {
        $source = $plan->source->id;
        $target = $plan->target->id;
        $active = $this->activeUsers($plan);

        /** @var array<string, array<int, int>> $map alias ⇒ [id de origen ⇒ id creado] */
        $map = [];
        /** @var list<AcademicYearRolloverItem> $items */
        $items = [];
        /** @var list<AcademicYearRolloverElementResult> $results */
        $results = [];

        $record = function (string $alias, Model $from, Model $to) use (&$map, &$items): void {
            $map[$alias][(int) $from->getKey()] = (int) $to->getKey();
            $items[] = new AcademicYearRolloverItem($alias, (int) $from->getKey(), (int) $to->getKey());
        };

        foreach (array_keys(self::ELEMENTS) as $name) {
            $code = self::MODULE.".{$name}";

            if (! $plan->includes($code)) {
                continue;
            }

            $skipped = [];
            $adjusted = [];
            $copied = 0;

            switch ($name) {
                case 'education_levels':
                    foreach (EducationLevel::query()->where('academic_year_id', $source)->orderBy('id')->get() as $row) {
                        $record('education_level', $row, EducationLevel::create(['academic_year_id' => $target] + $this->attributes($row, ['code', 'name_translations', 'stage', 'regime', 'position'])));
                        $copied++;
                    }
                    break;

                case 'courses':
                    foreach (Course::query()->where('academic_year_id', $source)->orderBy('id')->get() as $row) {
                        $record('course', $row, Course::create([
                            'academic_year_id' => $target,
                            'education_level_id' => $this->mapped($map, 'education_level', $row->education_level_id),
                        ] + $this->attributes($row, ['code', 'name_translations', 'position'])));
                        $copied++;
                    }
                    break;

                case 'groups':
                    foreach (Group::query()->where('academic_year_id', $source)->orderBy('id')->get() as $row) {
                        $record('group', $row, Group::create([
                            'academic_year_id' => $target,
                            'course_id' => $this->mapped($map, 'course', $row->course_id),
                        ] + $this->attributes($row, ['code', 'name', 'position'])));
                        $copied++;
                    }
                    break;

                case 'subgroups':
                    foreach (Subgroup::query()->where('academic_year_id', $source)->orderBy('id')->get() as $row) {
                        $record('subgroup', $row, Subgroup::create([
                            'academic_year_id' => $target,
                            'group_id' => $this->mapped($map, 'group', $row->group_id),
                        ] + $this->attributes($row, ['code', 'name', 'position'])));
                        $copied++;
                    }
                    break;

                case 'subjects':
                    foreach (Subject::query()->where('academic_year_id', $source)->orderBy('id')->get() as $row) {
                        $record('subject', $row, Subject::create([
                            'academic_year_id' => $target,
                            'course_id' => $this->mapped($map, 'course', $row->course_id),
                        ] + $this->attributes($row, ['code', 'name_translations', 'position'])));
                        $copied++;
                    }
                    break;

                case 'group_subjects':
                    foreach (GroupSubject::query()->where('academic_year_id', $source)->orderBy('id')->get() as $row) {
                        $record('group_subject', $row, GroupSubject::create([
                            'academic_year_id' => $target,
                            'course_id' => $this->mapped($map, 'course', $row->course_id),
                            'group_id' => $this->mapped($map, 'group', $row->group_id),
                            'subject_id' => $this->mapped($map, 'subject', $row->subject_id),
                        ]));
                        $copied++;
                    }
                    break;

                case 'academic_periods':
                    foreach (AcademicPeriod::query()->where('academic_year_id', $source)->orderBy('id')->get() as $row) {
                        $record('academic_period', $row, AcademicPeriod::create([
                            'academic_year_id' => $target,
                            'education_level_id' => $this->mapped($map, 'education_level', $row->education_level_id),
                            'starts_on' => $this->shift($row->starts_on, $plan)->toDateString(),
                            'ends_on' => $this->shift($row->ends_on, $plan)->toDateString(),
                        ] + $this->attributes($row, ['period_type', 'name_translations', 'position'])));
                        $copied++;
                    }
                    break;

                case 'grading_scales':
                    foreach (GradingScale::query()->where('academic_year_id', $source)->orderBy('id')->get() as $scale) {
                        $newScale = GradingScale::create(['academic_year_id' => $target] + $this->attributes($scale, ['code', 'name_translations', 'scale_type', 'min_value', 'max_value', 'decimals', 'passing_min_value']));
                        $record('grading_scale', $scale, $newScale);
                        $copied++;

                        foreach (GradingScaleValue::query()->where('grading_scale_id', $scale->id)->orderBy('id')->get() as $value) {
                            $record('grading_scale_value', $value, GradingScaleValue::create([
                                'academic_year_id' => $target,
                                'grading_scale_id' => $newScale->getKey(),
                            ] + $this->attributes($value, ['scale_type', 'code', 'label_translations', 'position', 'is_passing', 'range_min', 'range_max'])));
                        }
                    }
                    break;

                case 'timetable_frames':
                    // RN-ACAD-149: el ancla `timetables` del destino en `FOR UPDATE` (crear-o-leer), antes de escribir marcos.
                    $this->anchor->lock($target);

                    foreach (TimetableFrame::query()->where('academic_year_id', $source)->orderBy('id')->get() as $frame) {
                        $newFrame = TimetableFrame::create([
                            'academic_year_id' => $target,
                            'education_level_id' => $this->mapped($map, 'education_level', $frame->education_level_id),
                            'valid_from' => $frame->valid_from === null ? null : $this->shift($frame->valid_from, $plan)->toDateString(),
                            'valid_to' => $frame->valid_to === null ? null : $this->shift($frame->valid_to, $plan)->toDateString(),
                        ] + $this->attributes($frame, ['frame_type', 'name_translations']));
                        $record('timetable_frame', $frame, $newFrame);
                        $copied++;

                        foreach (TimeSlot::query()->where('timetable_frame_id', $frame->id)->orderBy('id')->get() as $slot) {
                            $record('time_slot', $slot, TimeSlot::create([
                                'academic_year_id' => $target,
                                'timetable_frame_id' => $newFrame->getKey(),
                            ] + $this->attributes($slot, ['weekday', 'starts_at', 'ends_at'])));
                        }
                    }
                    break;

                case 'teaching_assignments':
                    foreach (TeachingAssignment::query()->where('academic_year_id', $source)->orderBy('id')->get() as $row) {
                        if (! isset($active[(int) $row->user_id])) {
                            $this->count($skipped, 'teacher_inactive');

                            continue;
                        }

                        $record('teaching_assignment', $row, TeachingAssignment::create([
                            'academic_year_id' => $target,
                            'user_id' => $row->user_id,
                            'group_id' => $this->mapped($map, 'group', $row->group_id),
                            'group_subject_id' => $this->mapped($map, 'group_subject', $row->group_subject_id),
                            'subgroup_id' => $row->subgroup_id === null ? null : $this->mapped($map, 'subgroup', $row->subgroup_id),
                        ]));
                        $copied++;
                    }
                    break;

                case 'group_tutors':
                    foreach (GroupTutor::query()->where('academic_year_id', $source)->orderBy('id')->get() as $row) {
                        if (! isset($active[(int) $row->user_id])) {
                            $this->count($skipped, 'tutor_inactive');

                            continue;
                        }

                        $record('group_tutor', $row, GroupTutor::create([
                            'academic_year_id' => $target,
                            'group_id' => $this->mapped($map, 'group', $row->group_id),
                            'user_id' => $row->user_id,
                        ]));
                        $copied++;
                    }
                    break;

                case 'timetable_sessions':
                    $copied = $this->copySessions($plan, $map, $record, $skipped, $adjusted);
                    break;
            }

            $results[] = new AcademicYearRolloverElementResult($code, $copied, $skipped, $adjusted);
        }

        return new AcademicYearRolloverResult($results, $items);
    }

    /**
     * RN-ACAD-155. Las sesiones se insertan sin la detección de conflictos del servicio (`RN-ACAD-147`): omitir una
     * sesión o quitarle el aula nunca crea un conflicto que el origen no tuviera. El ancla ya está bloqueada si se
     * copiaron los marcos (`timetable_frames` va antes en el orden).
     *
     * @param  array<string, array<int, int>>  $map
     * @param  list<array{reason: string, count: int}>  $skipped
     * @param  list<array{reason: string, count: int}>  $adjusted
     */
    private function copySessions(AcademicYearRolloverPlan $plan, array $map, Closure $record, array &$skipped, array &$adjusted): int
    {
        $sessions = TimetableSession::query()->where('academic_year_id', $plan->source->id)->orderBy('id')->get();

        if (! $plan->includes(self::MODULE.'.timetable_frames')) {
            if ($sessions->count() > 0) {
                $skipped[] = ['reason' => 'frames_not_copied', 'count' => $sessions->count()];
            }

            return 0;
        }

        $aliveFacilities = $this->aliveFacilities($sessions->pluck('facility_id')->filter()->all());
        $copied = 0;

        foreach ($sessions as $session) {
            // Una sesión cuya asignación se omitió (profesor inactivo) se omite con el mismo motivo (RN-ACAD-155 (2)).
            $assignmentId = $map['teaching_assignment'][(int) $session->teaching_assignment_id] ?? null;

            if ($assignmentId === null) {
                $this->count($skipped, 'teacher_inactive');

                continue;
            }

            $withRoom = $session->facility_id !== null && isset($aliveFacilities[(int) $session->facility_id]);

            if ($session->facility_id !== null && ! $withRoom) {
                $this->count($adjusted, 'facility_removed');
            }

            $record('timetable_session', $session, TimetableSession::create([
                'academic_year_id' => $plan->target->id,
                'time_slot_id' => $this->mapped($map, 'time_slot', $session->time_slot_id),
                'teaching_assignment_id' => $assignmentId,
                'facility_id' => $withRoom ? $session->facility_id : null,
                'facility_type' => $withRoom ? $session->facility_type : null,
            ]));
            $copied++;
        }

        return $copied;
    }

    // ───────────────────────────── reversión ─────────────────────────────

    /**
     * RN-ACAD-150: una incidencia si alguna fila **viva** del destino que **no** está en los *items* de la copia
     * depende de una fila de la copia.
     */
    public function checkRevert(AcademicYearRolloverReversal $reversal): array
    {
        $copied = $this->copiedIds($reversal);
        $target = $reversal->target->id;
        $dependents = [];
        $count = 0;

        foreach ($this->dependencyRules() as [$parent, $childAlias, $childModel, $column]) {
            $parents = $copied[$parent] ?? [];

            if ($parents === []) {
                continue;
            }

            $query = $childModel::query()->where('academic_year_id', $target)->whereIn($column, $parents);

            if (($copied[$childAlias] ?? []) !== []) {
                $query->whereNotIn('id', $copied[$childAlias]);
            }

            $found = (int) $query->count();

            if ($found > 0) {
                $count += $found;
                $dependents[$childAlias] = true;
            }
        }

        if ($count === 0) {
            return [];
        }

        $params = ['dependents' => array_keys($dependents), 'count' => $count];

        return [new AcademicYearRolloverIssue('acad.rollover.revert_has_dependents', (string) __('acad.rollover.revert_has_dependents', ['count' => $count]), $params)];
    }

    /**
     * RN-ACAD-151: borrado lógico por instancia, de hijos a padres, de las filas de los *items* que sigan vivas. Una
     * fila que ya no existe (o ya estaba borrada) se trata como ya borrada.
     */
    public function revert(AcademicYearRolloverReversal $reversal): void
    {
        $copied = $this->copiedIds($reversal);

        // El ancla se toma antes si hay marcos, franjas o sesiones; nunca se borra (`datos.md §9.1`).
        if (($copied['timetable_frame'] ?? []) !== [] || ($copied['time_slot'] ?? []) !== [] || ($copied['timetable_session'] ?? []) !== []) {
            $this->anchor->lock($reversal->target->id);
        }

        $order = [
            'timetable_session' => TimetableSession::class,
            'group_tutor' => GroupTutor::class,
            'teaching_assignment' => TeachingAssignment::class,
            'time_slot' => TimeSlot::class,
            'timetable_frame' => TimetableFrame::class,
            'grading_scale_value' => GradingScaleValue::class,
            'grading_scale' => GradingScale::class,
            'academic_period' => AcademicPeriod::class,
            'group_subject' => GroupSubject::class,
            'subgroup' => Subgroup::class,
            'subject' => Subject::class,
            'group' => Group::class,
            'course' => Course::class,
            'education_level' => EducationLevel::class,
        ];

        // RN-CURSO-70 (revisión de `db-reviewer`, B4): `checkRevert()` es de solo lectura y corre antes de estos bloqueos, así
        // que un hijo creado entre medias se le escaparía. Con el ancla y los padres copiados bloqueados `FOR UPDATE` (un
        // alta concurrente de un hijo espera a su `FOR KEY SHARE` sobre el padre), la comprobación se repite y, si ya hay
        // dependientes, toda la reversión se revierte y la copia vuelve a `completado` con su `revert_failure`.
        $parents = array_unique(array_map(static fn (array $rule): string => $rule[0], $this->dependencyRules()));

        foreach (array_reverse(array_keys($order)) as $alias) {
            $ids = $copied[$alias] ?? [];

            if ($ids !== [] && in_array($alias, $parents, true)) {
                $order[$alias]::query()->where('academic_year_id', $reversal->target->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
            }
        }

        $issues = $this->checkRevert($reversal);

        if ($issues !== []) {
            throw new RolloverBlockedException($issues);
        }

        foreach ($order as $alias => $model) {
            $ids = $copied[$alias] ?? [];

            if ($ids === []) {
                continue;
            }

            foreach ($model::query()->where('academic_year_id', $reversal->target->id)->whereIn('id', $ids)->orderByDesc('id')->get() as $row) {
                $row->delete();
            }
        }
    }

    /**
     * Padre ⇒ hijos que lo referencian (§21.3, `RN-ACAD-150`): [alias del padre, alias del hijo, modelo del hijo, columna].
     *
     * @return list<array{0: string, 1: string, 2: class-string<Model>, 3: string}>
     */
    private function dependencyRules(): array
    {
        return [
            ['education_level', 'course', Course::class, 'education_level_id'],
            ['education_level', 'academic_period', AcademicPeriod::class, 'education_level_id'],
            ['education_level', 'timetable_frame', TimetableFrame::class, 'education_level_id'],
            ['course', 'group', Group::class, 'course_id'],
            ['course', 'subject', Subject::class, 'course_id'],
            ['group', 'subgroup', Subgroup::class, 'group_id'],
            ['group', 'group_subject', GroupSubject::class, 'group_id'],
            ['group', 'teaching_assignment', TeachingAssignment::class, 'group_id'],
            ['group', 'group_tutor', GroupTutor::class, 'group_id'],
            ['group_subject', 'teaching_assignment', TeachingAssignment::class, 'group_subject_id'],
            ['subgroup', 'teaching_assignment', TeachingAssignment::class, 'subgroup_id'],
            ['timetable_frame', 'time_slot', TimeSlot::class, 'timetable_frame_id'],
            ['time_slot', 'timetable_session', TimetableSession::class, 'time_slot_id'],
            ['teaching_assignment', 'timetable_session', TimetableSession::class, 'teaching_assignment_id'],
            ['grading_scale', 'grading_scale_value', GradingScaleValue::class, 'grading_scale_id'],
        ];
    }

    /**
     * @return array<string, list<int>> alias ⇒ identificadores creados por la copia
     */
    private function copiedIds(AcademicYearRolloverReversal $reversal): array
    {
        $ids = [];

        foreach ($reversal->items as $item) {
            $ids[$item->entityType][] = $item->targetId;
        }

        return $ids;
    }

    // ───────────────────────────── utilidades ─────────────────────────────

    /**
     * RN-ACAD-157: una única llamada a `UserDirectory::activeIdsAmong()` por `preview()` o `copy()`, con los
     * `user_id` distintos de las asignaciones y tutorías vivas del origen (solo si el plan incluye el paquete).
     *
     * @return array<int, int> usuarios activos, por id
     */
    private function activeUsers(AcademicYearRolloverPlan $plan): array
    {
        $assignments = $plan->includes(self::MODULE.'.teaching_assignments') || $plan->includes(self::MODULE.'.timetable_sessions');
        $tutors = $plan->includes(self::MODULE.'.group_tutors');

        if (! $assignments && ! $tutors) {
            return [];
        }

        $ids = [];

        if ($assignments) {
            $ids = TeachingAssignment::query()->where('academic_year_id', $plan->source->id)->pluck('user_id')->all();
        }

        if ($tutors) {
            $ids = array_merge($ids, GroupTutor::query()->where('academic_year_id', $plan->source->id)->pluck('user_id')->all());
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        $active = $this->users->activeIdsAmong($ids);

        return array_combine($active, $active);
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, int> aulas no dadas de baja, por id
     */
    private function aliveFacilities(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $alive = array_map('intval', Facility::query()->whereIn('id', $ids)->pluck('id')->all());

        return array_combine($alive, $alive);
    }

    /** `RN-CURSO-72`: la fecha del origen desplazada los días del plan. */
    private function shift(mixed $date, AcademicYearRolloverPlan $plan): CarbonInterface
    {
        return CarbonImmutable::parse($date)->startOfDay()->addDays($plan->dateOffsetDays);
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function attributes(Model $row, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = $row->getAttribute($column);
        }

        return $values;
    }

    /** @param array<string, array<int, int>> $map */
    private function mapped(array $map, string $alias, int|string|null $sourceId): int
    {
        return $map[$alias][(int) $sourceId] ?? throw new LogicException("La copia no encuentra la fila de «{$alias}» (id {$sourceId}) copiada: la selección no incluye su requisito (RN-CURSO-64) o el origen es inconsistente.");
    }

    /** @param list<array{reason: string, count: int}> $rows */
    private function count(array &$rows, string $reason): void
    {
        foreach ($rows as $index => $row) {
            if ($row['reason'] === $reason) {
                $rows[$index]['count']++;

                return;
            }
        }

        $rows[] = ['reason' => $reason, 'count' => 1];
    }

    /** @return class-string<Model> */
    private function targetModel(string $name): string
    {
        return self::TARGET_MODELS[$name];
    }

    private function targetNotEmpty(string $code, int $count): AcademicYearRolloverIssue
    {
        return new AcademicYearRolloverIssue(
            'acad.rollover.target_not_empty',
            (string) __('acad.rollover.target_not_empty', ['element' => $this->label($code), 'count' => $count]),
            ['element' => $code, 'count' => $count],
            $code,
        );
    }

    private function label(string $code): string
    {
        return (string) __('acad.rollover.elements.'.substr($code, strlen(self::MODULE) + 1));
    }
}
