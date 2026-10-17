<?php

use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AcadTestHelpers;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11d-a), docs/modulos/REQ-ACAD/datos.md §1.7, §3.1, §8.3:
// esquema real de `academic_periods` con base de datos real (ADR-033 §10).
// CA-ACAD-073 (CHECK y FK), CA-ACAD-089 (disparador y FK NO ACTION),
// CA-ACAD-015 (AR-16: columna traducible con el ayudante), INV-001 (RLS),
// INV-015. Lo que el motor NO garantiza (RN-ACAD-57/-58/-59) lo prueba
// `AcadPeriodsApiTest` y `tests/Concurrency/AcadConcurrencyTest`.

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->year = CursoTestHelpers::year($this->tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');
    $this->otherYear = CursoTestHelpers::year($this->tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-08-31');
    $this->tree = AcadTestHelpers::tree($this->tenant, $this->year);
    app(TenantContext::class)->enter($this->tenant->id);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, mixed> */
function periodRow(array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::ulid(),
        'period_type' => 'trimestre',
        'name_translations' => json_encode(['es-ES' => 'Primer trimestre']),
        'starts_on' => '2026-09-08',
        'ends_on' => '2026-12-22',
        'position' => 1,
    ], $overrides);
}

test('CA-ACAD-089 REQ-ACAD-001 datos.md §1.7: academic_periods tiene RLS forzada, public_id char(26), columnas de autoría y el disparador academic_year_write_guard habilitado', function (): void {
    $rls = DB::connection('pgsql_owner')->selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = ?::regclass', ['academic_periods']);
    $columns = array_column(DB::connection('pgsql_owner')->select(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'academic_periods'"
    ), 'column_name');
    $types = DB::connection('pgsql_owner')->select(
        "SELECT attname, format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = 'academic_periods'::regclass AND attname IN ('public_id', 'starts_on', 'ends_on', 'period_type', 'position') ORDER BY attnum"
    );
    $trigger = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
        SELECT count(*) AS n FROM pg_trigger t
        JOIN pg_proc p ON p.oid = t.tgfoid AND p.proname = 'assert_academic_year_writable'
        WHERE t.tgrelid = 'academic_periods'::regclass AND t.tgname = 'academic_year_write_guard' AND t.tgenabled <> 'D' AND NOT t.tgisinternal
        SQL);

    expect($rls->on)->toBeTrue()
        ->and($rls->forced)->toBeTrue()
        ->and($columns)->toContain('tenant_id', 'academic_year_id', 'education_level_id', 'name_translations', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at')
        ->and((int) $trigger->n)->toBe(1)
        ->and(collect($types)->pluck('t', 'attname')->all())->toBe(['public_id' => 'character(26)', 'period_type' => 'text', 'starts_on' => 'date', 'ends_on' => 'date', 'position' => 'integer']);
});

test('CA-ACAD-089 ADR-057 §5.2: ninguna FK de academic_periods (incluidas created_by y updated_by) tiene acción referencial distinta de NO ACTION', function (): void {
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT conname FROM pg_constraint
        WHERE contype = 'f' AND conrelid = 'academic_periods'::regclass
          AND (confdeltype <> 'a' OR confupdtype <> 'a')
        SQL);

    $all = DB::connection('pgsql_owner')->select("SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid = 'academic_periods'::regclass");
    $authors = DB::connection('pgsql_owner')->select("SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid = 'academic_periods'::regclass AND confrelid = 'users'::regclass");

    expect($rows)->toBe([])
        // No vacuidad: la de curso académico, la de tres columnas a la etapa y las dos de autoría.
        ->and(count($all))->toBe(4)
        ->and(count($authors))->toBe(2)
        ->and(collect($all)->pluck('conname')->all())->toContain('academic_periods_tenant_year_level_foreign');
});

test('CA-ACAD-073 datos.md §1.7: el motor rechaza ends_on <= starts_on, un period_type fuera de vocabulario y position = 0 (23514)', function (): void {
    foreach ([
        'fin igual al inicio' => ['starts_on' => '2026-09-08', 'ends_on' => '2026-09-08'],
        'fin anterior al inicio' => ['starts_on' => '2026-12-22', 'ends_on' => '2026-09-08'],
        'tipo fuera de vocabulario' => ['period_type' => 'bimestre'],
        'posición cero' => ['position' => 0],
    ] as $label => $override) {
        $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('academic_periods')->insert(periodRow([
            'academic_year_id' => $this->year->id,
            'education_level_id' => $this->tree['level']->id,
        ] + $override)));

        expect($state)->toBe('23514', $label);
    }

    // Una fila correcta sí entra (el rechazo no es ruido de la fila de prueba).
    $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('academic_periods')->insert(periodRow([
        'academic_year_id' => $this->year->id,
        'education_level_id' => $this->tree['level']->id,
    ])));

    expect($state)->toBeNull();
});

test('CA-ACAD-073 CA-ACAD-014 ADR-062 §5.1: el motor rechaza name_translations vacío (23514)', function (): void {
    foreach (['{}', '{"es": "Primer"}', '{"es-ES": 5}'] as $json) {
        $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('academic_periods')->insert(periodRow([
            'academic_year_id' => $this->year->id,
            'education_level_id' => $this->tree['level']->id,
            'name_translations' => $json,
        ])));

        expect($state)->toBe('23514', $json);
    }
});

test('CA-ACAD-073 RN-CURSO-27: una etapa de otro curso académico la rechaza la FK compuesta de tres columnas (23503)', function (): void {
    // La etapa es del curso 2026-2027; el período dice 2027-2028.
    $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('academic_periods')->insert(periodRow([
        'academic_year_id' => $this->otherYear->id,
        'education_level_id' => $this->tree['level']->id,
    ])));

    expect($state)->toBe('23503');
});

test('datos.md §1.7 §3.1: el índice de la FK de tres columnas, el destino de FK futuras y el índice del ayudante existen; sin EXCLUDE ni btree_gist (§8.3)', function (): void {
    $indexes = array_column(DB::connection('pgsql_owner')->select("SELECT indexname FROM pg_indexes WHERE tablename = 'academic_periods'"), 'indexname');

    expect($indexes)->toContain('academic_periods_tenant_year_level_idx', 'academic_periods_tenant_year_id_unique', TenantMigration::indexName('academic_periods', 'academic_year_id'));

    $exclusion = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid = 'academic_periods'::regclass AND contype = 'x'");
    $extension = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_extension WHERE extname = 'btree_gist'");

    expect((int) $exclusion->n)->toBe(0)->and((int) $extension->n)->toBe(0);

    // El índice de la FK de tres columnas es no parcial y empieza por sus columnas.
    $row = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
        SELECT i.indpred IS NULL AS non_partial,
               (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(i.indkey::int2[]) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum) AS cols
        FROM pg_index i WHERE i.indexrelid = 'academic_periods_tenant_year_level_idx'::regclass
        SQL);

    expect($row->non_partial)->toBeTrue()->and($row->cols)->toBe('tenant_id,academic_year_id,education_level_id');
});

test('INV-001 datos.md §1.7: la RLS aísla academic_periods por centro (el otro centro no ve la fila)', function (): void {
    DB::table('academic_periods')->insert(periodRow(['academic_year_id' => $this->year->id, 'education_level_id' => $this->tree['level']->id]));

    expect(DB::table('academic_periods')->count())->toBe(1);

    $other = Tenant::factory()->create();

    app(TenantContext::class)->runFor($other->id, function (): void {
        expect(DB::table('academic_periods')->count())->toBe(0);
    });
});

test('CA-ACAD-015 AR-16 ADR-062 §5.1: name_translations de academic_periods es jsonb NOT NULL y lleva el CHECK que emite el ayudante', function (): void {
    $column = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
        SELECT data_type, is_nullable FROM information_schema.columns
        WHERE table_name = 'academic_periods' AND column_name = 'name_translations'
        SQL);
    $checks = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
        SELECT count(*) AS n FROM pg_constraint
        WHERE conrelid = 'academic_periods'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%name_translations%'
        SQL);

    expect($column->data_type)->toBe('jsonb')->and($column->is_nullable)->toBe('NO')->and((int) $checks->n)->toBeGreaterThanOrEqual(1);
});

test('REQ-ACAD-001 datos.md §1.7: el borrado lógico de un período no lo borra físicamente y una etapa borrada lógicamente con períodos solo borrados se admite', function (): void {
    $id = DB::table('academic_periods')->insertGetId(periodRow(['academic_year_id' => $this->year->id, 'education_level_id' => $this->tree['level']->id]));
    DB::table('academic_periods')->where('id', $id)->update(['deleted_at' => now()]);

    expect(DB::table('academic_periods')->where('id', $id)->count())->toBe(1);

    $level = EducationLevel::query()->create([
        'academic_year_id' => $this->year->id, 'code' => 'LOGICA', 'name_translations' => ['es-ES' => 'Lógica'], 'stage' => 'eso', 'regime' => 'publico', 'position' => 9,
    ]);
    DB::table('academic_periods')->insert(periodRow(['academic_year_id' => $this->year->id, 'education_level_id' => $level->id, 'deleted_at' => now()]));
    $level->delete();

    expect(EducationLevel::query()->whereKey($level->id)->exists())->toBeFalse();
});
