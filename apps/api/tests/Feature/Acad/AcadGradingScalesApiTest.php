<?php

use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadScaleHelpers as Scales;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11d-b), docs/modulos/REQ-ACAD/api.md §11, funcional.md
// §4.9, §5.9-§5.10, §13.12: escalas de calificación y sus valores.
// CA-ACAD-075 a -085, -087, -104, -105, INV-010 (validación de negocio en
// servidor), INV-003 (auditoría), INV-015. «Curso académico de prueba»: del
// 2026-09-01 al 2027-08-31, `activo`.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ACAD-075 RN-ACAD-51 RN-ACAD-71 RN-ACAD-74 RN-ACAD-75 INV-003: el alta de una numérica responde 201 con Location, decimales como cadena con exactamente decimals cifras, values vacío y una entrada created de grading_scale', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-075');

    $response = Scales::post($tenant, $admin, Scales::numeric($year->public_id))->assertCreated();

    expect($response->json('public_id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($response->headers->get('Location'))->toEndWith('/api/v1/grading-scales/'.$response->json('public_id'))
        ->and($response->json('academic_year'))->toBe($year->public_id)
        ->and($response->json('code'))->toBe('NUM10')
        ->and($response->json('name'))->toBe('Numérica de 0 a 10')
        ->and($response->json('scale_type'))->toBe('numerica')
        ->and($response->json('min_value'))->toBe('0.00')
        ->and($response->json('max_value'))->toBe('10.00')
        ->and($response->json('decimals'))->toBe(2)
        ->and($response->json('passing_min_value'))->toBe('5.00')
        ->and($response->json('values'))->toBe([])
        ->and(array_keys($response->json()))->not->toContain('id', 'tenant_id', 'academic_year_id', 'created_by', 'updated_by', 'deleted_at');

    $audit = Scales::audit($tenant, 'grading_scale', 'created');

    expect($audit)->toHaveCount(1)
        ->and($audit[0]->changes)->toHaveKeys(['code', 'scale_type', 'min_value', 'max_value', 'decimals', 'passing_min_value', 'name_translations.es-ES']);

    // Detalle: mismo cuerpo. Con decimals 0 la cadena no lleva punto; con 1, un decimal.
    expect(Scales::get($tenant, $admin, '/grading-scales/'.$response->json('public_id'))->assertOk()->json('max_value'))->toBe('10.00');

    $zero = Scales::create($tenant, $admin, Scales::numeric($year->public_id, ['code' => 'ENT', 'decimals' => 0, 'min_value' => 1, 'max_value' => '9', 'passing_min_value' => 5]));
    $one = Scales::create($tenant, $admin, Scales::numeric($year->public_id, ['code' => 'UNO', 'decimals' => 1, 'min_value' => '-1.5', 'max_value' => '9.5', 'passing_min_value' => 5.5]));

    expect([$zero['min_value'], $zero['max_value'], $zero['passing_min_value']])->toBe(['1', '9', '5'])
        ->and([$one['min_value'], $one['max_value'], $one['passing_min_value']])->toBe(['-1.5', '9.5', '5.5']);
});

test('CA-ACAD-076 RN-ACAD-72 RN-ACAD-73 RN-ACAD-74 INV-010: una numérica con campos ausentes, rango, decimales, umbral o precisión inválidos es 422 de servidor y no crea nada', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-076');

    $cases = [
        'falta el umbral' => [['passing_min_value' => null], 'passing_min_value', 'acad.validation.scale_field_required'],
        'falta el mínimo' => [['min_value' => null], 'min_value', 'acad.validation.scale_field_required'],
        'falta decimals' => [['decimals' => null], 'decimals', 'acad.validation.scale_field_required'],
        'mínimo igual al máximo' => [['min_value' => '10'], 'max_value', 'acad.validation.range_invalid'],
        'mínimo mayor que el máximo' => [['min_value' => '11'], 'max_value', 'acad.validation.range_invalid'],
        'decimals 3' => [['decimals' => 3], 'decimals', 'acad.validation.decimals_invalid'],
        'decimals negativo' => [['decimals' => -1], 'decimals', 'acad.validation.decimals_invalid'],
        'decimals como cadena' => [['decimals' => '2'], 'decimals', 'acad.validation.decimals_invalid'],
        'umbral por debajo' => [['min_value' => '1', 'passing_min_value' => '0'], 'passing_min_value', 'acad.validation.passing_out_of_range'],
        'umbral por encima' => [['passing_min_value' => '11'], 'passing_min_value', 'acad.validation.passing_out_of_range'],
        'precisión con 0 decimales' => [['decimals' => 0, 'passing_min_value' => '4.5'], 'passing_min_value', 'acad.validation.decimal_precision_exceeded'],
        'precisión con 1 decimal' => [['decimals' => 1, 'max_value' => '10.25'], 'max_value', 'acad.validation.decimal_precision_exceeded'],
        'coma decimal' => [['passing_min_value' => '5,5'], 'passing_min_value', 'acad.validation.decimal_invalid'],
        'notación científica' => [['max_value' => '1e1'], 'max_value', 'acad.validation.decimal_invalid'],
        'fuera de numeric(5,2)' => [['max_value' => '1000'], 'max_value', 'acad.validation.decimal_invalid'],
        'tres decimales' => [['max_value' => '10.001'], 'max_value', 'acad.validation.decimal_invalid'],
        'sin parte entera' => [['max_value' => '.5'], 'max_value', 'acad.validation.decimal_invalid'],
        'no es un número' => [['max_value' => ['10']], 'max_value', 'acad.validation.decimal_invalid'],
    ];

    foreach ($cases as $label => [$override, $field, $code]) {
        $response = Scales::post($tenant, $admin, Scales::numeric($year->public_id, $override))->assertStatus(422);

        expect(Scales::errorCode($response, $field))->toBe($code, $label)
            ->and($response->json('errors')[$field][0]['message'])->not->toBe($code, $label);
    }

    // Los extremos de numeric(5,2) se admiten.
    Scales::create($tenant, $admin, Scales::numeric($year->public_id, ['code' => 'EXT', 'min_value' => '-999.99', 'max_value' => '999.99', 'passing_min_value' => '0']));

    expect(Scales::scales($tenant, false))->toBe(1);
});

test('CA-ACAD-077 RN-ACAD-72 RN-ACAD-81 RN-ACAD-82 RN-ACAD-83: una cualitativa de cinco valores responde 201 en el orden enviado con position 1 a 5, label y label_translations, y una entrada created por valor; sin valores, sin aprobado, sin is_passing, con código repetido o con campos numéricos es 422', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-077');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);

    $response = Scales::post($tenant, $admin, Scales::qualitative($year->public_id))->assertCreated();
    $values = $response->json('values');

    expect($response->json('scale_type'))->toBe('cualitativa')
        ->and([$response->json('min_value'), $response->json('max_value'), $response->json('decimals'), $response->json('passing_min_value')])->toBe([null, null, null, null])
        ->and(array_column($values, 'code'))->toBe(['IN', 'SU', 'BI', 'NT', 'SB'])
        ->and(array_column($values, 'position'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($values, 'is_passing'))->toBe([false, true, true, true, true])
        ->and(array_column($values, 'label'))->toBe(['Insuficiente', 'Suficiente', 'Bien', 'Notable', 'Sobresaliente'])
        ->and($values[0]['label_translations'])->toBe(['es-ES' => 'Insuficiente'])
        ->and($values[0]['range_min'])->toBeNull()->and($values[0]['range_max'])->toBeNull()
        ->and($values[0]['public_id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and(array_keys($values[0]))->not->toContain('id', 'scale_type', 'grading_scale_id', 'academic_year_id', 'deleted_at')
        ->and(Scales::audit($tenant, 'grading_scale_value', 'created'))->toHaveCount(5)
        ->and(Scales::audit($tenant, 'grading_scale', 'created'))->toHaveCount(1);

    $base = Scales::qualitative($year->public_id, ['code' => 'OTRA']);
    $withValues = fn (array $values): array => ['values' => $values] + $base;
    $sinValues = $base;
    unset($sinValues['values']);
    $allFail = array_map(fn (array $v): array => [...$v, 'is_passing' => false], $base['values']);
    $noFlag = $base['values'];
    unset($noFlag[2]['is_passing']);
    $duplicated = $base['values'];
    $duplicated[3]['code'] = 'SU';

    $cases = [
        'sin values' => [$sinValues, 'values', 'acad.validation.values_required'],
        'values vacío' => [$withValues([]), 'values', 'acad.validation.values_required'],
        'values no es lista' => [$withValues(['a' => $base['values'][0]]), 'values', 'acad.validation.values_required'],
        'ninguno aprobado' => [$withValues($allFail), 'values', 'acad.validation.passing_value_required'],
        'sin is_passing' => [$withValues($noFlag), 'values.2.is_passing', 'acad.validation.is_passing_required'],
        'is_passing no booleano' => [$withValues([['is_passing' => 'si'] + $base['values'][0]]), 'values.0.is_passing', 'acad.validation.is_passing_required'],
        'código repetido' => [$withValues($duplicated), 'values.3.code', 'acad.validation.code_taken'],
        'valor sin código' => [$withValues([['code' => ' '] + $base['values'][0]]), 'values.0.code', 'acad.validation.code_required'],
        'min_value en la cualitativa' => [['min_value' => '0'] + $base, 'min_value', 'acad.validation.not_applicable_to_scale_type'],
        'decimals en la cualitativa' => [['decimals' => 1] + $base, 'decimals', 'acad.validation.not_applicable_to_scale_type'],
        'range_min en un valor' => [$withValues([['range_min' => '1'] + $base['values'][0], $base['values'][1]]), 'values.0.range_min', 'acad.validation.not_applicable_to_scale_type'],
        'etiqueta sin idioma por defecto' => [$withValues([['label_translations' => ['en' => 'x']] + $base['values'][0], $base['values'][1]]), 'values.0.label_translations', 'translations.default_locale_required'],
    ];

    foreach ($cases as $label => [$body, $field, $code]) {
        $response = Scales::post($tenant, $admin, $body)->assertStatus(422);

        expect(Scales::errorCode($response, $field))->toBe($code, $label);
    }

    expect(Scales::scales($tenant, false))->toBe(1)->and(Scales::values($tenant, false))->toBe(5);
});

test('CA-ACAD-078 RN-ACAD-84 RN-ACAD-85 RN-ACAD-86 RN-ACAD-87: una mixta 0-10 responde 201 con is_passing derivado del umbral; tramos con hueco, con el umbral dentro de un tramo, fuera de la escala o con is_passing son 422', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-078');

    $response = Scales::post($tenant, $admin, Scales::mixed($year->public_id))->assertCreated();
    $values = $response->json('values');

    expect($response->json('scale_type'))->toBe('mixta')
        ->and([$response->json('min_value'), $response->json('max_value'), $response->json('passing_min_value'), $response->json('decimals')])->toBe(['0.0', '10.0', '5.0', 1])
        ->and(array_column($values, 'is_passing'))->toBe([false, true, true, true])
        ->and(array_column($values, 'range_min'))->toBe(['0.0', '5.0', '7.0', '9.0'])
        ->and(array_column($values, 'range_max'))->toBe(['4.9', '6.9', '8.9', '10.0'])
        ->and(array_column($values, 'position'))->toBe([1, 2, 3, 4]);

    // El is_passing de un tramo no se guarda (RN-ACAD-87): la columna queda a NULL.
    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('grading_scale_values')->whereNotNull('is_passing')->count()))->toBe(0);

    $band = fn (string $code, string $min, string $max): array => Scales::band($code, $code, $min, $max);
    $mixed = fn (array $bands, array $extra = []): array => $extra + ['values' => $bands] + Scales::mixed($year->public_id, ['code' => 'OTRA']);

    $cases = [
        'hueco (falta 5.0)' => [$mixed([$band('A', '0', '4.9'), $band('B', '5.1', '10')]), 'values', 'acad.validation.bands_not_contiguous'],
        'solape' => [$mixed([$band('A', '0', '5'), $band('B', '5', '10')]), 'values', 'acad.validation.bands_not_contiguous'],
        'fuera de orden' => [$mixed([$band('B', '5', '10'), $band('A', '0', '4.9')]), 'values', 'acad.validation.bands_not_contiguous'],
        'no empieza en el mínimo' => [$mixed([$band('A', '0.1', '4.9'), $band('B', '5', '10')]), 'values', 'acad.validation.bands_not_contiguous'],
        'no termina en el máximo' => [$mixed([$band('A', '0', '4.9'), $band('B', '5', '9.9')]), 'values', 'acad.validation.bands_not_contiguous'],
        'umbral dentro de un tramo' => [$mixed([$band('A', '0', '5.9'), $band('B', '6', '10')]), 'values', 'acad.validation.passing_threshold_inside_band'],
        'tramo por encima de la escala' => [$mixed([$band('A', '0', '4.9'), $band('B', '5', '8.9'), $band('C', '9', '11')]), 'values.2.range_max', 'acad.validation.band_range_invalid'],
        'tramo por debajo de la escala' => [$mixed([$band('A', '-1', '4.9'), $band('B', '5', '10')]), 'values.0.range_min', 'acad.validation.band_range_invalid'],
        'inicio mayor que fin' => [$mixed([$band('A', '0', '4.9'), $band('B', '10', '5')]), 'values.1.range_max', 'acad.validation.band_range_invalid'],
        'tramo con is_passing' => [$mixed([['is_passing' => true] + $band('A', '0', '4.9'), $band('B', '5', '10')]), 'values.0.is_passing', 'acad.validation.not_applicable_to_scale_type'],
        'tramo sin range_max' => [$mixed([$band('A', '0', '4.9'), ['code' => 'B', 'label_translations' => ['es-ES' => 'B'], 'range_min' => '5']]), 'values.1.range_max', 'acad.validation.scale_field_required'],
        'límite con más decimales' => [$mixed([$band('A', '0', '4.95'), $band('B', '5', '10')]), 'values.0.range_max', 'acad.validation.decimal_precision_exceeded'],
        'límite no decimal' => [$mixed([$band('A', '0', '4,9'), $band('B', '5', '10')]), 'values.0.range_max', 'acad.validation.decimal_invalid'],
        'sin values' => [array_diff_key($mixed([]), ['values' => 1]), 'values', 'acad.validation.values_required'],
    ];

    foreach ($cases as $label => [$body, $field, $code]) {
        $response = Scales::post($tenant, $admin, $body)->assertStatus(422);

        expect(Scales::errorCode($response, $field))->toBe($code, $label);
    }

    // Un tramo de un solo valor (10-10) es válido; un solo tramo que cubre todo el rango y empieza en el umbral también.
    Scales::create($tenant, $admin, $mixed([$band('A', '0', '4.9'), $band('B', '5', '9.9'), $band('C', '10', '10')], ['code' => 'PUN']));
    Scales::create($tenant, $admin, $mixed([$band('A', '0', '10')], ['code' => 'TODO', 'passing_min_value' => '0']));

    expect(Scales::scales($tenant, false))->toBe(3);
});

test('CA-ACAD-079 RN-ACAD-81 RN-ACAD-89: una numérica con values no vacío es 422 not_applicable_to_scale_type; una cualitativa con 101 valores es 422 values_too_many y con 100, 201', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-079');

    $response = Scales::post($tenant, $admin, Scales::numeric($year->public_id, ['values' => [Scales::value('A', 'A', true)]]))->assertStatus(422);
    expect(Scales::errorCode($response, 'values'))->toBe('acad.validation.not_applicable_to_scale_type');

    // Una lista vacía no informa nada.
    Scales::create($tenant, $admin, Scales::numeric($year->public_id, ['values' => []]));

    $many = fn (int $n): array => array_map(fn (int $i): array => Scales::value("V{$i}", "Valor {$i}", true), range(1, $n));

    $response = Scales::post($tenant, $admin, Scales::qualitative($year->public_id, ['values' => $many(101)]))->assertStatus(422);
    expect(Scales::errorCode($response, 'values'))->toBe('acad.validation.values_too_many')
        ->and($response->json('errors')['values'][0]['params'])->toBe(['max' => 100])
        ->and(Scales::scales($tenant, false))->toBe(1);

    expect(Scales::create($tenant, $admin, Scales::qualitative($year->public_id, ['values' => $many(100)]))['values'])->toHaveCount(100);
});

test('CA-ACAD-080 RN-ACAD-88: el PATCH con values es el conjunto completo: actualiza por public_id fusionando etiquetas, borra el ausente, crea el nuevo, reordena, y repetirlo no audita', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-080');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en', 'fr']);

    $created = Scales::create($tenant, $admin, Scales::qualitative($year->public_id, ['values' => [
        ['label_translations' => ['es-ES' => 'A', 'en' => 'A en'], 'code' => 'A', 'is_passing' => true],
        ['label_translations' => ['es-ES' => 'B', 'en' => 'B en'], 'code' => 'B', 'is_passing' => true],
        ['label_translations' => ['es-ES' => 'C', 'en' => 'C en'], 'code' => 'C', 'is_passing' => false],
    ]]));
    [$a, $b, $c] = array_column($created['values'], 'public_id');
    $before = Scales::auditCount($tenant);

    $body = ['values' => [
        ['public_id' => $c, 'label_translations' => ['fr' => 'C fr']],
        ['public_id' => $a],
        ['code' => 'D', 'label_translations' => ['es-ES' => 'D'], 'is_passing' => true],
    ]];

    $response = Scales::patch($tenant, $admin, $created['public_id'], $body)->assertOk();
    $values = $response->json('values');

    expect(array_column($values, 'code'))->toBe(['C', 'A', 'D'])
        ->and(array_column($values, 'position'))->toBe([1, 2, 3])
        ->and($values[0]['public_id'])->toBe($c)
        ->and($values[0]['label_translations'])->toEqual(['es-ES' => 'C', 'en' => 'C en', 'fr' => 'C fr'])
        ->and($values[1]['public_id'])->toBe($a)
        ->and($values[1]['is_passing'])->toBeTrue()
        ->and($values[2]['public_id'])->not->toBe($b);

    // B queda borrado (deleted), D creado (created), C reposicionado y con fr nuevo (updated), A reposicionado.
    expect(Scales::audit($tenant, 'grading_scale_value', 'deleted'))->toHaveCount(1)
        ->and(Scales::audit($tenant, 'grading_scale_value', 'created'))->toHaveCount(4)
        ->and(Scales::audit($tenant, 'grading_scale_value', 'updated'))->toHaveCount(2)
        ->and(Scales::get($tenant, $admin, '/grading-scales/'.$created['public_id'])->json('values.0.public_id'))->toBe($c);

    $row = Api::row($tenant, 'grading_scale_values', $b);
    expect($row->deleted_at)->not->toBeNull();

    // Repetir el mismo cuerpo (con los public_id vigentes): 200 y ninguna entrada nueva.
    $again = ['values' => [
        ['public_id' => $c, 'label_translations' => ['fr' => 'C fr']],
        ['public_id' => $a],
        ['public_id' => $values[2]['public_id']],
    ]];
    $count = Scales::auditCount($tenant);
    Scales::patch($tenant, $admin, $created['public_id'], $again)->assertOk();
    Scales::patch($tenant, $admin, $created['public_id'], [])->assertOk();
    Scales::patch($tenant, $admin, $created['public_id'], ['code' => 'CUAL', 'name_translations' => ['es-ES' => 'Insuficiente a Sobresaliente']])->assertOk();

    expect(Scales::auditCount($tenant))->toBe($count)->and($count)->toBeGreaterThan($before);

    // Un alta puede reutilizar el código de un valor que el mismo PATCH borra (borrados antes que altas).
    Scales::patch($tenant, $admin, $created['public_id'], ['values' => [
        ['public_id' => $a],
        ['code' => 'C', 'label_translations' => ['es-ES' => 'C nueva'], 'is_passing' => true],
    ]])->assertOk();
});

test('CA-ACAD-081 RN-ACAD-88 INV-001: un public_id de valor de otra escala, de otro centro, borrado, inexistente o repetido es 422 value_not_in_scale y nada cambia', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-081');
    [$other, $otherAdmin, $otherYear] = Scales::setup('esc-081b');

    $scale = Scales::create($tenant, $admin, Scales::qualitative($year->public_id));
    $sibling = Scales::create($tenant, $admin, Scales::qualitative($year->public_id, ['code' => 'HERMANA']));
    $foreign = Scales::create($other, $otherAdmin, Scales::qualitative($otherYear->public_id));

    // Un valor borrado de la propia escala.
    $deletedId = $scale['values'][4]['public_id'];
    Scales::patch($tenant, $admin, $scale['public_id'], ['values' => array_map(fn (array $v): array => ['public_id' => $v['public_id']], array_slice($scale['values'], 0, 4))])->assertOk();

    $mine = $scale['values'][0]['public_id'];
    $before = Scales::snapshot($tenant);
    $beforeOther = Scales::snapshot($other);

    $cases = [
        'de otra escala del centro' => [['public_id' => $sibling['values'][0]['public_id']], 'values.1.public_id'],
        'de otro centro' => [['public_id' => $foreign['values'][0]['public_id']], 'values.1.public_id'],
        'borrado' => [['public_id' => $deletedId], 'values.1.public_id'],
        'inexistente' => [['public_id' => '01JA0000000000000000000099'], 'values.1.public_id'],
        'repetido' => [['public_id' => $mine], 'values.1.public_id'],
        'no es una cadena' => [['public_id' => 7], 'values.1.public_id'],
    ];

    foreach ($cases as $label => [$element, $field]) {
        $response = Scales::patch($tenant, $admin, $scale['public_id'], ['code' => 'CAMBIADO', 'values' => [['public_id' => $mine], $element]])->assertStatus(422);

        expect(Scales::errorCode($response, $field))->toBe('acad.validation.value_not_in_scale', $label)
            ->and(Scales::snapshot($tenant))->toBe($before, $label)
            ->and(Scales::snapshot($other))->toBe($beforeOther, $label);
    }

    // En un alta, un public_id de valor tampoco vale.
    $response = Scales::post($tenant, $admin, Scales::qualitative($year->public_id, ['code' => 'NUEVA', 'values' => [['public_id' => $mine] + Scales::value('X', 'X', true)]]))->assertStatus(422);
    expect(Scales::errorCode($response, 'values.0.public_id'))->toBe('acad.validation.value_not_in_scale');
});

test('CA-ACAD-082 RN-ACAD-70 RN-ACAD-51: un PATCH con scale_type es 422 scale_type_immutable y con academic_year, parent_immutable, aunque sean el mismo valor; la fila no cambia', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-082');
    $scale = Scales::create($tenant, $admin, Scales::numeric($year->public_id));
    $before = Scales::snapshot($tenant);

    $response = Scales::patch($tenant, $admin, $scale['public_id'], ['scale_type' => 'numerica', 'academic_year' => $year->public_id, 'code' => 'OTRO'])->assertStatus(422);

    expect(Scales::errorCode($response, 'scale_type'))->toBe('acad.validation.scale_type_immutable')
        ->and(Scales::errorCode($response, 'academic_year'))->toBe('acad.validation.parent_immutable')
        ->and(Scales::snapshot($tenant))->toBe($before);

    expect(Scales::errorCode(Scales::patch($tenant, $admin, $scale['public_id'], ['scale_type' => 'mixta'])->assertStatus(422), 'scale_type'))->toBe('acad.validation.scale_type_immutable');

    // Un campo que no aplica al tipo, o un tipo ausente o inválido en el alta.
    expect(Scales::errorCode(Scales::patch($tenant, $admin, $scale['public_id'], ['values' => [Scales::value('A', 'A', true)]])->assertStatus(422), 'values'))->toBe('acad.validation.not_applicable_to_scale_type');

    foreach ([null, 'binaria', 5] as $type) {
        $body = Scales::numeric($year->public_id, ['scale_type' => $type]);

        expect(Scales::errorCode(Scales::post($tenant, $admin, $body)->assertStatus(422), 'scale_type'))->toBe('acad.validation.scale_type_invalid');
    }
});

test('CA-ACAD-083 RN-ACAD-88 RN-ACAD-85: un PATCH de una mixta que solo sube max_value valida los tramos guardados y es 422 bands_not_contiguous; con los tramos ajustados, 200', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-083');
    $scale = Scales::create($tenant, $admin, Scales::mixed($year->public_id));
    $before = Scales::snapshot($tenant);

    $response = Scales::patch($tenant, $admin, $scale['public_id'], ['max_value' => '12'])->assertStatus(422);
    expect(Scales::errorCode($response, 'values'))->toBe('acad.validation.bands_not_contiguous')
        ->and(Scales::snapshot($tenant))->toBe($before);

    // Cambiar solo el umbral a mitad de un tramo, o reducir los decimales, valida contra los tramos existentes.
    expect(Scales::errorCode(Scales::patch($tenant, $admin, $scale['public_id'], ['passing_min_value' => '6'])->assertStatus(422), 'values'))->toBe('acad.validation.passing_threshold_inside_band')
        ->and(Scales::errorCode(Scales::patch($tenant, $admin, $scale['public_id'], ['decimals' => 0])->assertStatus(422), 'values.0.range_max'))->toBe('acad.validation.decimal_precision_exceeded');

    $adjusted = Scales::patch($tenant, $admin, $scale['public_id'], [
        'max_value' => '12',
        'values' => [
            ['public_id' => $scale['values'][0]['public_id']],
            ['public_id' => $scale['values'][1]['public_id']],
            ['public_id' => $scale['values'][2]['public_id']],
            ['public_id' => $scale['values'][3]['public_id'], 'range_max' => '12'],
        ],
    ])->assertOk();

    expect($adjusted->json('max_value'))->toBe('12.0')->and($adjusted->json('values.3.range_max'))->toBe('12.0');

    // Cambiar el umbral a una frontera sin enviar values, y con el tipo cualitativo, un campo numérico no aplica.
    Scales::patch($tenant, $admin, $scale['public_id'], ['passing_min_value' => '7'])->assertOk()->assertJsonPath('values.1.is_passing', false)->assertJsonPath('values.2.is_passing', true);

    $qualitative = Scales::create($tenant, $admin, Scales::qualitative($year->public_id));
    expect(Scales::errorCode(Scales::patch($tenant, $admin, $qualitative['public_id'], ['max_value' => '10'])->assertStatus(422), 'max_value'))->toBe('acad.validation.not_applicable_to_scale_type');

    // Quitar todos los aprobados, o todos los valores, es inválido.
    $all = array_map(fn (array $v): array => ['public_id' => $v['public_id'], 'is_passing' => false], $qualitative['values']);
    expect(Scales::errorCode(Scales::patch($tenant, $admin, $qualitative['public_id'], ['values' => $all])->assertStatus(422), 'values'))->toBe('acad.validation.passing_value_required')
        ->and(Scales::errorCode(Scales::patch($tenant, $admin, $qualitative['public_id'], ['values' => []])->assertStatus(422), 'values'))->toBe('acad.validation.values_required');
});

test('CA-ACAD-084 RN-ACAD-71: un código usado por una escala viva del mismo curso es 422 code_taken; en otro curso académico o en una escala borrada se admite; en un PATCH, solo si cambia', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-084');
    $other = Api::setup('esc-084x');

    $first = Scales::create($tenant, $admin, Scales::numeric($year->public_id, ['code' => 'NOTA']));

    expect(Scales::errorCode(Scales::post($tenant, $admin, Scales::numeric($year->public_id, ['code' => 'NOTA']))->assertStatus(422), 'code'))->toBe('acad.validation.code_taken');

    // El mismo código en otro curso académico del mismo centro (el de prueba es el activo; el nuevo, en planificación).
    $nextYear = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-08-31');
    Scales::create($tenant, $admin, Scales::numeric($nextYear->public_id, ['code' => 'NOTA']));

    // Y en otro centro.
    Scales::create($other[0], $other[1], Scales::numeric($other[2]->public_id, ['code' => 'NOTA']));

    // Una escala borrada libera el código.
    Scales::delete($tenant, $admin, $first['public_id'])->assertNoContent();
    $second = Scales::create($tenant, $admin, Scales::numeric($year->public_id, ['code' => 'NOTA']));
    $third = Scales::create($tenant, $admin, Scales::numeric($year->public_id, ['code' => 'OTRA']));

    // PATCH: al código de otra escala viva, 422; al propio, 200; sin espacios alrededor, recortado.
    expect(Scales::errorCode(Scales::patch($tenant, $admin, $third['public_id'], ['code' => 'NOTA'])->assertStatus(422), 'code'))->toBe('acad.validation.code_taken');
    Scales::patch($tenant, $admin, $second['public_id'], ['code' => ' NOTA '])->assertOk()->assertJsonPath('code', 'NOTA');
    Scales::patch($tenant, $admin, $third['public_id'], ['code' => 'RENOMBRADA'])->assertOk();
});

test('CA-ACAD-085 RN-ACAD-76 INV-004: DELETE de una cualitativa con cinco valores responde 204, borra la escala y sus cinco valores y deja seis entradas deleted; GET posterior es 404', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-085');
    $scale = Scales::create($tenant, $admin, Scales::qualitative($year->public_id));
    $other = Scales::create($tenant, $admin, Scales::qualitative($year->public_id, ['code' => 'OTRA']));

    Scales::delete($tenant, $admin, $scale['public_id'])->assertNoContent();

    expect(Api::row($tenant, 'grading_scales', $scale['public_id'])->deleted_at)->not->toBeNull()
        ->and(Scales::scales($tenant))->toBe(1)
        ->and(Scales::values($tenant))->toBe(5)
        ->and(Scales::values($tenant, false))->toBe(10)
        ->and(Scales::audit($tenant, 'grading_scale', 'deleted'))->toHaveCount(1)
        ->and(Scales::audit($tenant, 'grading_scale_value', 'deleted'))->toHaveCount(5);

    foreach ($scale['values'] as $value) {
        expect(Api::row($tenant, 'grading_scale_values', $value['public_id'])->deleted_at)->not->toBeNull();
    }

    Scales::get($tenant, $admin, '/grading-scales/'.$scale['public_id'])->assertNotFound();
    Scales::delete($tenant, $admin, $scale['public_id'])->assertNotFound();
    Scales::patch($tenant, $admin, $scale['public_id'], ['code' => 'X'])->assertNotFound();

    // La otra escala, intacta.
    expect(Scales::get($tenant, $admin, '/grading-scales/'.$other['public_id'])->assertOk()->json('values'))->toHaveCount(5);
});

test('CA-ACAD-087 RN-ACAD-53 ADR-062: una etiqueta sin el idioma por defecto es 422 translations.default_locale_required en values.n.label_translations; el PATCH audita solo label_translations.es-ES y .fr; label y name se ignoran; la etiqueta cae al idioma por defecto', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-087');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en', 'fr']);

    $body = Scales::qualitative($year->public_id);
    $body['values'][1]['label_translations'] = ['en' => 'Pass'];
    expect(Scales::errorCode(Scales::post($tenant, $admin, $body)->assertStatus(422), 'values.1.label_translations'))->toBe('translations.default_locale_required');

    // Un idioma inactivo del centro, o una etiqueta vacía, tampoco.
    $body['values'][1]['label_translations'] = ['es-ES' => 'Suficiente', 'de' => 'Ausreichend'];
    expect(Scales::errorCode(Scales::post($tenant, $admin, $body)->assertStatus(422), 'values.1.label_translations.de'))->toBe('translations.locale_not_active');
    $body['values'][1]['label_translations'] = ['es-ES' => '  '];
    expect(Scales::errorCode(Scales::post($tenant, $admin, $body)->assertStatus(422), 'values.1.label_translations.es-ES'))->toBe('translations.empty_value');

    // label, name y claves desconocidas se ignoran.
    $body = Scales::qualitative($year->public_id, ['name' => 'ignorado', 'campo_raro' => 1]);
    $body['values'][0] += ['label' => 'ignorado', 'name' => 'ignorado'];
    $scale = Scales::create($tenant, $admin, $body);

    expect($scale['name'])->toBe('Insuficiente a Sobresaliente')->and($scale['values'][0]['label'])->toBe('Insuficiente');

    $valueId = $scale['values'][0]['public_id'];
    $keep = array_map(fn (array $v): array => ['public_id' => $v['public_id']], array_slice($scale['values'], 1));
    Scales::patch($tenant, $admin, $scale['public_id'], ['values' => [['public_id' => $valueId, 'label_translations' => ['es-ES' => 'Insuf.', 'fr' => 'Insuffisant']], ...$keep]])->assertOk();

    $updated = Scales::audit($tenant, 'grading_scale_value', 'updated');

    expect($updated)->toHaveCount(1)
        ->and(array_keys($updated[0]->changes))->toEqualCanonicalizing(['label_translations.es-ES', 'label_translations.fr'])
        ->and($updated[0]->changes['label_translations.fr'])->toEqual(['from' => null, 'to' => 'Insuffisant']);

    // El idioma de la persona (fr) manda: la etiqueta con traducción sale en fr y la que no la tiene cae al idioma por defecto del centro (ADR-062 §5.3).
    app(TenantContext::class)->runFor($tenant->id, fn () => $admin->person->forceFill(['locale' => 'fr'])->save());

    $response = Scales::get($tenant, $admin, '/grading-scales/'.$scale['public_id'])->assertOk();
    expect($response->json('values.0.label'))->toBe('Insuffisant')->and($response->json('values.1.label'))->toBe('Suficiente')
        ->and($response->json('values.0.label_translations'))->toEqual(['es-ES' => 'Insuf.', 'fr' => 'Insuffisant']);
});

test('CA-ACAD-104 RN-ACAD-53: un code de escala o de valor con NUL, control C0 o salto de línea es 422 invalid_characters en code o values.n.code, en POST y en PATCH, y no escribe nada', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-104');
    $scale = Scales::create($tenant, $admin, Scales::qualitative($year->public_id));
    $before = Scales::snapshot($tenant);

    foreach (["A\0B", "A\x07B", "A\nB", "A\rB"] as $bad) {
        $response = Scales::post($tenant, $admin, Scales::numeric($year->public_id, ['code' => $bad]))->assertStatus(422);
        expect(Scales::errorCode($response, 'code'))->toBe('acad.validation.invalid_characters', bin2hex($bad));

        $body = Scales::qualitative($year->public_id, ['code' => 'LIMPIO']);
        $body['values'][2]['code'] = $bad;
        expect(Scales::errorCode(Scales::post($tenant, $admin, $body)->assertStatus(422), 'values.2.code'))->toBe('acad.validation.invalid_characters', bin2hex($bad));

        expect(Scales::errorCode(Scales::patch($tenant, $admin, $scale['public_id'], ['code' => $bad])->assertStatus(422), 'code'))->toBe('acad.validation.invalid_characters', bin2hex($bad));

        $patch = ['values' => array_map(fn (array $v): array => ['public_id' => $v['public_id']], $scale['values'])];
        $patch['values'][1]['code'] = $bad;
        expect(Scales::errorCode(Scales::patch($tenant, $admin, $scale['public_id'], $patch)->assertStatus(422), 'values.1.code'))->toBe('acad.validation.invalid_characters', bin2hex($bad));
    }

    expect(Scales::snapshot($tenant))->toBe($before);

    // Un código vacío y uno demasiado largo (más de 255 caracteres).
    expect(Scales::errorCode(Scales::post($tenant, $admin, Scales::numeric($year->public_id, ['code' => '']))->assertStatus(422), 'code'))->toBe('acad.validation.code_required')
        ->and(Scales::post($tenant, $admin, Scales::numeric($year->public_id, ['code' => str_repeat('x', 256)]))->assertStatus(422)->json('errors'))->toHaveKey('code');
});

test('CA-ACAD-105 RN-ACAD-51: el alta sin academic_year es 422 academic_year_required; con uno inexistente, mal formado o de otro centro, 422 academic_year_not_found (no 404); no se crea nada', function (): void {
    [$tenant, $admin, $year] = Scales::setup('esc-105');
    [$other, $otherAdmin, $otherYear] = Scales::setup('esc-105b');

    $withoutYear = Scales::numeric($year->public_id);
    unset($withoutYear['academic_year']);

    foreach ([$withoutYear, Scales::numeric($year->public_id, ['academic_year' => null]), Scales::numeric($year->public_id, ['academic_year' => ' ']), Scales::numeric($year->public_id, ['academic_year' => 7])] as $body) {
        expect(Scales::errorCode(Scales::post($tenant, $admin, $body)->assertStatus(422), 'academic_year'))->toBe('acad.validation.academic_year_required');
    }

    foreach (['01JA0000000000000000000099', 'no-es-un-ulid', "x\0y", $otherYear->public_id] as $unknown) {
        $response = Scales::post($tenant, $admin, Scales::numeric($year->public_id, ['academic_year' => $unknown]))->assertStatus(422);

        expect(Scales::errorCode($response, 'academic_year'))->toBe('acad.validation.academic_year_not_found');
    }

    expect(Scales::scales($tenant, false))->toBe(0)->and(Scales::scales($other, false))->toBe(0);
});
