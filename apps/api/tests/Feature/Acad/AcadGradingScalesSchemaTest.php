<?php

use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AcadTestHelpers;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11d-b), docs/modulos/REQ-ACAD/datos.md §1.8, §1.9, §3.1, §8:
// esquema real de `grading_scales` y `grading_scale_values` con base de datos
// real (ADR-033 §10). CA-ACAD-086 (CHECK y FK de cuatro columnas),
// CA-ACAD-089 (disparador y FK NO ACTION), CA-ACAD-015 (AR-16), INV-001 (RLS),
// INV-015. Lo que el motor NO garantiza (tramos contiguos, al menos un
// aprobado, precisión) lo prueba `AcadGradingScalesApiTest`.

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->year = CursoTestHelpers::year($this->tenant, '2026-2027', AcademicYearStatus::Activo, '2026-09-01', '2027-08-31');
    $this->otherYear = CursoTestHelpers::year($this->tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-08-31');
    app(TenantContext::class)->enter($this->tenant->id);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, mixed> */
function scaleRow(int $yearId, array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::ulid(),
        'academic_year_id' => $yearId,
        'code' => 'E'.Str::random(6),
        'name_translations' => json_encode(['es-ES' => 'Escala']),
        'scale_type' => 'numerica',
        'min_value' => '0',
        'max_value' => '10',
        'decimals' => 2,
        'passing_min_value' => '5',
    ], $overrides);
}

/** @return array<string, mixed> */
function valueRow(int $yearId, int $scaleId, string $type, array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::ulid(),
        'academic_year_id' => $yearId,
        'grading_scale_id' => $scaleId,
        'scale_type' => $type,
        'code' => 'V'.Str::random(6),
        'label_translations' => json_encode(['es-ES' => 'Valor']),
        'position' => 1,
        'is_passing' => $type === 'cualitativa' ? true : null,
        'range_min' => $type === 'mixta' ? '0' : null,
        'range_max' => $type === 'mixta' ? '10' : null,
    ], $overrides);
}

test('CA-ACAD-089 REQ-ACAD-001 datos.md §1.8 §1.9: las dos tablas tienen RLS forzada, public_id char(26), columnas de autoría, tipos numeric(5,2) y el disparador academic_year_write_guard habilitado', function (): void {
    foreach (['grading_scales', 'grading_scale_values'] as $table) {
        $rls = DB::connection('pgsql_owner')->selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = ?::regclass', [$table]);
        $columns = array_column(DB::connection('pgsql_owner')->select(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]
        ), 'column_name');
        $trigger = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
            SELECT count(*) AS n FROM pg_trigger t
            JOIN pg_proc p ON p.oid = t.tgfoid AND p.proname = 'assert_academic_year_writable'
            WHERE t.tgrelid = ?::regclass AND t.tgname = 'academic_year_write_guard' AND t.tgenabled <> 'D' AND NOT t.tgisinternal
            SQL, [$table]);

        expect($rls->on)->toBeTrue($table)
            ->and($rls->forced)->toBeTrue($table)
            ->and($columns)->toContain('tenant_id', 'academic_year_id', 'public_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at')
            ->and((int) $trigger->n)->toBe(1, $table);
    }

    $types = collect(DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.relname || '.' || a.attname AS col, format_type(a.atttypid, a.atttypmod) AS t
        FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
        WHERE c.relname IN ('grading_scales', 'grading_scale_values')
          AND a.attname IN ('public_id', 'min_value', 'max_value', 'passing_min_value', 'decimals', 'range_min', 'range_max', 'is_passing', 'scale_type', 'position')
        SQL))->pluck('t', 'col')->all();

    expect($types)->toMatchArray([
        'grading_scales.public_id' => 'character(26)',
        'grading_scales.min_value' => 'numeric(5,2)',
        'grading_scales.max_value' => 'numeric(5,2)',
        'grading_scales.passing_min_value' => 'numeric(5,2)',
        'grading_scales.decimals' => 'smallint',
        'grading_scales.scale_type' => 'text',
        'grading_scale_values.range_min' => 'numeric(5,2)',
        'grading_scale_values.range_max' => 'numeric(5,2)',
        'grading_scale_values.is_passing' => 'boolean',
        'grading_scale_values.position' => 'integer',
    ]);
});

test('CA-ACAD-089 ADR-057 §5.2: ninguna FK de las dos tablas (incluidas created_by y updated_by) tiene acción referencial distinta de NO ACTION', function (): void {
    foreach (['grading_scales' => 3, 'grading_scale_values' => 4] as $table => $expected) {
        $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
            SELECT conname FROM pg_constraint
            WHERE contype = 'f' AND conrelid = ?::regclass AND (confdeltype <> 'a' OR confupdtype <> 'a')
            SQL, [$table]);
        $all = DB::connection('pgsql_owner')->select("SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid = ?::regclass", [$table]);

        // No vacuidad: curso académico y dos de autoría; los valores, además, la de cuatro columnas a la escala.
        expect($rows)->toBe([], $table)->and(count($all))->toBe($expected, $table);
    }

    $names = array_column(DB::connection('pgsql_owner')->select("SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid = 'grading_scale_values'::regclass"), 'conname');
    $columns = DB::connection('pgsql_owner')->selectOne("SELECT array_length(conkey, 1) AS n FROM pg_constraint WHERE conname = 'grading_scale_values_tenant_year_scale_foreign'");

    expect($names)->toContain('grading_scale_values_tenant_year_scale_foreign')->and((int) $columns->n)->toBe(4);
});

test('CA-ACAD-086 datos.md §1.8: el motor rechaza una escala con campos incoherentes con su tipo, con rango, umbral o decimales inválidos (23514)', function (): void {
    $cases = [
        'numérica con mínimo igual al máximo' => ['min_value' => '10'],
        'numérica con mínimo mayor que el máximo' => ['min_value' => '11'],
        'umbral por debajo del mínimo' => ['min_value' => '1', 'passing_min_value' => '0'],
        'umbral por encima del máximo' => ['passing_min_value' => '11'],
        'decimals 3' => ['decimals' => 3],
        'decimals negativo' => ['decimals' => -1],
        'mínimo 4.5 con decimals 0' => ['min_value' => '4.5', 'decimals' => 0, 'passing_min_value' => '5'],
        'máximo con más decimales' => ['max_value' => '10.5', 'decimals' => 0],
        'umbral con más decimales' => ['passing_min_value' => '5.5', 'decimals' => 0],
        'numérica sin umbral' => ['passing_min_value' => null],
        'numérica sin decimals' => ['decimals' => null],
        'mixta sin máximo' => ['scale_type' => 'mixta', 'max_value' => null],
        'cualitativa con min_value' => ['scale_type' => 'cualitativa', 'max_value' => null, 'decimals' => null, 'passing_min_value' => null],
        'cualitativa con decimals' => ['scale_type' => 'cualitativa', 'min_value' => null, 'max_value' => null, 'passing_min_value' => null],
        'tipo fuera de vocabulario' => ['scale_type' => 'binaria'],
        'nombre vacío' => ['name_translations' => '{}'],
    ];

    foreach ($cases as $label => $override) {
        expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scales')->insert(scaleRow($this->year->id, $override))))->toBe('23514', $label);
    }

    // Filas correctas de los tres tipos sí entran (el rechazo no es ruido de la fila de prueba).
    foreach ([
        [],
        ['decimals' => 0, 'min_value' => '1', 'max_value' => '9', 'passing_min_value' => '5'],
        ['scale_type' => 'mixta'],
        ['scale_type' => 'cualitativa', 'min_value' => null, 'max_value' => null, 'decimals' => null, 'passing_min_value' => null],
        ['min_value' => '-999.99', 'max_value' => '999.99', 'passing_min_value' => '0'],
    ] as $override) {
        expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scales')->insert(scaleRow($this->year->id, $override))))->toBeNull(json_encode($override));
    }
});

test('CA-ACAD-086 datos.md §1.9: el motor rechaza un valor con scale_type numerica o con campos incoherentes con su tipo (23514)', function (): void {
    $qualitative = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id, ['scale_type' => 'cualitativa', 'min_value' => null, 'max_value' => null, 'decimals' => null, 'passing_min_value' => null]));
    $mixed = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id, ['scale_type' => 'mixta']));
    $numeric = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id));

    $cases = [
        'valor de tipo numerica' => [$numeric, 'numerica', ['is_passing' => null]],
        'cualitativa con range_min' => [$qualitative, 'cualitativa', ['range_min' => '1']],
        'cualitativa con range_max' => [$qualitative, 'cualitativa', ['range_max' => '1']],
        'cualitativa sin is_passing' => [$qualitative, 'cualitativa', ['is_passing' => null]],
        'mixta con is_passing' => [$mixed, 'mixta', ['is_passing' => true]],
        'mixta sin range_max' => [$mixed, 'mixta', ['range_max' => null]],
        'mixta sin range_min' => [$mixed, 'mixta', ['range_min' => null]],
        'mixta con inicio mayor que fin' => [$mixed, 'mixta', ['range_min' => '6', 'range_max' => '5']],
        'posición cero' => [$qualitative, 'cualitativa', ['position' => 0]],
        'etiqueta vacía' => [$qualitative, 'cualitativa', ['label_translations' => '{}']],
        'tipo fuera de vocabulario' => [$qualitative, 'binaria', []],
    ];

    foreach ($cases as $label => [$scaleId, $type, $override]) {
        $state = AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->year->id, $scaleId, $type, $override)));

        expect($state)->toBe('23514', $label);
    }

    // Un tramo de un solo valor (range_min = range_max) sí es válido.
    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->year->id, $mixed, 'mixta', ['range_min' => '5', 'range_max' => '5']))))->toBeNull()
        ->and(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->year->id, $qualitative, 'cualitativa'))))->toBeNull();
});

test('CA-ACAD-086 RN-ACAD-70 RN-CURSO-27: la FK de cuatro columnas rechaza (23503) un valor cuyo scale_type no coincide con el de su escala, o cuyo academic_year_id es otro, y un UPDATE del scale_type de una escala con valores', function (): void {
    $qualitative = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id, ['scale_type' => 'cualitativa', 'min_value' => null, 'max_value' => null, 'decimals' => null, 'passing_min_value' => null]));
    $mixed = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id, ['scale_type' => 'mixta']));

    // Tipo distinto del de la escala.
    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->year->id, $qualitative, 'mixta'))))->toBe('23503')
        ->and(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->year->id, $mixed, 'cualitativa'))))->toBe('23503');

    // Curso académico distinto del de la escala.
    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->otherYear->id, $qualitative, 'cualitativa'))))->toBe('23503');

    // Una escala sin valores sí cambia de tipo; con valores (vivos o borrados) no.
    $free = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id));
    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scales')->where('id', $free)->update(['scale_type' => 'mixta'])))->toBeNull();

    DB::table('grading_scale_values')->insert(valueRow($this->year->id, $mixed, 'mixta'));
    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scales')->where('id', $mixed)->update(['scale_type' => 'cualitativa', 'min_value' => null, 'max_value' => null, 'decimals' => null, 'passing_min_value' => null])))->toBe('23503');

    DB::table('grading_scale_values')->where('grading_scale_id', $mixed)->update(['deleted_at' => now()]);
    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scales')->where('id', $mixed)->update(['scale_type' => 'cualitativa', 'min_value' => null, 'max_value' => null, 'decimals' => null, 'passing_min_value' => null])))->toBe('23503');
});

test('RN-ACAD-71 RN-ACAD-83 datos.md §3.1: el código de escala es único entre las vivas del curso y el de valor entre los vivos de su escala (23505); uno borrado lo libera', function (): void {
    $first = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id, ['code' => 'NOTA']));

    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scales')->insert(scaleRow($this->year->id, ['code' => 'NOTA']))))->toBe('23505')
        // Otro curso académico del mismo centro: se admite.
        ->and(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scales')->insert(scaleRow($this->otherYear->id, ['code' => 'NOTA']))))->toBeNull();

    DB::table('grading_scales')->where('id', $first)->update(['deleted_at' => now()]);
    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scales')->insert(scaleRow($this->year->id, ['code' => 'NOTA']))))->toBeNull();

    $scale = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id, ['scale_type' => 'cualitativa', 'min_value' => null, 'max_value' => null, 'decimals' => null, 'passing_min_value' => null]));
    $other = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id, ['scale_type' => 'cualitativa', 'min_value' => null, 'max_value' => null, 'decimals' => null, 'passing_min_value' => null]));
    $valueId = DB::table('grading_scale_values')->insertGetId(valueRow($this->year->id, $scale, 'cualitativa', ['code' => 'A']));

    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->year->id, $scale, 'cualitativa', ['code' => 'A']))))->toBe('23505')
        // La posición no es única (RN-ACAD-83): dos valores con la misma, sin error.
        ->and(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->year->id, $scale, 'cualitativa', ['code' => 'B', 'position' => 1]))))->toBeNull()
        // El mismo código en otra escala.
        ->and(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->year->id, $other, 'cualitativa', ['code' => 'A']))))->toBeNull();

    DB::table('grading_scale_values')->where('id', $valueId)->update(['deleted_at' => now()]);
    expect(AcadTestHelpers::sqlStateOf(fn () => DB::table('grading_scale_values')->insert(valueRow($this->year->id, $scale, 'cualitativa', ['code' => 'A']))))->toBeNull();
});

test('datos.md §3.1: los índices de las FK, los destinos de FK futuras y el del ayudante existen, son no parciales los de FK y no hay EXCLUDE', function (): void {
    $scales = array_column(DB::connection('pgsql_owner')->select("SELECT indexname FROM pg_indexes WHERE tablename = 'grading_scales'"), 'indexname');
    $values = array_column(DB::connection('pgsql_owner')->select("SELECT indexname FROM pg_indexes WHERE tablename = 'grading_scale_values'"), 'indexname');

    expect($scales)->toContain('grading_scales_tenant_year_code_unique', 'grading_scales_tenant_year_id_type_unique', 'grading_scales_public_id_unique', TenantMigration::indexName('grading_scales', 'academic_year_id'))
        ->and($values)->toContain('grading_scale_values_tenant_year_scale_idx', 'grading_scale_values_tenant_scale_code_unique', 'grading_scale_values_tenant_year_id_unique', 'grading_scale_values_public_id_unique', TenantMigration::indexName('grading_scale_values', 'academic_year_id'));

    $row = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
        SELECT i.indpred IS NULL AS non_partial,
               (SELECT string_agg(a.attname, ',' ORDER BY k.ord) FROM unnest(i.indkey::int2[]) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum) AS cols
        FROM pg_index i WHERE i.indexrelid = 'grading_scale_values_tenant_year_scale_idx'::regclass
        SQL);

    expect($row->non_partial)->toBeTrue()->and($row->cols)->toBe('tenant_id,academic_year_id,grading_scale_id,scale_type');

    foreach (['grading_scales_tenant_year_code_unique', 'grading_scale_values_tenant_scale_code_unique'] as $index) {
        $partial = DB::connection('pgsql_owner')->selectOne('SELECT indpred IS NOT NULL AS partial FROM pg_index WHERE indexrelid = ?::regclass', [$index]);

        expect($partial->partial)->toBeTrue($index);
    }

    $exclusion = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid IN ('grading_scales'::regclass, 'grading_scale_values'::regclass) AND contype = 'x'");

    expect((int) $exclusion->n)->toBe(0);
});

test('INV-001 datos.md §1.8 §1.9: la RLS aísla las dos tablas por centro (el otro centro no ve las filas)', function (): void {
    $scale = DB::table('grading_scales')->insertGetId(scaleRow($this->year->id, ['scale_type' => 'cualitativa', 'min_value' => null, 'max_value' => null, 'decimals' => null, 'passing_min_value' => null]));
    DB::table('grading_scale_values')->insert(valueRow($this->year->id, $scale, 'cualitativa'));

    expect(DB::table('grading_scales')->count())->toBe(1)->and(DB::table('grading_scale_values')->count())->toBe(1);

    $other = Tenant::factory()->create();

    app(TenantContext::class)->runFor($other->id, function (): void {
        expect(DB::table('grading_scales')->count())->toBe(0)->and(DB::table('grading_scale_values')->count())->toBe(0);
    });
});

test('CA-ACAD-015 AR-16 ADR-062 §5.1: name_translations y label_translations son jsonb NOT NULL y llevan el CHECK que emite el ayudante', function (): void {
    foreach (['grading_scales' => 'name_translations', 'grading_scale_values' => 'label_translations'] as $table => $column) {
        $info = DB::connection('pgsql_owner')->selectOne('SELECT data_type, is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?', [$table, $column]);
        $checks = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE ?", [$table, "%{$column}%"]);

        expect($info->data_type)->toBe('jsonb')->and($info->is_nullable)->toBe('NO')->and((int) $checks->n)->toBeGreaterThanOrEqual(1);
    }
});
