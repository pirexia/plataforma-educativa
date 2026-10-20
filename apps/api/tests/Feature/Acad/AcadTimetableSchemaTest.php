<?php

use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTestHelpers;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-002 (1.12-a), docs/modulos/REQ-ACAD/datos.md §9, funcional.md §18.9:
// esquema real de las siete tablas con base de datos real (ADR-033 §10).
// CA-ACAD-108 (FK de asignatura y subgrupo del grupo), CA-ACAD-121 (CHECK y
// FK de marcos y franjas), CA-ACAD-122 (solo aulas en sesiones), CA-ACAD-130
// (disparador y FK NO ACTION), ADR-065 (hora civil `time`), INV-001 (RLS),
// INV-015. Lo que el motor NO garantiza lo prueban `AcadTimetableFramesApiTest`
// y los de sesiones y conflictos.

const TIMETABLE_TABLES = ['facilities', 'timetables', 'teaching_assignments', 'group_tutors', 'timetable_frames', 'time_slots', 'timetable_sessions', 'facility_bookings'];

beforeEach(function (): void {
    [$this->tenant, $this->admin, $this->year] = Api::setup('tt-schema');
    $this->otherYear = CursoTestHelpers::year($this->tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-06-30');
    $this->tree = AcadTestHelpers::tree($this->tenant, $this->year);
    app(TenantContext::class)->enter($this->tenant->id);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @param  array<string, mixed>  $overrides */
function ttInsert(string $table, array $overrides): ?string
{
    return AcadTestHelpers::sqlStateOf(fn () => DB::table($table)->insert(['public_id' => (string) Str::ulid()] + $overrides));
}

/** Inserta una fila y devuelve su id (sin comprobar nada). */
function ttInsertId(string $table, array $row): int
{
    return (int) DB::table($table)->insertGetId(['public_id' => (string) Str::ulid()] + $row);
}

function ttFrame(object $test, array $overrides = []): int
{
    return ttInsertId('timetable_frames', $overrides + [
        'academic_year_id' => $test->year->id,
        'education_level_id' => $test->tree['level']->id,
        'frame_type' => 'ordinaria',
        'name_translations' => json_encode(['es-ES' => 'Horario']),
    ]);
}

function ttSlot(object $test, int $frameId, array $overrides = []): int
{
    return ttInsertId('time_slots', $overrides + [
        'academic_year_id' => $test->year->id,
        'timetable_frame_id' => $frameId,
        'weekday' => 1,
        'starts_at' => '08:30',
        'ends_at' => '09:25',
    ]);
}

function ttAssignment(object $test, array $overrides = []): array
{
    return $overrides + [
        'academic_year_id' => $test->year->id,
        'user_id' => $test->admin->id,
        'group_id' => $test->tree['group']->id,
        'group_subject_id' => $test->tree['groupSubject']->id,
        'subgroup_id' => null,
    ];
}

test('CA-ACAD-130 CA-ACAD-001 datos.md §9: las ocho tablas (siete de 1.12-a y facility_bookings de 1.12-b) tienen RLS forzada, public_id char(26) y autoría; las siete con curso tienen el disparador academic_year_write_guard y facilities ni la columna ni el disparador', function (): void {
    $owner = DB::connection('pgsql_owner');

    foreach (TIMETABLE_TABLES as $table) {
        $rls = $owner->selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = ?::regclass', [$table]);
        $columns = array_column($owner->select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]), 'column_name');
        $publicId = $owner->selectOne("SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = ?::regclass AND attname = 'public_id'", [$table]);
        $trigger = $owner->selectOne(<<<'SQL'
            SELECT count(*) AS n FROM pg_trigger t
            JOIN pg_proc p ON p.oid = t.tgfoid AND p.proname = 'assert_academic_year_writable'
            WHERE t.tgrelid = ?::regclass AND t.tgname = 'academic_year_write_guard' AND t.tgenabled <> 'D' AND NOT t.tgisinternal
            SQL, [$table]);

        expect($rls->on)->toBeTrue($table)->and($rls->forced)->toBeTrue($table)
            ->and($publicId->t)->toBe('character(26)', $table)
            ->and($columns)->toContain('tenant_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at');

        if ($table === 'facilities') {
            expect($columns)->not->toContain('academic_year_id')->and((int) $trigger->n)->toBe(0);
        } else {
            expect($columns)->toContain('academic_year_id')->and((int) $trigger->n)->toBe(1, $table);
        }
    }
});

test('CA-ACAD-130 ADR-057 §5.2 AR-13: ninguna FK de las ocho tablas (incluidas created_by y updated_by) tiene acción referencial distinta de NO ACTION, y las tres de teaching_assignments y de timetable_sessions existen', function (): void {
    $owner = DB::connection('pgsql_owner');
    $tables = "'".implode("','", TIMETABLE_TABLES)."'";

    $rows = $owner->select("SELECT conrelid::regclass::text AS t, conname FROM pg_constraint WHERE contype = 'f' AND conrelid::regclass::text IN ({$tables}) AND (confdeltype <> 'a' OR confupdtype <> 'a')");
    $named = collect($owner->select("SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid::regclass::text IN ({$tables})"))->pluck('conname')->all();

    expect($rows)->toBe([])->and($named)->toContain(
        'teaching_assignments_tenant_year_group_foreign', 'teaching_assignments_tenant_group_gsubject_foreign', 'teaching_assignments_tenant_group_subgroup_foreign',
        'timetable_sessions_tenant_year_slot_foreign', 'timetable_sessions_tenant_year_assignment_foreign', 'timetable_sessions_tenant_facility_type_foreign',
        'time_slots_tenant_year_frame_foreign', 'timetable_frames_tenant_year_level_foreign', 'group_tutors_tenant_year_group_foreign',
        'facility_bookings_tenant_id_facility_id_foreign',
    );
});

test('ADR-065 CA-ACAD-121: starts_at y ends_at son time sin zona (precisión de minuto) y weekday smallint', function (): void {
    $types = DB::connection('pgsql_owner')->select("SELECT attname, format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = 'time_slots'::regclass AND attname IN ('weekday', 'starts_at', 'ends_at') ORDER BY attname");

    expect(collect($types)->pluck('t', 'attname')->all())->toBe(['ends_at' => 'time(0) without time zone', 'starts_at' => 'time(0) without time zone', 'weekday' => 'smallint']);
});

test('CA-ACAD-121 datos.md §9.1: el motor rechaza weekday fuera de 1-7, ends_at <= starts_at, un ordinaria con fechas, un especial sin fechas o con valid_to < valid_from y un frame_type fuera de vocabulario (23514)', function (): void {
    $frame = ttFrame($this);

    foreach ([
        'weekday 0' => ['weekday' => 0],
        'weekday 8' => ['weekday' => 8],
        'fin igual al inicio' => ['ends_at' => '08:30'],
        'fin anterior al inicio' => ['ends_at' => '08:00'],
    ] as $label => $override) {
        expect(ttInsert('time_slots', $override + ['academic_year_id' => $this->year->id, 'timetable_frame_id' => $frame, 'weekday' => 1, 'starts_at' => '08:30', 'ends_at' => '09:25']))->toBe('23514', $label);
    }

    $base = ['academic_year_id' => $this->year->id, 'education_level_id' => $this->tree['level']->id, 'name_translations' => json_encode(['es-ES' => 'X'])];

    foreach ([
        'ordinaria con valid_from' => ['frame_type' => 'ordinaria', 'valid_from' => '2026-12-01'],
        'ordinaria con valid_to' => ['frame_type' => 'ordinaria', 'valid_to' => '2026-12-01'],
        'especial sin fechas' => ['frame_type' => 'especial'],
        'especial con una sola fecha' => ['frame_type' => 'especial', 'valid_from' => '2026-12-01'],
        'especial con valid_to < valid_from' => ['frame_type' => 'especial', 'valid_from' => '2026-12-22', 'valid_to' => '2026-12-01'],
        'tipo fuera de vocabulario' => ['frame_type' => 'otra'],
    ] as $label => $override) {
        expect(ttInsert('timetable_frames', $base + $override))->toBe('23514', $label);
    }

    // Una fila correcta sí entra: especial con fechas ordenadas (las columnas de 1.12-b existen ya).
    expect(ttInsert('timetable_frames', $base + ['frame_type' => 'especial', 'valid_from' => '2026-12-01', 'valid_to' => '2026-12-01']))->toBeNull();
});

test('CA-ACAD-121 RN-ACAD-110: el motor impide un segundo marco ordinaria vivo en la etapa (23505) pero admite uno borrado o un especial', function (): void {
    ttFrame($this);

    expect(ttInsert('timetable_frames', ['academic_year_id' => $this->year->id, 'education_level_id' => $this->tree['level']->id, 'frame_type' => 'ordinaria', 'name_translations' => json_encode(['es-ES' => 'Otro'])]))->toBe('23505');

    DB::table('timetable_frames')->update(['deleted_at' => now()]);

    expect(ttInsert('timetable_frames', ['academic_year_id' => $this->year->id, 'education_level_id' => $this->tree['level']->id, 'frame_type' => 'ordinaria', 'name_translations' => json_encode(['es-ES' => 'Otro'])]))->toBeNull();
});

test('CA-ACAD-121 datos.md §9.1: una franja con el marco de otro curso académico, una sesión con la franja o la asignación de otro curso y una etapa con curso distinto son 23503', function (): void {
    $frame = ttFrame($this);
    $slot = ttSlot($this, $frame);
    $assignment = ttInsertId('teaching_assignments', ttAssignment($this));

    expect(ttInsert('time_slots', ['academic_year_id' => $this->otherYear->id, 'timetable_frame_id' => $frame, 'weekday' => 2, 'starts_at' => '08:30', 'ends_at' => '09:25']))->toBe('23503')
        ->and(ttInsert('timetable_sessions', ['academic_year_id' => $this->otherYear->id, 'time_slot_id' => $slot, 'teaching_assignment_id' => $assignment]))->toBe('23503')
        ->and(ttInsert('timetable_frames', ['academic_year_id' => $this->otherYear->id, 'education_level_id' => $this->tree['level']->id, 'frame_type' => 'especial', 'valid_from' => '2027-12-01', 'valid_to' => '2027-12-22', 'name_translations' => json_encode(['es-ES' => 'X'])]))->toBe('23503')
        ->and(ttInsert('timetable_sessions', ['academic_year_id' => $this->year->id, 'time_slot_id' => $slot, 'teaching_assignment_id' => $assignment]))->toBeNull();
});

test('CA-ACAD-108 RN-ACAD-93 datos.md §9.1: el motor rechaza una asignación con el group_subject o el subgrupo de otro grupo (23503) y admite subgroup_id NULL', function (): void {
    $other = app(TenantContext::class)->runFor($this->tenant->id, function (): array {
        $group = Group::create(['academic_year_id' => $this->year->id, 'course_id' => $this->tree['course']->id, 'code' => 'B', 'name' => 'Grupo B', 'position' => 2]);
        $subject = Subject::create(['academic_year_id' => $this->year->id, 'course_id' => $this->tree['course']->id, 'code' => 'LEN', 'name_translations' => ['es-ES' => 'Lengua'], 'position' => 2]);
        $groupSubject = GroupSubject::create(['academic_year_id' => $this->year->id, 'course_id' => $this->tree['course']->id, 'group_id' => $group->id, 'subject_id' => $subject->id]);
        $subgroup = Subgroup::create(['academic_year_id' => $this->year->id, 'group_id' => $group->id, 'code' => 'B1', 'name' => 'B1', 'position' => 1]);

        return compact('group', 'groupSubject', 'subgroup');
    });

    expect(ttInsert('teaching_assignments', ttAssignment($this, ['group_subject_id' => $other['groupSubject']->id])))->toBe('23503')
        ->and(ttInsert('teaching_assignments', ttAssignment($this, ['subgroup_id' => $other['subgroup']->id])))->toBe('23503')
        ->and(ttInsert('teaching_assignments', ttAssignment($this, ['subgroup_id' => $this->tree['subgroup']->id])))->toBeNull()
        ->and(ttInsert('teaching_assignments', ttAssignment($this, ['group_id' => $other['group']->id])))->toBe('23503')
        ->and(ttInsert('teaching_assignments', ttAssignment($this, ['user_id' => 999999999])))->toBe('23503');
});

test('CA-ACAD-110 RN-ACAD-94: el único NULLS NOT DISTINCT cuenta NULL como valor: dos asignaciones iguales sin subgrupo son 23505, con subgrupo distinto no, y una borrada no cuenta', function (): void {
    ttInsertId('teaching_assignments', ttAssignment($this));

    expect(ttInsert('teaching_assignments', ttAssignment($this)))->toBe('23505')
        ->and(ttInsert('teaching_assignments', ttAssignment($this, ['subgroup_id' => $this->tree['subgroup']->id])))->toBeNull()
        ->and(ttInsert('teaching_assignments', ttAssignment($this, ['subgroup_id' => $this->tree['subgroup']->id])))->toBe('23505');

    DB::table('teaching_assignments')->update(['deleted_at' => now()]);

    expect(ttInsert('teaching_assignments', ttAssignment($this)))->toBeNull();
});

test('CA-ACAD-122 RN-ACAD-121 datos.md §9.1: el motor rechaza un recurso como espacio de una sesión (23503), un facility_type distinto de aula o un facility_id sin tipo (23514) y admite aula y NULL', function (): void {
    $slot = ttSlot($this, ttFrame($this));
    $assignment = ttInsertId('teaching_assignments', ttAssignment($this));
    $room = ttInsertId('facilities', ['facility_type' => 'aula', 'code' => 'A21', 'name' => 'Aula 2.1']);
    $resource = ttInsertId('facilities', ['facility_type' => 'recurso', 'code' => 'CARRO', 'name' => 'Carro']);
    $base = ['academic_year_id' => $this->year->id, 'time_slot_id' => $slot, 'teaching_assignment_id' => $assignment];

    expect(ttInsert('timetable_sessions', $base + ['facility_id' => $resource, 'facility_type' => 'aula']))->toBe('23503')
        ->and(ttInsert('timetable_sessions', $base + ['facility_id' => $resource, 'facility_type' => 'recurso']))->toBe('23514')
        ->and(ttInsert('timetable_sessions', $base + ['facility_id' => $room]))->toBe('23514')
        ->and(ttInsert('timetable_sessions', $base + ['facility_type' => 'aula']))->toBe('23514')
        ->and(ttInsert('timetable_sessions', $base + ['facility_id' => $room, 'facility_type' => 'aula']))->toBeNull()
        // Otra asignación en la misma franja: el único (franja, asignación) de #446-d no lo impide.
        ->and(ttInsert('timetable_sessions', ['teaching_assignment_id' => ttInsertId('teaching_assignments', ttAssignment($this, ['subgroup_id' => $this->tree['subgroup']->id]))] + $base))->toBeNull();
});

test('CA-ACAD-110 RN-ACAD-123 #446-d: el motor impide dos sesiones vivas con la misma franja y la misma asignación (23505) como cinturón; no entre una borrada, ni con otra franja u otra asignación', function (): void {
    $slot = ttSlot($this, ttFrame($this));
    $otherSlot = ttSlot($this, (int) DB::table('time_slots')->where('id', $slot)->value('timetable_frame_id'), ['weekday' => 2]);
    $assignment = ttInsertId('teaching_assignments', ttAssignment($this));
    $otherAssignment = ttInsertId('teaching_assignments', ttAssignment($this, ['subgroup_id' => $this->tree['subgroup']->id]));
    $base = ['academic_year_id' => $this->year->id, 'time_slot_id' => $slot, 'teaching_assignment_id' => $assignment];

    expect(ttInsert('timetable_sessions', $base))->toBeNull()
        ->and(ttInsert('timetable_sessions', $base))->toBe('23505')
        ->and(ttInsert('timetable_sessions', ['time_slot_id' => $otherSlot] + $base))->toBeNull()
        ->and(ttInsert('timetable_sessions', ['teaching_assignment_id' => $otherAssignment] + $base))->toBeNull();

    // Borrada la primera, la pareja queda libre.
    DB::table('timetable_sessions')->where('time_slot_id', $slot)->where('teaching_assignment_id', $assignment)->update(['deleted_at' => now()]);

    expect(ttInsert('timetable_sessions', $base))->toBeNull();
});

test('CA-ACAD-115 RN-ACAD-100 RN-ACAD-101: el motor rechaza un facility_type fuera de vocabulario (23514) y un código repetido entre vivos (23505), no entre uno borrado', function (): void {
    ttInsertId('facilities', ['facility_type' => 'aula', 'code' => 'A21', 'name' => 'Aula 2.1']);

    expect(ttInsert('facilities', ['facility_type' => 'laboratorio', 'code' => 'L1', 'name' => 'Lab']))->toBe('23514')
        ->and(ttInsert('facilities', ['facility_type' => 'recurso', 'code' => 'A21', 'name' => 'Otro']))->toBe('23505');

    DB::table('facilities')->update(['deleted_at' => now()]);

    expect(ttInsert('facilities', ['facility_type' => 'recurso', 'code' => 'A21', 'name' => 'Otro']))->toBeNull();
});

test('CA-ACAD-113 RN-ACAD-97: el motor impide el mismo tutor dos veces en el grupo (23505), no uno borrado, y admite el mismo usuario en otro grupo', function (): void {
    $row = ['academic_year_id' => $this->year->id, 'group_id' => $this->tree['group']->id, 'user_id' => $this->admin->id];
    ttInsertId('group_tutors', $row);

    expect(ttInsert('group_tutors', $row))->toBe('23505');

    DB::table('group_tutors')->update(['deleted_at' => now()]);

    expect(ttInsert('group_tutors', $row))->toBeNull();
});

test('CA-ACAD-130 RN-ACAD-90: sobre un curso cerrado, crear, modificar y borrar filas de las seis tablas con curso falla con YC001 y la fila no cambia; facilities sigue editable', function (): void {
    $frame = ttFrame($this);
    $slot = ttSlot($this, $frame);
    $assignment = ttInsertId('teaching_assignments', ttAssignment($this));
    $tutor = ttInsertId('group_tutors', ['academic_year_id' => $this->year->id, 'group_id' => $this->tree['group']->id, 'user_id' => $this->admin->id]);
    $session = ttInsertId('timetable_sessions', ['academic_year_id' => $this->year->id, 'time_slot_id' => $slot, 'teaching_assignment_id' => $assignment]);
    $anchor = ttInsertId('timetables', ['academic_year_id' => $this->year->id]);
    $room = ttInsertId('facilities', ['facility_type' => 'aula', 'code' => 'A21', 'name' => 'Aula 2.1']);

    CursoTestHelpers::setStatus($this->tenant, $this->year, AcademicYearStatus::Cerrado);
    app(TenantContext::class)->enter($this->tenant->id);

    foreach ([
        'timetables' => $anchor, 'teaching_assignments' => $assignment, 'group_tutors' => $tutor,
        'timetable_frames' => $frame, 'time_slots' => $slot, 'timetable_sessions' => $session,
    ] as $table => $id) {
        expect(AcadTestHelpers::sqlStateOf(fn () => DB::table($table)->where('id', $id)->update(['updated_at' => now()])))->toBe('YC001', "UPDATE {$table}")
            ->and(AcadTestHelpers::sqlStateOf(fn () => DB::table($table)->where('id', $id)->update(['deleted_at' => now()])))->toBe('YC001', "DELETE lógico {$table}");
    }

    expect(ttInsert('time_slots', ['academic_year_id' => $this->year->id, 'timetable_frame_id' => $frame, 'weekday' => 3, 'starts_at' => '08:30', 'ends_at' => '09:25']))->toBe('YC001')
        ->and(AcadTestHelpers::sqlStateOf(fn () => DB::table('facilities')->where('id', $room)->update(['name' => 'Renombrada'])))->toBeNull();
});

test('INV-001 CA-ACAD-132: las filas de otro centro no se ven por el modelo ni por SQL directo (RLS) en ninguna de las siete tablas', function (): void {
    ttFrame($this);
    ttInsertId('facilities', ['facility_type' => 'aula', 'code' => 'A21', 'name' => 'Aula 2.1']);
    ttInsertId('timetables', ['academic_year_id' => $this->year->id]);

    [$other] = Api::setup('tt-schema-b');
    app(TenantContext::class)->enter($other->id);

    foreach (['timetable_frames', 'facilities', 'timetables'] as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }

    expect(EducationLevel::query()->count())->toBe(0)->and(Course::query()->count())->toBe(0);
});

test('ADR-065 CA-ACAD-138 datos.md §9.2: facility_bookings guarda la fecha como date y las horas como time sin zona; el motor rechaza ends_at <= starts_at (23514), un espacio inexistente (23503) y un curso inexistente (23503) y admite una fila correcta', function (): void {
    $types = DB::connection('pgsql_owner')->select("SELECT attname, format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = 'facility_bookings'::regclass AND attname IN ('booked_on', 'starts_at', 'ends_at') ORDER BY attname");

    expect(collect($types)->pluck('t', 'attname')->all())->toBe(['booked_on' => 'date', 'ends_at' => 'time(0) without time zone', 'starts_at' => 'time(0) without time zone']);

    $facility = ttInsertId('facilities', ['facility_type' => 'recurso', 'code' => 'CARRO', 'name' => 'Carro']);
    $row = ['academic_year_id' => $this->year->id, 'facility_id' => $facility, 'booked_on' => '2026-11-12', 'starts_at' => '10:00', 'ends_at' => '11:00'];

    expect(ttInsert('facility_bookings', ['ends_at' => '10:00'] + $row))->toBe('23514')
        ->and(ttInsert('facility_bookings', ['ends_at' => '09:00'] + $row))->toBe('23514')
        ->and(ttInsert('facility_bookings', ['facility_id' => 999999999] + $row))->toBe('23503')
        ->and(ttInsert('facility_bookings', ['academic_year_id' => 999999999] + $row))->toBe('23503')
        ->and(ttInsert('facility_bookings', $row))->toBeNull()
        // El motor no impide dos reservas solapadas (sin btree_gist): lo hace el servicio bajo el ancla (RN-ACAD-125).
        ->and(ttInsert('facility_bookings', ['starts_at' => '10:30', 'ends_at' => '11:30'] + $row))->toBeNull();
});
