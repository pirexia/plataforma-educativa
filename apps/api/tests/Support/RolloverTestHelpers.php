<?php

namespace Tests\Support;

use App\Models\Person;
use App\Models\User;
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
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * REQ-CURSO-002 (1.12b): ayudantes de los tests de la apertura del curso siguiente. Construye el «origen de
 * prueba» y el «destino de prueba» de `REQ-CURSO/funcional.md §13.8` (y los datos de docencia de
 * `REQ-ACAD/funcional.md §21.5`) directamente por los modelos, dentro del contexto del centro.
 */
final class RolloverTestHelpers
{
    /**
     * Centro con `acad` contratado, administrador, el curso origen `activo` 2026-09-01 a 2027-08-31 con su
     * estructura (más una fila borrada de cada tabla) y el destino `planificacion` 2027-09-01 a 2028-08-31, vacío.
     *
     * @return array{tenant: Tenant, admin: User, source: AcademicYear, target: AcademicYear, data: array<string, mixed>}
     */
    public static function scenario(string $slug, bool $teaching = false, AcademicYearStatus $sourceStatus = AcademicYearStatus::Activo): array
    {
        [$tenant, $admin] = provisionCoreTenant($slug);
        contractModule($tenant, 'acad');
        // El administrador aprovisionado queda `pendiente` hasta su primer acceso; los trabajos solo actúan como un usuario activo (RN-CURSO-83).
        self::setUserStatus($tenant, $admin, UserStatus::Activo);

        // Los datos se crean con el curso activo (el disparador de ADR-057 impide escribir en uno cerrado) y después se fija el estado.
        $source = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');
        $target = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-08-31');
        $data = self::structure($tenant, $source);

        if ($teaching) {
            $data += self::teaching($tenant, $source, $data);
        }

        if ($sourceStatus !== AcademicYearStatus::Activo) {
            CursoTestHelpers::setStatus($tenant, $source, $sourceStatus);
            $source = app(TenantContext::class)->runFor($tenant->id, fn () => $source->refresh());
        }

        return compact('tenant', 'admin', 'source', 'target', 'data');
    }

    /**
     * Dos etapas, tres cursos de etapa, cuatro grupos, dos subgrupos, cinco asignaturas, ocho asignaturas de grupo,
     * tres períodos, una escala numérica y una cualitativa con tres valores, un marco ordinaria con diez franjas y un
     * marco especial del 2027-06-01 al 2027-06-30; y una fila borrada de cada tabla.
     *
     * @return array<string, mixed>
     */
    public static function structure(Tenant $tenant, AcademicYear $year): array
    {
        return app(TenantContext::class)->runFor($tenant->id, function () use ($year): array {
            $y = $year->id;
            $level = fn (string $code, int $pos): EducationLevel => EducationLevel::create(['academic_year_id' => $y, 'code' => $code, 'name_translations' => ['es-ES' => "Etapa {$code}"], 'stage' => 'eso', 'regime' => 'concertado', 'position' => $pos]);
            $l1 = $level('ESO', 1);
            $l2 = $level('BACH', 2);
            $levelGone = $level('FP', 3);
            $levelGone->delete();

            $course = fn (EducationLevel $l, string $code, int $pos): Course => Course::create(['academic_year_id' => $y, 'education_level_id' => $l->id, 'code' => $code, 'name_translations' => ['es-ES' => "Curso {$code}"], 'position' => $pos]);
            $c1 = $course($l1, '1ESO', 1);
            $c2 = $course($l1, '2ESO', 2);
            $c3 = $course($l2, '1BACH', 1);
            $course($l2, '2BACH', 2)->delete();

            $group = fn (Course $c, string $code, int $pos): Group => Group::create(['academic_year_id' => $y, 'course_id' => $c->id, 'code' => $code, 'name' => "Grupo {$code}", 'position' => $pos]);
            $g1 = $group($c1, 'A', 1);
            $g2 = $group($c1, 'B', 2);
            $g3 = $group($c2, 'A', 1);
            $g4 = $group($c3, 'A', 1);
            $group($c3, 'B', 2)->delete();

            $subgroup = fn (Group $g, string $code): Subgroup => Subgroup::create(['academic_year_id' => $y, 'group_id' => $g->id, 'code' => $code, 'name' => "Desdoble {$code}", 'position' => 1]);
            $s1 = $subgroup($g1, 'D1');
            $s2 = $subgroup($g2, 'D1');
            $subgroup($g3, 'D2')->delete();

            $subject = fn (Course $c, string $code, int $pos): Subject => Subject::create(['academic_year_id' => $y, 'course_id' => $c->id, 'code' => $code, 'name_translations' => ['es-ES' => "Asignatura {$code}"], 'position' => $pos]);
            $m1 = $subject($c1, 'MAT', 1);
            $m2 = $subject($c1, 'LEN', 2);
            $m3 = $subject($c1, 'ING', 3);
            $m4 = $subject($c2, 'FIS', 1);
            $m5 = $subject($c3, 'QUI', 1);
            $subject($c3, 'BIO', 2)->delete();

            $gs = fn (Course $c, Group $g, Subject $s): GroupSubject => GroupSubject::create(['academic_year_id' => $y, 'course_id' => $c->id, 'group_id' => $g->id, 'subject_id' => $s->id]);
            $groupSubjects = [
                $gs($c1, $g1, $m1), $gs($c1, $g1, $m2), $gs($c1, $g1, $m3),
                $gs($c1, $g2, $m1), $gs($c1, $g2, $m2), $gs($c1, $g2, $m3),
                $gs($c2, $g3, $m4), $gs($c3, $g4, $m5),
            ];
            GroupSubject::create(['academic_year_id' => $y, 'course_id' => $c3->id, 'group_id' => $g4->id, 'subject_id' => Subject::query()->where('academic_year_id', $y)->where('code', 'BIO')->withTrashed()->value('id')])->delete();

            $period = fn (EducationLevel $l, string $from, string $to, int $pos): AcademicPeriod => AcademicPeriod::create(['academic_year_id' => $y, 'education_level_id' => $l->id, 'period_type' => 'trimestre', 'name_translations' => ['es-ES' => "Trimestre {$pos}"], 'starts_on' => $from, 'ends_on' => $to, 'position' => $pos]);
            $periods = [
                $period($l1, '2026-09-08', '2026-12-22', 1),
                $period($l1, '2027-01-08', '2027-03-26', 2),
                $period($l1, '2027-04-12', '2027-06-22', 3),
            ];
            $period($l2, '2026-09-08', '2026-12-22', 1)->delete();

            $numeric = GradingScale::create(['academic_year_id' => $y, 'code' => 'NUM10', 'name_translations' => ['es-ES' => 'Numérica'], 'scale_type' => 'numerica', 'min_value' => '0', 'max_value' => '10', 'decimals' => 2, 'passing_min_value' => '5']);
            $qualitative = GradingScale::create(['academic_year_id' => $y, 'code' => 'CUAL', 'name_translations' => ['es-ES' => 'Cualitativa'], 'scale_type' => 'cualitativa']);
            $values = [];

            foreach ([['IN', 'Insuficiente', false], ['SU', 'Suficiente', true], ['SB', 'Sobresaliente', true]] as $i => [$code, $label, $passing]) {
                $values[] = GradingScaleValue::create(['academic_year_id' => $y, 'grading_scale_id' => $qualitative->id, 'scale_type' => 'cualitativa', 'code' => $code, 'label_translations' => ['es-ES' => $label], 'position' => $i + 1, 'is_passing' => $passing]);
            }

            GradingScale::create(['academic_year_id' => $y, 'code' => 'BORR', 'name_translations' => ['es-ES' => 'Borrada'], 'scale_type' => 'numerica', 'min_value' => '0', 'max_value' => '10', 'decimals' => 0, 'passing_min_value' => '5'])->delete();

            Timetable::create(['academic_year_id' => $y]);
            $ordinary = TimetableFrame::create(['academic_year_id' => $y, 'education_level_id' => $l1->id, 'frame_type' => 'ordinaria', 'name_translations' => ['es-ES' => 'Horario general']]);
            $slots = [];

            foreach ([1, 2, 3, 4, 5] as $weekday) {
                foreach ([['08:30', '09:25'], ['09:25', '10:20']] as [$from, $to]) {
                    $slots[] = TimeSlot::create(['academic_year_id' => $y, 'timetable_frame_id' => $ordinary->id, 'weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to]);
                }
            }

            $special = TimetableFrame::create(['academic_year_id' => $y, 'education_level_id' => $l1->id, 'frame_type' => 'especial', 'name_translations' => ['es-ES' => 'Jornada reducida'], 'valid_from' => '2027-06-01', 'valid_to' => '2027-06-30']);
            TimeSlot::create(['academic_year_id' => $y, 'timetable_frame_id' => $special->id, 'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00']);
            TimetableFrame::create(['academic_year_id' => $y, 'education_level_id' => $l2->id, 'frame_type' => 'ordinaria', 'name_translations' => ['es-ES' => 'Borrado']])->delete();

            return compact('l1', 'l2', 'c1', 'c2', 'c3', 'g1', 'g2', 'g3', 'g4', 's1', 's2', 'm1', 'm2', 'm3', 'm4', 'm5', 'groupSubjects', 'periods', 'numeric', 'qualitative', 'values', 'ordinary', 'special', 'slots');
        });
    }

    /**
     * Cuatro asignaciones docentes de tres usuarios activos (una en un subgrupo), dos tutorías, seis sesiones (una en un
     * aula dada de baja) y una asignación, una tutoría y una sesión borradas.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function teaching(Tenant $tenant, AcademicYear $year, array $data): array
    {
        $teachers = [self::user($tenant, 'prof1@example.com'), self::user($tenant, 'prof2@example.com'), self::user($tenant, 'prof3@example.com')];

        return app(TenantContext::class)->runFor($tenant->id, function () use ($year, $data, $teachers): array {
            $y = $year->id;
            [$t1, $t2, $t3] = $teachers;
            $assign = fn (User $u, Group $g, GroupSubject $gs, ?Subgroup $s = null): TeachingAssignment => TeachingAssignment::create(['academic_year_id' => $y, 'user_id' => $u->id, 'group_id' => $g->id, 'group_subject_id' => $gs->id, 'subgroup_id' => $s?->id]);
            [$gs1, $gs2, , , , , $gs7] = $data['groupSubjects'];
            $a1 = $assign($t1, $data['g1'], $gs1);
            $a2 = $assign($t2, $data['g1'], $gs2);
            $a3 = $assign($t3, $data['g3'], $gs7);
            $a4 = $assign($t1, $data['g1'], $data['groupSubjects'][2], $data['s1']);
            $assign($t2, $data['g2'], $data['groupSubjects'][3])->delete();

            $tutor1 = GroupTutor::create(['academic_year_id' => $y, 'group_id' => $data['g1']->id, 'user_id' => $t2->id]);
            $tutor2 = GroupTutor::create(['academic_year_id' => $y, 'group_id' => $data['g3']->id, 'user_id' => $t3->id]);
            GroupTutor::create(['academic_year_id' => $y, 'group_id' => $data['g2']->id, 'user_id' => $t1->id])->delete();

            $room = Facility::create(['facility_type' => 'aula', 'code' => 'A1-'.substr(uniqid(), -4), 'name' => 'Aula 1']);
            $goneRoom = Facility::create(['facility_type' => 'aula', 'code' => 'A2-'.substr(uniqid(), -4), 'name' => 'Aula 2']);

            $slots = $data['slots'];
            $session = fn (TimeSlot $slot, TeachingAssignment $a, ?Facility $f = null): TimetableSession => TimetableSession::create(['academic_year_id' => $y, 'time_slot_id' => $slot->id, 'teaching_assignment_id' => $a->id, 'facility_id' => $f?->id, 'facility_type' => $f?->facility_type]);
            $sessions = [
                $session($slots[0], $a1, $room),
                $session($slots[1], $a1),
                $session($slots[2], $a2),
                $session($slots[3], $a2),
                $session($slots[4], $a3, $goneRoom),
                $session($slots[5], $a4),
            ];
            $session($slots[6], $a3)->delete();
            $goneRoom->delete();

            return ['teachers' => $teachers, 'assignments' => [$a1, $a2, $a3, $a4], 'tutors' => [$tutor1, $tutor2], 'sessions' => $sessions, 'room' => $room, 'goneRoom' => $goneRoom];
        });
    }

    public static function user(Tenant $tenant, string $email, UserStatus $status = UserStatus::Activo): User
    {
        return app(TenantContext::class)->runFor($tenant->id, function () use ($email, $status): User {
            $person = Person::create(['given_name' => 'Docente', 'family_name_1' => 'Prueba']);
            $user = new User(['person_id' => $person->id, 'email' => $email, 'password' => 'x', 'status' => $status]);
            $user->save();

            return $user;
        });
    }

    /** Fija el estado de un usuario (p. ej. desactivarlo entre la simulación y la ejecución). */
    public static function setUserStatus(Tenant $tenant, User $user, UserStatus $status): void
    {
        app(TenantContext::class)->runFor($tenant->id, function () use ($user, $status): void {
            $user->refresh();
            $user->status = $status;
            $user->save();
        });
    }

    /** Una copia en el estado dado, creada por el modelo (sin pasar por la solicitud). */
    public static function rollover(Tenant $tenant, AcademicYear $target, AcademicYear $source, YearRolloverStatus $status, ?User $by = null, array $elements = ['acad.education_levels'], bool $copyTeaching = false): YearRollover
    {
        return app(TenantContext::class)->runFor($tenant->id, function () use ($target, $source, $status, $by, $elements, $copyTeaching): YearRollover {
            $previous = Auth::user();

            if ($by !== null) {
                Auth::setUser($by);
            }

            try {
                return YearRollover::create([
                    'academic_year_id' => $target->id,
                    'source_academic_year_id' => $source->id,
                    'status' => $status,
                    'elements' => $elements,
                    'copy_teaching_assignments' => $copyTeaching,
                    'reverted_by' => in_array($status, [YearRolloverStatus::Revirtiendo, YearRolloverStatus::Revertido], true) ? $by?->id : null,
                    'reverted_at' => $status === YearRolloverStatus::Revertido ? now() : null,
                ]);
            } finally {
                $previous !== null ? Auth::setUser($previous) : Auth::forgetUser();
            }
        });
    }

    /** Los nueve elementos sin paquete (§21.2). */
    public const NINE = [
        'acad.education_levels', 'acad.courses', 'acad.groups', 'acad.subgroups', 'acad.subjects',
        'acad.group_subjects', 'acad.academic_periods', 'acad.grading_scales', 'acad.timetable_frames',
    ];

    /** Cuenta filas vivas del destino por tabla. */
    public static function counts(Tenant $tenant, AcademicYear $year): array
    {
        return app(TenantContext::class)->runFor($tenant->id, function () use ($year): array {
            $counts = [];

            foreach (['education_levels', 'courses', 'groups', 'subgroups', 'subjects', 'group_subjects', 'academic_periods', 'grading_scales', 'grading_scale_values', 'timetable_frames', 'time_slots', 'teaching_assignments', 'group_tutors', 'timetable_sessions'] as $table) {
                $counts[$table] = DB::table($table)->where('academic_year_id', $year->id)->whereNull('deleted_at')->count();
            }

            return $counts;
        });
    }

    // ───────────── HTTP, ejecución directa de los trabajos y consultas compartidas por los tests ─────────────

    /** @param  array<string, mixed>  $query */
    public static function get(object $tenant, User $user, string $path, array $query = [], string $locale = 'es-ES'): TestResponse
    {
        resetSessionState();

        return test()->withHeader('Accept-Language', $locale)->actingAs($user)->getJson(coreApiUrl($tenant->slug, $path).($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @param  array<string, mixed>  $body */
    public static function post(object $tenant, User $user, string $path, array $body = []): TestResponse
    {
        resetSessionState();

        return test()->actingAs($user)->postJson(coreApiUrl($tenant->slug, $path), $body);
    }

    /**
     * Cuerpo de simulación/solicitud con los nueve elementos sin paquete.
     *
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function body(array $scenario, array $overrides = []): array
    {
        return $overrides + [
            'academic_year' => $scenario['target']->public_id,
            'source' => $scenario['source']->public_id,
            'elements' => self::NINE,
        ];
    }

    /** Ejecuta un cierre dentro del contexto del centro (RLS y `TenantScope`). */
    public static function inTenant(array $scenario, Closure $callback): mixed
    {
        return app(TenantContext::class)->runFor($scenario['tenant']->id, $callback);
    }

    /** Ejecuta el trabajo de copia directamente, con `Auth` vacío (como un worker). */
    public static function run(array $scenario, YearRollover|string $rollover): void
    {
        self::inTenant($scenario, function () use ($rollover): void {
            $id = $rollover instanceof YearRollover ? $rollover->id : YearRollover::query()->where('public_id', $rollover)->value('id');
            Auth::forgetUser();
            app(RolloverExecutor::class)->run($id);
        });
    }

    /** Ejecuta el trabajo de reversión directamente, con `Auth` vacío. */
    public static function runRevert(array $scenario, string $publicId): void
    {
        self::inTenant($scenario, function () use ($publicId): void {
            Auth::forgetUser();
            app(RolloverExecutor::class)->runRevert(YearRollover::query()->where('public_id', $publicId)->value('id'));
        });
    }

    public static function byId(array $scenario, string $publicId): YearRollover
    {
        return self::inTenant($scenario, fn () => YearRollover::query()->where('public_id', $publicId)->firstOrFail());
    }

    /** Solicita la copia por la API y devuelve su `public_id` (con la cola falsa). */
    public static function request(array $scenario, array $body = []): string
    {
        return self::post($scenario['tenant'], $scenario['admin'], '/academic-year-rollovers', self::body($scenario, $body))->assertStatus(202)->json('public_id');
    }

    /** Descontrata `acad` del centro (RN-CURSO-52, -60, -62). */
    public static function disableAcad(array $scenario): void
    {
        self::inTenant($scenario, function (): void {
            DB::connection('pgsql_platform')->table('module_subscriptions')->where('module_code', 'acad')->update(['enabled' => false]);
            Cache::forget('modules:acad:enabled');
        });
    }

    /** Cuántas filas vivas del destino tiene cada tabla. */
    public static function targetCounts(array $scenario): array
    {
        return self::counts($scenario['tenant'], $scenario['target']);
    }

    /**
     * Forma (códigos, sin identificadores) de la estructura de un curso, para comparar origen y destino.
     *
     * @return list<string>
     */
    public static function shape(array $scenario, int $yearId): array
    {
        return self::inTenant($scenario, function () use ($yearId): array {
            $shape = [];

            foreach (EducationLevel::query()->where('academic_year_id', $yearId)->get() as $l) {
                $shape[] = "level:{$l->code}:{$l->stage}:{$l->regime}:{$l->position}:".json_encode($l->name_translations);
            }

            foreach (Course::query()->where('academic_year_id', $yearId)->with('educationLevel')->get() as $c) {
                $shape[] = "course:{$c->code}:{$c->educationLevel->code}:{$c->position}:".json_encode($c->name_translations);
            }

            foreach (Group::query()->where('academic_year_id', $yearId)->with('course')->get() as $g) {
                $shape[] = "group:{$g->code}:{$g->course->code}:{$g->name}:{$g->position}";
            }

            foreach (Subject::query()->where('academic_year_id', $yearId)->with('course')->get() as $s) {
                $shape[] = "subject:{$s->code}:{$s->course->code}:{$s->position}";
            }

            foreach (GroupSubject::query()->where('academic_year_id', $yearId)->with(['group.course', 'subject'])->get() as $gs) {
                $shape[] = "gs:{$gs->group->course->code}/{$gs->group->code}:{$gs->subject->code}";
            }

            sort($shape);

            return $shape;
        });
    }
}
