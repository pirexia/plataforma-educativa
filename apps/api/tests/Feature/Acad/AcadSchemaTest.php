<?php

use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\Subject;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AcadTestHelpers;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11-b), docs/modulos/REQ-ACAD/datos.md §1-§3, INV-015.
// Esquema real de las seis tablas del módulo `acad`, con base de datos real
// (ADR-033 §10): FK compuestas de tres columnas, CHECK, unicidad parcial,
// índices del ayudante y borrado lógico los impone el motor.

const ACAD_TABLES = ['education_levels', 'courses', 'groups', 'subgroups', 'subjects', 'group_subjects'];

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->year = CursoTestHelpers::year($this->tenant, '2026-2027', AcademicYearStatus::Activo);
    $this->tree = AcadTestHelpers::tree($this->tenant, $this->year);
    app(TenantContext::class)->enter($this->tenant->id);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, mixed> */
function subjectRow(array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::ulid(),
        'code' => 'LEN',
        'name_translations' => json_encode(['es-ES' => 'Lengua']),
        'position' => 2,
    ], $overrides);
}

test('REQ-ACAD-001 datos.md §1: las seis tablas existen, con RLS forzada, public_id char(26) y las columnas de auditoría', function (): void {
    foreach (ACAD_TABLES as $table) {
        $rls = DB::connection('pgsql_owner')->selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = ?::regclass', [$table]);
        $columns = array_column(DB::connection('pgsql_owner')->select(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]
        ), 'column_name');
        $publicId = DB::connection('pgsql_owner')->selectOne(
            "SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = ?::regclass AND attname = 'public_id'", [$table]
        );

        expect($rls->on)->toBeTrue("{$table} sin RLS")
            ->and($rls->forced)->toBeTrue("{$table} sin FORCE RLS")
            ->and($publicId->t)->toBe('character(26)')
            ->and($columns)->toContain('tenant_id', 'academic_year_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at');
    }
});

test('CA-ACAD-021 ADR-057: las seis tablas llevan el disparador academic_year_write_guard habilitado, puesto por el ayudante', function (): void {
    foreach (ACAD_TABLES as $table) {
        $row = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
            SELECT count(*) AS n FROM pg_trigger t
            JOIN pg_proc p ON p.oid = t.tgfoid AND p.proname = 'assert_academic_year_writable'
            WHERE t.tgrelid = ?::regclass AND t.tgname = 'academic_year_write_guard' AND t.tgenabled <> 'D' AND NOT t.tgisinternal
            SQL, [$table]);

        expect((int) $row->n)->toBe(1, "{$table} sin el disparador");
    }
});

test('CA-ACAD-022 ADR-057 §5.2: ninguna FK de las seis tablas (incluidas created_by y updated_by) tiene acción referencial distinta de NO ACTION', function (): void {
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT conrelid::regclass::text AS tabla, conname FROM pg_constraint
        WHERE contype = 'f' AND conrelid::regclass::text = ANY (?)
          AND (confdeltype <> 'a' OR confupdtype <> 'a')
        SQL, ['{'.implode(',', ACAD_TABLES).'}']);

    expect($rows)->toBe([]);

    // No vacuidad: las seis tienen la FK compuesta de autoría a users.
    $authors = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT conrelid::regclass::text AS tabla FROM pg_constraint
        WHERE contype = 'f' AND confrelid = 'users'::regclass AND conrelid::regclass::text = ANY (?)
        SQL, ['{'.implode(',', ACAD_TABLES).'}']);

    expect(count($authors))->toBe(12);
});

test('CA-ACAD-038 #407: cada tabla de acad tiene un índice cuyas columnas iniciales son (tenant_id, academic_year_id)', function (): void {
    foreach (ACAD_TABLES as $table) {
        $row = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
            SELECT count(*) AS n FROM pg_index i
            WHERE i.indrelid = ?::regclass AND i.indisvalid
              AND i.indkey[0] = (SELECT attnum FROM pg_attribute WHERE attrelid = i.indrelid AND attname = 'tenant_id')
              AND i.indkey[1] = (SELECT attnum FROM pg_attribute WHERE attrelid = i.indrelid AND attname = 'academic_year_id')
              AND i.indpred IS NULL AND i.indexprs IS NULL
            SQL, [$table]);

        expect((int) $row->n)->toBeGreaterThanOrEqual(1, "{$table} sin índice (tenant_id, academic_year_id)");
    }

    expect(TenantMigration::indexName('group_subjects', 'academic_year_id'))->toBe('group_subjects__academic_year_id_tenant_idx');
});

test('CA-ACAD-014 ADR-062 §5.1: el motor rechaza name_translations vacío, con clave fuera del vocabulario o con un valor que no es cadena (23514)', function (): void {
    foreach ([
        'objeto vacío' => '{}',
        'clave fuera del vocabulario' => '{"es": "Lengua"}',
        'valor que no es cadena' => '{"es-ES": 5}',
        'no es un objeto' => '["Lengua"]',
    ] as $label => $json) {
        $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('subjects')->insert(subjectRow([
            'academic_year_id' => $this->year->id,
            'course_id' => $this->tree['course']->id,
            'name_translations' => $json,
        ])));

        expect($state)->toBe('23514', $label);
    }

    // Control positivo: la misma fila con un objeto válido se inserta.
    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('subjects')->insert(subjectRow([
        'academic_year_id' => $this->year->id,
        'course_id' => $this->tree['course']->id,
    ]))))->toBeNull();
});

test('CA-ACAD-014 ADR-062 §5.1: courses y education_levels llevan el mismo CHECK de traducciones que subjects', function (): void {
    foreach (['education_levels' => 'name_translations', 'courses' => 'name_translations', 'subjects' => 'name_translations'] as $table => $column) {
        $name = TenantMigration::translationsCheckName($table, $column);
        $row = DB::connection('pgsql_owner')->selectOne('SELECT count(*) AS n FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ? AND contype = \'c\'', [$table, $name]);

        expect((int) $row->n)->toBe(1, "{$table} sin {$name}");
    }
});

test('CA-ACAD-019 RN-ACAD-30: la FK compuesta rechaza group_subjects con grupo y asignatura de cursos de etapa distintos (23503)', function (): void {
    $other = app(TenantContext::class)->runFor($this->tenant->id, function (): array {
        $course = Course::create([
            'academic_year_id' => $this->year->id,
            'education_level_id' => $this->tree['level']->id,
            'code' => '2ESO',
            'name_translations' => ['es-ES' => 'Segundo de ESO'],
            'position' => 2,
        ]);
        $subject = Subject::create([
            'academic_year_id' => $this->year->id,
            'course_id' => $course->id,
            'code' => 'FIS',
            'name_translations' => ['es-ES' => 'Física'],
            'position' => 1,
        ]);

        return compact('course', 'subject');
    });

    // Asignatura del otro curso de etapa, grupo del primero.
    $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('group_subjects')->insert([
        'public_id' => (string) Str::ulid(),
        'academic_year_id' => $this->year->id,
        'course_id' => $this->tree['course']->id,
        'group_id' => $this->tree['group']->id,
        'subject_id' => $other['subject']->id,
    ]));

    expect($state)->toBe('23503');

    // Y con el course_id del segundo, falla la FK del grupo.
    $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('group_subjects')->insert([
        'public_id' => (string) Str::ulid(),
        'academic_year_id' => $this->year->id,
        'course_id' => $other['course']->id,
        'group_id' => $this->tree['group']->id,
        'subject_id' => $other['subject']->id,
    ]));

    expect($state)->toBe('23503');
});

test('CA-ACAD-060 CA-ACAD-061 RN-CURSO-27 RN-ACAD-15 RN-ACAD-16 RN-ACAD-17: un padre de otro curso académico lo rechaza la FK de tres columnas (23503), en las cinco tablas con padre', function (): void {
    $otherYear = CursoTestHelpers::year($this->tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-06-30');
    $other = AcadTestHelpers::tree($this->tenant, $otherYear, 'B');
    // La asignación del otro curso se da de baja: así la unicidad parcial no
    // se dispara antes que la FK, que es lo que se prueba.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $other['groupSubject']->delete());

    $attempts = [
        'courses → education_levels' => fn () => DB::table('courses')->insert([
            'public_id' => (string) Str::ulid(), 'academic_year_id' => $this->year->id,
            'education_level_id' => $other['level']->id, 'code' => 'X', 'name_translations' => json_encode(['es-ES' => 'X']), 'position' => 1,
        ]),
        'groups → courses' => fn () => DB::table('groups')->insert([
            'public_id' => (string) Str::ulid(), 'academic_year_id' => $this->year->id,
            'course_id' => $other['course']->id, 'code' => 'X', 'name' => 'X', 'position' => 1,
        ]),
        'subgroups → groups' => fn () => DB::table('subgroups')->insert([
            'public_id' => (string) Str::ulid(), 'academic_year_id' => $this->year->id,
            'group_id' => $other['group']->id, 'code' => 'X', 'name' => 'X', 'position' => 1,
        ]),
        'subjects → courses' => fn () => DB::table('subjects')->insert(subjectRow([
            'academic_year_id' => $this->year->id, 'course_id' => $other['course']->id, 'code' => 'X',
        ])),
        'group_subjects → courses' => fn () => DB::table('group_subjects')->insert([
            'public_id' => (string) Str::ulid(), 'academic_year_id' => $this->year->id,
            'course_id' => $other['course']->id, 'group_id' => $other['group']->id, 'subject_id' => $other['subject']->id,
        ]),
    ];

    foreach ($attempts as $label => $write) {
        expect(AcadTestHelpers::sqlStateOf($write))->toBe('23503', $label);
    }
});

test('RN-ACAD-01 datos.md §3: el código es único entre hermanos vivos (23505); un hermano borrado, otro padre u otro curso académico lo admiten', function (): void {
    $tree = $this->tree;

    $dupSubject = fn (int $courseId, string $code) => DB::table('subjects')->insert(subjectRow([
        'academic_year_id' => $this->year->id, 'course_id' => $courseId, 'code' => $code,
    ]));

    expect(AcadTestHelpers::sqlStateOf(fn () => $dupSubject($tree['course']->id, 'MAT')))->toBe('23505');

    // Hermano borrado (borrado lógico por instancia, AR-15): el código se libera.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $tree['subject']->delete());
    expect(AcadTestHelpers::sqlStateOf(fn () => $dupSubject($tree['course']->id, 'MAT')))->toBeNull();

    // Etapa: mismo código en otro curso académico, sí; en el mismo, no.
    $otherYear = CursoTestHelpers::year($this->tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-06-30');
    $level = fn (int $yearId) => DB::table('education_levels')->insert([
        'public_id' => (string) Str::ulid(), 'academic_year_id' => $yearId, 'code' => 'ESO',
        'name_translations' => json_encode(['es-ES' => 'ESO']), 'stage' => 'eso', 'regime' => 'publico', 'position' => 2,
    ]);

    expect(AcadTestHelpers::sqlStateOf(fn () => $level($this->year->id)))->toBe('23505')
        ->and(AcadTestHelpers::sqlStateOf(fn () => $level($otherYear->id)))->toBeNull();
});

test('datos.md §3: una asignatura se asigna una sola vez a un grupo vivo (23505); tras la baja, puede volver a asignarse', function (): void {
    $tree = $this->tree;
    $insert = fn () => DB::table('group_subjects')->insert([
        'public_id' => (string) Str::ulid(), 'academic_year_id' => $this->year->id,
        'course_id' => $tree['course']->id, 'group_id' => $tree['group']->id, 'subject_id' => $tree['subject']->id,
    ]);

    expect(AcadTestHelpers::sqlStateOf($insert))->toBe('23505');

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $tree['groupSubject']->delete());

    expect(AcadTestHelpers::sqlStateOf($insert))->toBeNull();
});

test('datos.md §1.1-§1.5: CHECK de stage, regime y position (>= 1) en base de datos', function (): void {
    $level = fn (array $o) => DB::table('education_levels')->insert(array_merge([
        'public_id' => (string) Str::ulid(), 'academic_year_id' => $this->year->id, 'code' => 'N'.Str::random(5),
        'name_translations' => json_encode(['es-ES' => 'N']), 'stage' => 'eso', 'regime' => 'publico', 'position' => 1,
    ], $o));

    expect(AcadTestHelpers::sqlStateOf(fn () => $level(['stage' => 'otra'])))->toBe('23514')
        ->and(AcadTestHelpers::sqlStateOf(fn () => $level(['regime' => 'otro'])))->toBe('23514')
        ->and(AcadTestHelpers::sqlStateOf(fn () => $level(['position' => 0])))->toBe('23514')
        ->and(AcadTestHelpers::sqlStateOf(fn () => DB::table('groups')->insert([
            'public_id' => (string) Str::ulid(), 'academic_year_id' => $this->year->id,
            'course_id' => $this->tree['course']->id, 'code' => 'Z', 'name' => 'Z', 'position' => 0,
        ])))->toBe('23514');

    foreach (['infantil', 'primaria', 'eso', 'bachillerato', 'fp', 'universidad'] as $stage) {
        expect(AcadTestHelpers::sqlStateOf(fn () => $level(['stage' => $stage])))->toBeNull($stage);
    }
});

test('REQ-ACAD-001 ADR-004 INV-004: el borrado de una entidad es lógico (deleted_at), nunca físico', function (): void {
    $tree = $this->tree;

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $tree['subgroup']->delete());

    $row = DB::table('subgroups')->where('id', $tree['subgroup']->id)->first(['deleted_at']);

    expect($row)->not->toBeNull()->and($row->deleted_at)->not->toBeNull();
});
