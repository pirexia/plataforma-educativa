<?php

use App\Models\AuditLog;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadPeriodHelpers as Periods;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11d-a), docs/modulos/REQ-ACAD/api.md §11, funcional.md
// §4.8, §5.7-§5.8, §6.1, §13.11: períodos lectivos. CA-ACAD-063 a -072,
// -074, INV-010 (validación de negocio en servidor), INV-003 (auditoría),
// INV-015. «Curso académico de prueba»: del 2026-09-01 al 2027-08-31, `activo`.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ACAD-063 RN-ACAD-51 RN-ACAD-53 INV-003: el alta de un período responde 201 con Location, el curso de su etapa sin haberlo enviado, name, name_translations y position 1, y deja una entrada created con las traducciones desglosadas', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-063');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);
    $level = Api::level($tenant, $admin, $year);

    $response = Periods::post($tenant, $admin, Periods::body($level, ['name_translations' => ['es-ES' => 'Primer trimestre', 'en' => 'First term']]))->assertCreated();

    expect($response->json('public_id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($response->headers->get('Location'))->toEndWith('/api/v1/academic-periods/'.$response->json('public_id'))
        ->and($response->json('academic_year'))->toBe($year->public_id)
        ->and($response->json('education_level'))->toBe($level)
        ->and($response->json('period_type'))->toBe('trimestre')
        ->and($response->json('name'))->toBe('Primer trimestre')
        ->and($response->json('name_translations'))->toBe(['es-ES' => 'Primer trimestre', 'en' => 'First term'])
        ->and($response->json('starts_on'))->toBe('2026-09-08')
        ->and($response->json('ends_on'))->toBe('2026-12-22')
        ->and($response->json('position'))->toBe(1)
        ->and(array_keys($response->json()))->not->toContain('id', 'tenant_id', 'education_level_id', 'academic_year_id', 'created_by', 'updated_by', 'deleted_at', 'code');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $audit = AuditLog::query()->where('auditable_type', 'academic_period')->where('event', 'created')->get();

        expect($audit)->toHaveCount(1)
            ->and($audit[0]->changes)->toHaveKeys(['name_translations.es-ES', 'period_type', 'starts_on', 'ends_on', 'position'])
            ->and($audit[0]->changes['name_translations.es-ES'])->toEqual(['from' => null, 'to' => 'Primer trimestre']);
    });

    // GET del recurso creado y segundo período: posición por defecto = la última viva + 1 (RN-ACAD-60).
    resetSessionState();
    test()->actingAs($admin)->getJson(Api::url($tenant, '/academic-periods/'.$response->json('public_id')))->assertOk()->assertJsonPath('name', 'Primer trimestre');

    expect(Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2026-12-23', 'ends_on' => '2027-03-26', 'name_translations' => ['es-ES' => 'Segundo trimestre']]))->assertCreated()->json('position'))->toBe(2);
});

test('CA-ACAD-064 RN-ACAD-51 RN-ACAD-52: academic_year en el cuerpo es 422 academic_year_not_allowed; education_level ausente, inexistente, borrada, ajena o de un curso cerrado ilegible es 422 parent_not_found; nunca se crea nada', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-064');
    [$other, $otherAdmin, $otherYear] = Periods::setup('per-064b');
    $level = Api::level($tenant, $admin, $year);
    $foreign = Api::level($other, $otherAdmin, $otherYear);

    $withYear = Periods::post($tenant, $admin, Periods::body($level, ['academic_year' => $year->public_id]))->assertStatus(422);
    expect($withYear->json('errors.academic_year.0.code'))->toBe('acad.validation.academic_year_not_allowed');

    // Etapa borrada (lógicamente).
    resetSessionState();
    $deletedLevel = Api::level($tenant, $admin, $year, 'BORRADA');
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$deletedLevel}"))->assertNoContent();

    $bodies = [
        'ausente' => array_diff_key(Periods::body($level), ['education_level' => 1]),
        'inexistente' => Periods::body('01JA0000000000000000000099'),
        'borrada' => Periods::body($deletedLevel),
        'de otro centro' => Periods::body($foreign),
        'no es cadena' => Periods::body($level, ['education_level' => 5]),
    ];

    foreach ($bodies as $label => $body) {
        $response = Periods::post($tenant, $admin, $body)->assertStatus(422);

        expect($response->json('errors.education_level.0.code'))->toBe('acad.validation.parent_not_found', $label);
    }

    // Etapa de un curso cerrado que el usuario no puede leer: indistinguible de una inexistente.
    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);
    $writer = Api::userWith($tenant, $admin, 'solo_crear', ['configuracion_academica.crear']);
    $response = Periods::post($tenant, $writer, Periods::body($level))->assertStatus(422);

    expect($response->json('errors.education_level.0.code'))->toBe('acad.validation.parent_not_found')
        // Con curso_historico.leer lo ve y responde el disparador (RN-ACAD-52).
        ->and(Periods::post($tenant, $admin, Periods::body($level))->assertStatus(409)->json('type'))->toBe('urn:pge:error:academic-year-closed')
        ->and(Periods::count($tenant, false))->toBe(0)->and(Periods::count($other, false))->toBe(0);
});

test('CA-ACAD-065 RN-ACAD-55 RN-ACAD-56 INV-010: tipo fuera de vocabulario, fechas inválidas y fin igual o anterior al inicio son 422 de servidor, sin llegar al CHECK', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-065');
    $level = Api::level($tenant, $admin, $year);

    $cases = [
        'tipo bimestre' => [['period_type' => 'bimestre'], 'period_type', 'acad.validation.period_type_invalid'],
        'tipo ausente' => [['period_type' => null], 'period_type', 'acad.validation.period_type_invalid'],
        'mes 13' => [['starts_on' => '2026-13-01'], 'starts_on', 'acad.validation.date_invalid'],
        'formato dd/mm/aaaa' => [['starts_on' => '08/09/2026'], 'starts_on', 'acad.validation.date_invalid'],
        'día inexistente' => [['ends_on' => '2026-02-31'], 'ends_on', 'acad.validation.date_invalid'],
        'fecha numérica' => [['ends_on' => 20261222], 'ends_on', 'acad.validation.date_invalid'],
        'fin igual al inicio' => [['ends_on' => '2026-09-08'], 'ends_on', 'acad.validation.period_ends_before_start'],
        'fin anterior al inicio' => [['ends_on' => '2026-09-01'], 'ends_on', 'acad.validation.period_ends_before_start'],
    ];

    foreach ($cases as $label => [$override, $field, $code]) {
        $response = Periods::post($tenant, $admin, Periods::body($level, $override))->assertStatus(422);

        expect($response->json("errors.{$field}.0.code"))->toBe($code, $label);
    }

    // Faltan las dos fechas: dos errores, uno por campo.
    $response = Periods::post($tenant, $admin, array_diff_key(Periods::body($level), ['starts_on' => 1, 'ends_on' => 1]))->assertStatus(422);
    expect($response->json('errors.starts_on.0.code'))->toBe('acad.validation.date_invalid')
        ->and($response->json('errors.ends_on.0.code'))->toBe('acad.validation.date_invalid')
        ->and(Periods::count($tenant, false))->toBe(0);
});

test('CA-ACAD-066 RN-ACAD-57: un período que empieza antes o termina después del curso es 422 period_outside_academic_year con las fechas del curso; el que coincide exactamente con él es 201', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-066');
    $level = Api::level($tenant, $admin, $year);

    foreach ([['starts_on' => '2026-08-31', 'field' => 'starts_on'], ['ends_on' => '2027-09-01', 'field' => 'ends_on']] as $case) {
        $field = array_pop($case);
        $response = Periods::post($tenant, $admin, Periods::body($level, $case + ['starts_on' => '2026-09-08', 'ends_on' => '2026-12-22']))->assertStatus(422);

        expect($response->json("errors.{$field}.0.code"))->toBe('acad.validation.period_outside_academic_year')
            ->and($response->json("errors.{$field}.0.params"))->toBe(['academic_year_starts_on' => '2026-09-01', 'academic_year_ends_on' => '2027-08-31'])
            ->and($response->json("errors.{$field}.0.message"))->toContain('2026-09-01')->toContain('2027-08-31');
    }

    expect(Periods::count($tenant))->toBe(0);

    Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']))->assertCreated();
});

test('CA-ACAD-067 RN-ACAD-58: compartir un día es solape (params.period); contiguos, otra etapa o un período borrado no solapan', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-067');
    $level = Api::level($tenant, $admin, $year);
    $otherLevel = Api::level($tenant, $admin, $year, 'BACH');

    $first = Periods::post($tenant, $admin, Periods::body($level))->assertCreated()->json('public_id');

    $overlap = Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2026-12-22', 'ends_on' => '2027-03-26']))->assertStatus(422);
    expect($overlap->json('errors.starts_on.0.code'))->toBe('acad.validation.period_overlap')
        ->and($overlap->json('errors.starts_on.0.params'))->toBe(['period' => $first]);

    // Contenido dentro, y que contiene al existente: también solapan.
    Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2026-10-01', 'ends_on' => '2026-11-01']))->assertStatus(422);
    Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2026-09-01', 'ends_on' => '2027-01-10']))->assertStatus(422);

    // Contiguo: el 22 termina, el 23 empieza.
    Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2026-12-23', 'ends_on' => '2027-03-26', 'name_translations' => ['es-ES' => 'Segundo']]))->assertCreated();

    // Otra etapa: los períodos de etapas distintas pueden solaparse.
    Periods::post($tenant, $admin, Periods::body($otherLevel, ['starts_on' => '2026-10-01', 'ends_on' => '2026-11-01']))->assertCreated();

    // Un período borrado de la misma etapa no cuenta.
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/academic-periods/{$first}"))->assertNoContent();
    Periods::post($tenant, $admin, Periods::body($level))->assertCreated();
});

test('CA-ACAD-068 RN-ACAD-59: todos los períodos de una etapa son del mismo tipo (alta y PATCH); el único período vivo sí puede cambiar de tipo', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-068');
    $level = Api::level($tenant, $admin, $year);

    $first = Periods::post($tenant, $admin, Periods::body($level))->assertCreated()->json('public_id');

    $mismatch = Periods::post($tenant, $admin, Periods::body($level, ['period_type' => 'semestre', 'starts_on' => '2027-01-10', 'ends_on' => '2027-06-20']))->assertStatus(422);
    expect($mismatch->json('errors.period_type.0.code'))->toBe('acad.validation.period_type_mismatch')
        ->and($mismatch->json('errors.period_type.0.params'))->toBe(['period_type' => 'trimestre']);

    // PATCH de tipo con un hermano vivo: el mismo 422 y la fila no cambia.
    $second = Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2027-01-10', 'ends_on' => '2027-03-26', 'name_translations' => ['es-ES' => 'Segundo']]))->assertCreated()->json('public_id');
    $patch = Periods::patch($tenant, $admin, $second, ['period_type' => 'semestre'])->assertStatus(422);

    expect($patch->json('errors.period_type.0.code'))->toBe('acad.validation.period_type_mismatch')
        ->and(Api::row($tenant, 'academic_periods', $second)->period_type)->toBe('trimestre');

    // Borrados los hermanos, el único vivo cambia de tipo (§4.8.5).
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/academic-periods/{$second}"))->assertNoContent();
    Periods::patch($tenant, $admin, $first, ['period_type' => 'semestre'])->assertOk()->assertJsonPath('period_type', 'semestre');

    // Otra etapa puede tener otro tipo.
    $otherLevel = Api::level($tenant, $admin, $year, 'BACH');
    Periods::post($tenant, $admin, Periods::body($otherLevel, ['period_type' => 'cuatrimestre']))->assertCreated();
});

test('CA-ACAD-070 RN-ACAD-51: PATCH de fechas válidas deja updated con starts_on/ends_on {from,to}, se valida excluyéndose a sí mismo, rechaza el padre y el curso y sin cambios no audita', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-070');
    $level = Api::level($tenant, $admin, $year);
    $publicId = Periods::post($tenant, $admin, Periods::body($level))->assertCreated()->json('public_id');

    Periods::patch($tenant, $admin, $publicId, ['starts_on' => '2026-09-10', 'ends_on' => '2026-12-20'])->assertOk()->assertJsonPath('starts_on', '2026-09-10')->assertJsonPath('ends_on', '2026-12-20');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $updated = AuditLog::query()->where('auditable_type', 'academic_period')->where('event', 'updated')->get();

        expect($updated)->toHaveCount(1)
            ->and(array_keys($updated[0]->changes))->toEqualCanonicalizing(['starts_on', 'ends_on'])
            ->and($updated[0]->changes['ends_on']['from'])->toContain('2026-12-22')
            ->and($updated[0]->changes['ends_on']['to'])->toContain('2026-12-20');
    });

    // Solo `ends_on`: no solapa consigo mismo.
    Periods::patch($tenant, $admin, $publicId, ['ends_on' => '2026-12-22'])->assertOk();

    // El padre y el curso son inmutables.
    $other = Api::level($tenant, $admin, $year, 'BACH');
    foreach (['education_level' => $other, 'academic_year' => $year->public_id] as $key => $value) {
        $response = Periods::patch($tenant, $admin, $publicId, [$key => $value])->assertStatus(422);

        expect($response->json("errors.{$key}.0.code"))->toBe('acad.validation.parent_immutable');
    }

    expect(Api::row($tenant, 'academic_periods', $publicId)->starts_on)->toBe('2026-09-10');

    // Sin cambios: 200 sin entrada nueva.
    $before = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->where('auditable_type', 'academic_period')->count());
    Periods::patch($tenant, $admin, $publicId, ['starts_on' => '2026-09-10'])->assertOk();
    Periods::patch($tenant, $admin, $publicId, [])->assertOk();

    expect(app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->where('auditable_type', 'academic_period')->count()))->toBe($before);

    // Reglas sobre el estado resultante.
    Periods::patch($tenant, $admin, $publicId, ['ends_on' => '2026-09-10'])->assertStatus(422)->assertJsonPath('errors.ends_on.0.code', 'acad.validation.period_ends_before_start');
    Periods::patch($tenant, $admin, $publicId, ['starts_on' => '2026-08-01'])->assertStatus(422)->assertJsonPath('errors.starts_on.0.code', 'acad.validation.period_outside_academic_year');
    Periods::patch($tenant, $admin, $publicId, ['starts_on' => null])->assertStatus(422)->assertJsonPath('errors.starts_on.0.code', 'acad.validation.date_invalid');
    Periods::patch($tenant, $admin, $publicId, ['position' => 0])->assertStatus(422)->assertJsonPath('errors.position.0.code', 'acad.validation.position_invalid');
});

test('CA-ACAD-070 RN-ACAD-53 ADR-062: el PATCH de name_translations se fusiona por idioma, deja el desglose en updated y name se ignora', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-070n');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en', 'fr']);
    $level = Api::level($tenant, $admin, $year);
    $publicId = Periods::post($tenant, $admin, Periods::body($level, ['name_translations' => ['es-ES' => 'Primer trimestre', 'en' => 'First term']]))->assertCreated()->json('public_id');

    $patched = Periods::patch($tenant, $admin, $publicId, ['name' => 'ignorado', 'name_translations' => ['es-ES' => '1.º trimestre', 'fr' => 'Premier trimestre']])->assertOk();

    expect($patched->json('name_translations'))->toBe(['es-ES' => '1.º trimestre', 'en' => 'First term', 'fr' => 'Premier trimestre']);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $updated = AuditLog::query()->where('auditable_type', 'academic_period')->where('event', 'updated')->firstOrFail();

        expect(array_keys($updated->changes))->toEqualCanonicalizing(['name_translations.es-ES', 'name_translations.fr']);
    });

    Periods::patch($tenant, $admin, $publicId, ['name_translations' => ['es-ES' => null]])->assertStatus(422)->assertJsonPath('errors.name_translations.0.code', 'translations.default_locale_required');
    expect(Periods::errorCode(Periods::patch($tenant, $admin, $publicId, ['name_translations' => ['en' => '']])->assertStatus(422), 'name_translations.en'))->toBe('translations.empty_value');
});

test('CA-ACAD-063 RN-ACAD-53 ADR-062: el alta exige el idioma por defecto del centro y solo idiomas activos', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-063t');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);
    $level = Api::level($tenant, $admin, $year);

    Periods::post($tenant, $admin, Periods::body($level, ['name_translations' => ['en' => 'First term']]))->assertStatus(422)->assertJsonPath('errors.name_translations.0.code', 'translations.default_locale_required');
    Periods::post($tenant, $admin, Periods::body($level, ['name_translations' => null]))->assertStatus(422)->assertJsonPath('errors.name_translations.0.code', 'translations.default_locale_required');
    // `de` no está activo en el centro; `xx` no es del vocabulario.
    expect(Periods::errorCode(Periods::post($tenant, $admin, Periods::body($level, ['name_translations' => ['es-ES' => 'Primer', 'de' => 'Erstes']]))->assertStatus(422), 'name_translations.de'))->toBe('translations.locale_not_active');
    Periods::post($tenant, $admin, Periods::body($level, ['name_translations' => ['es-ES' => 'Primer', 'xx' => 'Otro']]))->assertStatus(422);
    expect(Periods::errorCode(Periods::post($tenant, $admin, Periods::body($level, ['name_translations' => ['es-ES' => '  ']]))->assertStatus(422), 'name_translations.es-ES'))->toBe('translations.empty_value');

    // Centro con default_locale en: solo `en` es válido (OPEN-062-01).
    Api::setLocales($tenant, $admin, 'en', ['en']);
    Periods::post($tenant, $admin, Periods::body($level, ['name_translations' => ['en' => 'First term']]))->assertCreated();

    expect(Periods::count($tenant))->toBe(1);
});

test('CA-ACAD-071 RN-ACAD-60: el listado va por starts_on ascendente; sort=-starts_on lo invierte; sort=position desempata por starts_on; position 0 o negativa es 422', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-071');
    $level = Api::level($tenant, $admin, $year);

    // Creados en orden de fechas inverso.
    $third = Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2027-04-06', 'ends_on' => '2027-06-20', 'name_translations' => ['es-ES' => 'Tercero'], 'position' => 3]))->assertCreated()->json('public_id');
    $second = Periods::post($tenant, $admin, Periods::body($level, ['starts_on' => '2026-12-23', 'ends_on' => '2027-03-26', 'name_translations' => ['es-ES' => 'Segundo'], 'position' => 1]))->assertCreated()->json('public_id');
    $first = Periods::post($tenant, $admin, Periods::body($level, ['name_translations' => ['es-ES' => 'Primero'], 'position' => 1]))->assertCreated()->json('public_id');

    $list = function (string $query) use ($tenant, $admin): array {
        resetSessionState();

        return test()->actingAs($admin)->getJson(Api::url($tenant, "/academic-periods?education_level={$GLOBALS['__level']}{$query}"))->assertOk()->json('data.*.public_id');
    };
    $GLOBALS['__level'] = $level;

    expect($list(''))->toBe([$first, $second, $third])
        ->and($list('&sort=starts_on'))->toBe([$first, $second, $third])
        ->and($list('&sort=-starts_on'))->toBe([$third, $second, $first])
        // position: 1 (primero y segundo), 3 (tercero); a igual posición, por starts_on ascendente.
        ->and($list('&sort=position'))->toBe([$first, $second, $third])
        ->and($list('&sort=-position'))->toBe([$third, $first, $second]);

    foreach ([0, -1, 1.5, '2'] as $position) {
        Periods::post($tenant, $admin, Periods::body($level, ['position' => $position, 'starts_on' => '2027-07-01', 'ends_on' => '2027-07-10']))->assertStatus(422)->assertJsonPath('errors.position.0.code', 'acad.validation.position_invalid');
    }

    Periods::patch($tenant, $admin, $first, ['position' => -3])->assertStatus(422)->assertJsonPath('errors.position.0.code', 'acad.validation.position_invalid');

    // sort fuera de la lista blanca (el de la estructura no vale aquí).
    resetSessionState();
    test()->actingAs($admin)->getJson(Api::url($tenant, '/academic-periods?sort=code'))->assertStatus(422);
});

test('CA-ACAD-072 RN-ACAD-61: DELETE responde 204, informa deleted_at y deja deleted; una etapa con un período vivo da 409 has_dependents con academic_periods y se borra al borrar el período', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-072');
    $level = Api::level($tenant, $admin, $year);
    $period = Periods::post($tenant, $admin, Periods::body($level))->assertCreated()->json('public_id');

    resetSessionState();
    $conflict = test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$level}"))->assertStatus(409);

    expect($conflict->json('errors.dependents.0.code'))->toBe('acad.conflict.has_dependents')
        ->and($conflict->json('errors.dependents.0.params.dependents'))->toBe(['academic_periods'])
        ->and($conflict->json('detail'))->toContain('períodos lectivos');
    expect(Api::row($tenant, 'education_levels', $level)->deleted_at)->toBeNull();

    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/academic-periods/{$period}"))->assertNoContent();

    expect(Api::row($tenant, 'academic_periods', $period)->deleted_at)->not->toBeNull();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(AuditLog::query()->where('auditable_type', 'academic_period')->where('event', 'deleted')->count())->toBe(1);
    });

    // Un segundo DELETE y un GET posterior: 404.
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/academic-periods/{$period}"))->assertNotFound();
    resetSessionState();
    test()->actingAs($admin)->getJson(Api::url($tenant, "/academic-periods/{$period}"))->assertNotFound();

    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$level}"))->assertNoContent();
});

test('CA-ACAD-072 CA-ACAD-008 RN-ACAD-20 RN-ACAD-61: una etapa con cursos de etapa y períodos vivos lista los dos dependientes, en orden', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-072b');
    $tree = Api::tree($tenant, $admin, $year);
    Periods::post($tenant, $admin, Periods::body($tree['level']))->assertCreated();

    resetSessionState();
    $conflict = test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$tree['level']}"))->assertStatus(409);

    expect($conflict->json('errors.dependents.0.params.dependents'))->toBe(['courses', 'academic_periods']);
});

test('CA-ACAD-074 api.md §11.5: el listado filtra por academic_year (omitido: el activo), education_level y period_type múltiples con AND, y pagina con un máximo de 100', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-074');
    $next = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-08-31');
    $levelA = Api::level($tenant, $admin, $year, 'ESO');
    $levelB = Api::level($tenant, $admin, $year, 'BACH');
    $levelNext = Api::level($tenant, $admin, $next, 'ESO');

    $a1 = Periods::post($tenant, $admin, Periods::body($levelA))->assertCreated()->json('public_id');
    $a2 = Periods::post($tenant, $admin, Periods::body($levelA, ['starts_on' => '2026-12-23', 'ends_on' => '2027-03-26']))->assertCreated()->json('public_id');
    $b1 = Periods::post($tenant, $admin, Periods::body($levelB, ['period_type' => 'semestre', 'starts_on' => '2026-09-08', 'ends_on' => '2027-01-31']))->assertCreated()->json('public_id');
    $n1 = Periods::post($tenant, $admin, Periods::body($levelNext, ['starts_on' => '2027-09-08', 'ends_on' => '2027-12-22']))->assertCreated()->json('public_id');

    $get = function (string $query) use ($tenant, $admin) {
        resetSessionState();

        return test()->actingAs($admin)->getJson(Api::url($tenant, "/academic-periods{$query}"));
    };

    // Sin academic_year: el activo.
    expect($get('')->assertOk()->json('data.*.public_id'))->toBe([$a1, $b1, $a2])
        ->and($get('')->json('meta.total'))->toBe(3)
        ->and($get("?academic_year={$next->public_id}")->assertOk()->json('data.*.public_id'))->toBe([$n1])
        ->and($get("?education_level={$levelA}")->json('data.*.public_id'))->toBe([$a1, $a2])
        ->and($get("?education_level={$levelA},{$levelB}")->json('meta.total'))->toBe(3)
        ->and($get('?period_type=semestre')->json('data.*.public_id'))->toBe([$b1])
        ->and($get('?period_type=semestre,trimestre')->json('meta.total'))->toBe(3)
        // AND entre filtros.
        ->and($get("?education_level={$levelA}&period_type=semestre")->json('data'))->toBe([])
        // Etapa de otro curso académico distinto del filtrado: colección vacía, no error.
        ->and($get("?education_level={$levelNext}")->assertOk()->json('data'))->toBe([])
        ->and($get('?education_level=01JA0000000000000000000099')->assertOk()->json('data'))->toBe([])
        ->and($get('?per_page=2')->json('data'))->toHaveCount(2)
        ->and($get('?per_page=2&page=2')->json('data.*.public_id'))->toBe([$a2]);

    $get('?per_page=101')->assertStatus(422);
    $get('?period_type=bimestre')->assertStatus(422);
    $get('?page=0')->assertStatus(422);
});

test('RN-ACAD-52 api.md §11.5: un parámetro de consulta con NUL o controles es 422, nunca 500', function (): void {
    [$tenant, $admin] = Periods::setup('per-nul');

    foreach (['academic_year', 'education_level'] as $param) {
        resetSessionState();
        test()->actingAs($admin)->getJson(Api::url($tenant, "/academic-periods?{$param}=".rawurlencode("a\0b")))->assertStatus(422);
    }
});

test('RN-ACAD-52 CA-ACAD-088: el alta en un curso en planificacion se admite; el PATCH sin cambios de un período de un curso cerrado es 200 sin escritura y con cambios 409', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-088p', AcademicYearStatus::Planificacion);
    $level = Api::level($tenant, $admin, $year);
    $period = Periods::post($tenant, $admin, Periods::body($level))->assertCreated()->json('public_id');

    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);

    Periods::patch($tenant, $admin, $period, [])->assertOk();
    Periods::patch($tenant, $admin, $period, ['starts_on' => '2026-09-08'])->assertOk();
    expect(Periods::patch($tenant, $admin, $period, ['starts_on' => '2026-09-09'])->assertStatus(409)->json('type'))->toBe('urn:pge:error:academic-year-closed');
    expect(Api::row($tenant, 'academic_periods', $period)->starts_on)->toBe('2026-09-08');
});

test('REQ-ACAD-001 api.md §11.8 CLAUDE.md §10: OpenAPI describe los cinco endpoints de períodos y exactamente las claves que devuelve la API; period_type extensible, name de solo lectura y sort con lista blanca', function (): void {
    [$tenant, $admin, $year] = Periods::setup('per-openapi');
    $level = Api::level($tenant, $admin, $year);
    $publicId = Periods::post($tenant, $admin, Periods::body($level))->assertCreated()->json('public_id');

    resetSessionState();
    $body = test()->actingAs($admin)->getJson(Api::url($tenant, "/academic-periods/{$publicId}"))->assertOk()->json();
    $components = Yaml::parseFile(base_path('openapi/components.yaml'))['components'];
    $paths = Yaml::parseFile(base_path('openapi/paths/acad.yaml'))['paths'];
    $schema = $components['schemas']['AcademicPeriod'];

    expect(array_keys($body))->toEqualCanonicalizing(array_keys($schema['properties']))
        ->and($schema['required'])->toEqualCanonicalizing(array_keys($body))
        ->and($schema['properties']['name']['readOnly'])->toBeTrue()
        ->and($components['schemas']['AcademicPeriodType']['x-extensible-enum'])->toBeTrue()
        ->and($components['schemas']['AcademicPeriodType']['enum'])->toBe(['trimestre', 'cuatrimestre', 'semestre'])
        ->and($components['parameters']['AcademicPeriodSortParam']['schema']['enum'])->toBe(['starts_on', '-starts_on', 'position', '-position']);

    $operations = [];

    foreach (['/api/v1/academic-periods', '/api/v1/academic-periods/{publicId}'] as $uri) {
        foreach (array_intersect(['get', 'post', 'patch', 'delete'], array_keys($paths[$uri])) as $method) {
            $operations[$paths[$uri][$method]['operationId']] = true;
        }
    }

    expect(array_keys($operations))->toEqualCanonicalizing(['listAcademicPeriods', 'createAcademicPeriod', 'getAcademicPeriod', 'updateAcademicPeriod', 'deleteAcademicPeriod']);

    $filters = collect($paths['/api/v1/academic-periods']['get']['parameters'])->whereIn('name', ['education_level', 'period_type']);

    expect($filters)->toHaveCount(2);

    foreach ($filters as $filter) {
        expect($filter['style'])->toBe('form')->and($filter['explode'])->toBeFalse();
    }
});
