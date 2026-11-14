<?php

use App\Models\AuditLog;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11-b), docs/modulos/REQ-ACAD/api.md §2 (etapas),
// CA-ACAD-001, -003, -005, -006, -008, -009, -010, -011, -013, -020, -054 a
// -059. Base de datos real y permisos reales (INV-001, INV-002, INV-015).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, mixed> */
function levelBody(object $year, array $overrides = []): array
{
    return array_merge([
        'academic_year' => $year->public_id,
        'code' => 'ESO',
        'name_translations' => ['es-ES' => 'Educación Secundaria Obligatoria'],
        'stage' => 'eso',
        'regime' => 'concertado',
    ], $overrides);
}

test('CA-ACAD-001: POST /education-levels crea la etapa con public_id ULID, curso, name y name_translations, Location y entrada created', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-001');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);

    $response = test()->actingAs($admin)
        ->postJson(Api::url($tenant, '/education-levels'), levelBody($year, ['name_translations' => ['es-ES' => 'Educación Secundaria', 'en' => 'Secondary'], 'position' => 3]))
        ->assertCreated();

    expect($response->json('public_id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($response->json('academic_year'))->toBe($year->public_id)
        ->and($response->json('name'))->toBe('Educación Secundaria')
        ->and($response->json('name_translations'))->toBe(['es-ES' => 'Educación Secundaria', 'en' => 'Secondary'])
        ->and($response->json('stage'))->toBe('eso')
        ->and($response->json('regime'))->toBe('concertado')
        ->and($response->json('position'))->toBe(3)
        ->and(array_keys($response->json()))->toBe(['public_id', 'academic_year', 'code', 'name', 'name_translations', 'stage', 'regime', 'position', 'created_at', 'updated_at'])
        ->and($response->headers->get('Location'))->toEndWith('/api/v1/education-levels/'.$response->json('public_id'));

    $logged = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->where('auditable_type', 'education_level')->where('event', 'created')->count());

    expect($logged)->toBe(1);
});

test('CA-ACAD-003 RN-ACAD-01: un código usado por un hermano vivo es 422 code_taken; en otro curso académico o con el hermano borrado, 201', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-003');
    $other = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-06-30');

    $first = test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year))->assertCreated()->json('public_id');

    $dup = test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year))->assertStatus(422);
    expect($dup->json('errors.code.0.code'))->toBe('acad.validation.code_taken');

    test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($other))->assertCreated();

    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$first}"))->assertNoContent();
    test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year))->assertCreated();
});

test('CA-ACAD-005 CA-ACAD-055 CA-ACAD-056 CA-ACAD-058: validación de stage, regime, position, code y academic_year, sin llegar al CHECK', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-005');
    $post = fn (array $body) => test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), $body);

    expect($post(levelBody($year, ['stage' => 'otra']))->assertStatus(422)->json('errors.stage.0.code'))->toBe('acad.validation.stage_invalid')
        ->and($post(levelBody($year, ['regime' => 'otro']))->assertStatus(422)->json('errors.regime.0.code'))->toBe('acad.validation.regime_invalid')
        ->and($post(levelBody($year, ['position' => 0]))->assertStatus(422)->json('errors.position.0.code'))->toBe('acad.validation.position_invalid')
        ->and($post(levelBody($year, ['position' => 'x']))->assertStatus(422)->json('errors.position.0.code'))->toBe('acad.validation.position_invalid')
        ->and($post(levelBody($year, ['position' => 1.5]))->assertStatus(422)->json('errors.position.0.code'))->toBe('acad.validation.position_invalid')
        ->and($post(levelBody($year, ['code' => '   ']))->assertStatus(422)->json('errors.code.0.code'))->toBe('acad.validation.code_required');

    $noYear = levelBody($year);
    unset($noYear['academic_year']);

    expect($post($noYear)->assertStatus(422)->json('errors.academic_year.0.code'))->toBe('acad.validation.academic_year_required')
        ->and($post(levelBody($year, ['academic_year' => '01JA0000000000000000000000']))->assertStatus(422)->json('errors.academic_year.0.code'))->toBe('acad.validation.academic_year_not_found');

    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('education_levels')->count()))->toBe(0);

    $created = $post(levelBody($year, ['code' => '  ESO  ']))->assertCreated();
    expect($created->json('code'))->toBe('ESO');
});

test('CA-ACAD-054 RN-ACAD-04: sin position recibe la última viva + 1 (el primero, 1); con el mismo position el listado ordena por code', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-054');
    $post = fn (string $code, array $extra = []) => test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year, ['code' => $code] + $extra))->assertCreated()->json();

    expect($post('A')['position'])->toBe(1);
    $post('B', ['position' => 2]);
    $post('C', ['position' => 5]);
    $gone = $post('D', ['position' => 9]);
    test()->actingAs($admin)->deleteJson(Api::url($tenant, '/education-levels/'.$gone['public_id']))->assertNoContent();

    expect($post('E')['position'])->toBe(6);

    $post('Z1', ['position' => 7]);
    $post('Y1', ['position' => 7]);

    $codes = array_column(test()->actingAs($admin)->getJson(Api::url($tenant, '/education-levels?sort=position'))->assertOk()->json('data'), 'code');

    expect($codes)->toBe(['A', 'B', 'C', 'E', 'Y1', 'Z1']);
});

test('CA-ACAD-009 CA-ACAD-010 CA-ACAD-011 ADR-062 §5.2: traducciones — idioma por defecto obligatorio, idiomas activos, PATCH por fusión y name ignorado', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-009');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en', 'fr']);
    $post = fn (array $names) => test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year, ['name_translations' => $names] + ['code' => 'C'.random_int(1, 99999)]));

    expect($post(['en' => 'Only English'])->assertStatus(422)->json('errors.name_translations.0.code'))->toBe('translations.default_locale_required')
        ->and($post(['es-ES' => 'X', 'de' => 'Y'])->assertStatus(422)->json('errors')['name_translations.de'][0]['code'])->toBe('translations.locale_not_active')
        ->and($post(['es-ES' => '   '])->assertStatus(422)->json('errors')['name_translations.es-ES'][0]['code'])->toBe('translations.empty_value')
        ->and($post(['es-ES' => str_repeat('x', 256)])->assertStatus(422)->json('errors')['name_translations.es-ES'][0]['code'])->toBe('translations.too_long');

    $id = $post(['es-ES' => 'Lengua', 'en' => 'Language'])->assertCreated()->json('public_id');
    $patch = fn (array $body) => test()->actingAs($admin)->patchJson(Api::url($tenant, "/education-levels/{$id}"), $body);

    // Fusión: añade fr, es-ES y en intactos.
    expect($patch(['name_translations' => ['fr' => 'Langue']])->assertOk()->json('name_translations'))->toBe(['es-ES' => 'Lengua', 'en' => 'Language', 'fr' => 'Langue']);
    // null quita solo ese idioma.
    expect($patch(['name_translations' => ['fr' => null]])->assertOk()->json('name_translations'))->toBe(['es-ES' => 'Lengua', 'en' => 'Language']);
    // Quitar el idioma por defecto: 422 y nada cambia.
    expect($patch(['name_translations' => ['es-ES' => null]])->assertStatus(422)->json('errors.name_translations.0.code'))->toBe('translations.default_locale_required');
    expect($patch(['name_translations' => ['en' => '']])->assertStatus(422)->json('errors')['name_translations.en'][0]['code'])->toBe('translations.empty_value');
    // `name` en el cuerpo se ignora (200, no 422) y no cambia nada.
    $ignored = $patch(['name' => 'Hackeado', 'code' => 'ESO2'])->assertOk();
    expect($ignored->json('name'))->toBe('Lengua')->and($ignored->json('code'))->toBe('ESO2');
    expect(json_decode(Api::row($tenant, 'education_levels', $id)->name_translations, true))->toBe(['en' => 'Language', 'es-ES' => 'Lengua']);
});

test('CA-ACAD-009 OPEN-062-01: con default_locale = en y active_locales = [en], un alta con solo en es 201', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-009b');
    Api::setLocales($tenant, $admin, 'en', ['en']);

    $response = test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year, ['name_translations' => ['en' => 'Secondary']]))->assertCreated();

    expect($response->json('name'))->toBe('Secondary');
});

test('CA-ACAD-011 CA-ACAD-012 ADR-062 §5.3: name se resuelve en el idioma de la petición con caída al idioma por defecto', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-012');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en', 'de']);
    $id = test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year, ['name_translations' => ['es-ES' => 'Lengua', 'en' => 'Language']]))->assertCreated()->json('public_id');

    // El idioma de la petición es el de la persona autenticada (ADR-038 §11).
    $name = function (string $lang) use ($tenant, $admin, $id): string {
        app(TenantContext::class)->runFor($tenant->id, fn () => $admin->person->update(['locale' => $lang]));
        resetSessionState();

        return test()->actingAs($admin->unsetRelation('person'))->getJson(Api::url($tenant, "/education-levels/{$id}"))->assertOk()->json('name');
    };

    expect($name('en'))->toBe('Language')->and($name('de'))->toBe('Lengua');
});

test('CA-ACAD-013 ADR-062 §5.5: un PATCH que cambia es-ES y añade fr deja exactamente name_translations.es-ES y name_translations.fr en audit_logs', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-013');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en', 'fr']);
    $id = test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year, ['name_translations' => ['es-ES' => 'Lengua', 'en' => 'Language']]))->assertCreated()->json('public_id');

    test()->actingAs($admin)->patchJson(Api::url($tenant, "/education-levels/{$id}"), ['name_translations' => ['es-ES' => 'Lengua Castellana', 'fr' => 'Langue']])->assertOk();

    $changes = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->where('auditable_type', 'education_level')->where('event', 'updated')->firstOrFail()->changes);

    // `updated_at` solo consta si su valor cambia, y su precisión es de segundos: depende de si el PATCH cae
    // en el mismo segundo que el alta. No es un cambio de negocio, así que no forma parte de la comprobación.
    expect(array_values(array_diff(array_keys($changes), ['updated_at', 'updated_by'])))->toEqualCanonicalizing(['name_translations.es-ES', 'name_translations.fr']);
});

test('CA-ACAD-006 RN-ACAD-06: PATCH con academic_year, education_level, course o group es 422 parent_immutable y la fila no cambia', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-006');
    $id = test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year))->assertCreated()->json('public_id');

    foreach (['academic_year', 'education_level', 'course', 'group'] as $key) {
        $response = test()->actingAs($admin)->patchJson(Api::url($tenant, "/education-levels/{$id}"), [$key => $year->public_id, 'code' => 'CAMBIADO'])->assertStatus(422);

        expect($response->json("errors.{$key}.0.code"))->toBe('acad.validation.parent_immutable');
    }

    expect(Api::row($tenant, 'education_levels', $id)->code)->toBe('ESO');
});

test('CA-ACAD-008 RN-ACAD-20: borrar una etapa con cursos de etapa vivos es 409 has_dependents con params.dependents; sin ellos, 204 y entrada deleted', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-008');
    $tree = Api::tree($tenant, $admin, $year);

    $response = test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$tree['level']}"))->assertStatus(409);

    expect($response->json('type'))->toBe('urn:pge:error:conflict')
        ->and($response->json('errors.dependents.0.code'))->toBe('acad.conflict.has_dependents')
        ->and($response->json('errors.dependents.0.params.dependents'))->toBe(['courses'])
        ->and(Api::row($tenant, 'education_levels', $tree['level'])->deleted_at)->toBeNull();

    // Hijos primero.
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/subgroups/{$tree['subgroup']}"))->assertNoContent();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/groups/{$tree['group']}"))->assertNoContent();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/subjects/{$tree['subject']}"))->assertNoContent();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/courses/{$tree['course']}"))->assertNoContent();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$tree['level']}"))->assertNoContent();

    expect(Api::row($tenant, 'education_levels', $tree['level'])->deleted_at)->not->toBeNull();
    // Segundo DELETE: 404.
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$tree['level']}"))->assertNotFound();
});

test('api.md §3: listado — filtros stage y regime múltiples, orden, paginación y validación de parámetros', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-list');
    foreach ([['A', 'eso', 'publico', 1], ['B', 'primaria', 'privado', 2], ['C', 'eso', 'concertado', 3]] as [$code, $stage, $regime, $position]) {
        test()->actingAs($admin)->postJson(Api::url($tenant, '/education-levels'), levelBody($year, compact('code', 'stage', 'regime', 'position')))->assertCreated();
    }

    $get = fn (string $q) => test()->actingAs($admin)->getJson(Api::url($tenant, '/education-levels'.$q));
    $codes = fn ($r) => array_column($r->assertOk()->json('data'), 'code');

    expect($codes($get('?stage=eso')))->toBe(['A', 'C'])
        ->and($codes($get('?stage=eso,primaria&regime=privado,publico')))->toBe(['A', 'B'])
        ->and($codes($get('?sort=-position')))->toBe(['C', 'B', 'A'])
        ->and($codes($get('?sort=-code')))->toBe(['C', 'B', 'A'])
        ->and($get('?per_page=2&page=2')->assertOk()->json('meta'))->toBe(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);

    $get('?per_page=101')->assertStatus(422);
    $get('?sort=name')->assertStatus(422);
    $get('?stage=otra')->assertStatus(422);
    // Parámetro desconocido: se ignora.
    $get('?foo=bar')->assertOk();
});

test('CA-ACAD-031: listado sin academic_year y sin curso activo es 404 curso.no_active_year; academic_year inexistente, 404', function (): void {
    [$tenant, $admin] = provisionCoreTenant('acad-031');
    contractModule($tenant, 'acad');

    $none = test()->actingAs($admin)->getJson(Api::url($tenant, '/education-levels'))->assertNotFound();
    expect($none->json('errors.academic_year.0.code'))->toBe('curso.no_active_year');

    test()->actingAs($admin)->getJson(Api::url($tenant, '/education-levels?academic_year=01JA0000000000000000000000'))->assertNotFound();
});

test('CA-ACAD-033: ciertos listados y detalles de un centro nunca devuelven datos del otro; un public_id ajeno es 404 (detalle, PATCH, DELETE)', function (): void {
    [$a, $adminA, $yearA] = Api::setup('acad-033a');
    [$b, $adminB, $yearB] = Api::setup('acad-033b');
    $mine = test()->actingAs($adminA)->postJson(Api::url($a, '/education-levels'), levelBody($yearA))->assertCreated()->json('public_id');
    resetSessionState();
    $theirs = test()->actingAs($adminB)->postJson(Api::url($b, '/education-levels'), levelBody($yearB))->assertCreated()->json('public_id');
    resetSessionState();

    $asA = fn (string $verb, string $path, array $body = []) => test()->actingAs($adminA)->json($verb, Api::url($a, $path), $body);

    expect(array_column($asA('GET', '/education-levels')->assertOk()->json('data'), 'public_id'))->toBe([$mine])
        ->and($asA('GET', '/education-levels')->json('meta.total'))->toBe(1);
    $asA('GET', "/education-levels/{$theirs}")->assertNotFound();
    $asA('PATCH', "/education-levels/{$theirs}", ['code' => 'X'])->assertNotFound();
    $asA('DELETE', "/education-levels/{$theirs}")->assertNotFound();
    // Con su curso del otro centro: 404 (no existe para este centro).
    $asA('GET', '/education-levels?academic_year='.$yearB->public_id)->assertNotFound();

    expect(Api::row($b, 'education_levels', $theirs)->code)->toBe('ESO');
});
