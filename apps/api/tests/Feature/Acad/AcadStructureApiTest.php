<?php

use App\Models\AuditLog;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11-b), docs/modulos/REQ-ACAD/api.md §2 (cursos de etapa,
// grupos, subgrupos, asignaturas y su asignación). CA-ACAD-002, -004 (parte
// secuencial), -007, -008, -009, -010, -017, -018, -055 a -062. INV-010,
// INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Cuenta las entradas de auditoría de un tipo y evento, en el contexto del centro. */
function acadAuditCount(object $tenant, string $type, string $event): int
{
    return app(TenantContext::class)->runFor($tenant->id, fn (): int => AuditLog::query()->where('auditable_type', $type)->where('event', $event)->count());
}

function acadPost(object $tenant, object $admin, string $path, array $body)
{
    resetSessionState();

    return test()->actingAs($admin)->postJson(Api::url($tenant, $path), $body);
}

test('CA-ACAD-002 RN-ACAD-05: curso de etapa, grupo, subgrupo y asignatura nacen con el academic_year de su padre sin enviarlo; con academic_year en el cuerpo, 422 academic_year_not_allowed y no se crea nada', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-002');
    $tree = Api::tree($tenant, $admin, $year);

    foreach (['/courses/'.$tree['course'], '/groups/'.$tree['group'], '/subgroups/'.$tree['subgroup'], '/subjects/'.$tree['subject']] as $path) {
        resetSessionState();
        expect(test()->actingAs($admin)->getJson(Api::url($tenant, $path))->assertOk()->json('academic_year'))->toBe($year->public_id, $path);
    }

    $bodies = [
        '/courses' => ['education_level' => $tree['level'], 'code' => 'X', 'name_translations' => ['es-ES' => 'X']],
        '/groups' => ['course' => $tree['course'], 'code' => 'X', 'name' => 'X'],
        "/groups/{$tree['group']}/subgroups" => ['code' => 'X', 'name' => 'X'],
        '/subjects' => ['course' => $tree['course'], 'code' => 'X', 'name_translations' => ['es-ES' => 'X']],
    ];

    foreach ($bodies as $path => $body) {
        $response = acadPost($tenant, $admin, $path, $body + ['academic_year' => $year->public_id])->assertStatus(422);

        expect($response->json('errors.academic_year.0.code'))->toBe('acad.validation.academic_year_not_allowed', $path);
    }

    foreach (['courses' => 1, 'groups' => 1, 'subgroups' => 1, 'subjects' => 1] as $table => $count) {
        expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table($table)->count()))->toBe($count, $table);
    }
});

test('CA-ACAD-056 RN-ACAD-01: code ausente, vacío o con solo espacios es 422 code_required en las cinco entidades con código; con espacios en los extremos se guarda recortado', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-056');
    $tree = Api::tree($tenant, $admin, $year);

    $bodies = [
        '/courses' => ['education_level' => $tree['level'], 'name_translations' => ['es-ES' => 'X']],
        '/groups' => ['course' => $tree['course'], 'name' => 'X'],
        "/groups/{$tree['group']}/subgroups" => ['name' => 'X'],
        '/subjects' => ['course' => $tree['course'], 'name_translations' => ['es-ES' => 'X']],
        '/education-levels' => ['academic_year' => $year->public_id, 'name_translations' => ['es-ES' => 'X'], 'stage' => 'eso', 'regime' => 'publico'],
    ];

    foreach ($bodies as $path => $body) {
        foreach ([[], ['code' => ''], ['code' => '   ']] as $code) {
            $response = acadPost($tenant, $admin, $path, $body + $code)->assertStatus(422);

            expect($response->json('errors.code.0.code'))->toBe('acad.validation.code_required', $path);
        }

        $created = acadPost($tenant, $admin, $path, $body + ['code' => '  Z1  '])->assertCreated();

        expect($created->json('code'))->toBe('Z1', $path);
    }
});

test('CA-ACAD-057 RN-ACAD-13: en grupo y subgrupo name es texto simple obligatorio (name_required) y sí se guarda en POST y PATCH', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-057');
    $tree = Api::tree($tenant, $admin, $year);

    foreach (['/groups' => ['course' => $tree['course'], 'code' => 'Q'], "/groups/{$tree['group']}/subgroups" => ['code' => 'Q']] as $path => $body) {
        foreach ([[], ['name' => ''], ['name' => '  ']] as $name) {
            expect(acadPost($tenant, $admin, $path, $body + $name)->assertStatus(422)->json('errors.name.0.code'))->toBe('acad.validation.name_required', $path);
        }

        expect(acadPost($tenant, $admin, $path, $body + ['name' => ' Nombre simple '])->assertCreated()->json('name'))->toBe('Nombre simple');
    }

    foreach (["/groups/{$tree['group']}", "/subgroups/{$tree['subgroup']}"] as $path) {
        resetSessionState();
        expect(test()->actingAs($admin)->patchJson(Api::url($tenant, $path), ['name' => 'Editado'])->assertOk()->json('name'))->toBe('Editado');
        resetSessionState();
        expect(test()->actingAs($admin)->patchJson(Api::url($tenant, $path), ['name' => ''])->assertStatus(422)->json('errors.name.0.code'))->toBe('acad.validation.name_required');
        // Sin name_translations en la representación.
        expect(test()->actingAs($admin)->getJson(Api::url($tenant, $path))->json())->not->toHaveKey('name_translations');
    }
});

test('CA-ACAD-055 RN-ACAD-04: position 0, negativa o no entera es 422 position_invalid en alta y en PATCH, sin llegar al CHECK', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-055');
    $tree = Api::tree($tenant, $admin, $year);

    foreach ([0, -3, 'uno', 2.5, null] as $position) {
        $patch = test()->actingAs($admin)->patchJson(Api::url($tenant, "/groups/{$tree['group']}"), ['position' => $position])->assertStatus(422);
        expect($patch->json('errors.position.0.code'))->toBe('acad.validation.position_invalid');
        resetSessionState();
    }

    foreach ([0, -3, 'uno', 2.5] as $position) {
        $post = acadPost($tenant, $admin, '/groups', ['course' => $tree['course'], 'code' => 'P'.random_int(1, 9999), 'name' => 'P', 'position' => $position])->assertStatus(422);
        expect($post->json('errors.position.0.code'))->toBe('acad.validation.position_invalid');
    }
});

test('CA-ACAD-007: padre del cuerpo ausente, borrado, inexistente o de otro centro es 422 parent_not_found; grupo de la ruta en esa situación, 404, y no se crea nada', function (): void {
    [$a, $adminA, $yearA] = Api::setup('acad-007a');
    [$b, $adminB, $yearB] = Api::setup('acad-007b');
    $treeA = Api::tree($a, $adminA, $yearA);
    $treeB = Api::tree($b, $adminB, $yearB);

    // Etapa y curso de etapa borrados (hijos primero) para tener un padre borrado.
    $deleted = Api::tree($a, $adminA, $yearA, 'D');
    foreach ([['subgroups', 'subgroup'], ['groups', 'group'], ['subjects', 'subject'], ['courses', 'course'], ['education-levels', 'level']] as [$resource, $key]) {
        resetSessionState();
        test()->actingAs($adminA)->deleteJson(Api::url($a, "/{$resource}/{$deleted[$key]}"))->assertNoContent();
    }

    $unknown = '01JA0000000000000000000099';

    foreach ([
        ['/courses', 'education_level', [$unknown, $treeB['level'], $deleted['level']], ['code' => 'N', 'name_translations' => ['es-ES' => 'N']]],
        ['/groups', 'course', [$unknown, $treeB['course'], $deleted['course']], ['code' => 'N', 'name' => 'N']],
        ['/subjects', 'course', [$unknown, $treeB['course'], $deleted['course']], ['code' => 'N', 'name_translations' => ['es-ES' => 'N']]],
    ] as [$path, $field, $parents, $rest]) {
        foreach ([...$parents, null, ''] as $parent) {
            $body = $rest + ($parent === null ? [] : [$field => $parent]);
            $response = acadPost($a, $adminA, $path, $body)->assertStatus(422);

            expect($response->json("errors.{$field}.0.code"))->toBe('acad.validation.parent_not_found', "{$path} {$field}=".json_encode($parent));
        }
    }

    // Padre de la ruta: 404, nunca 422.
    foreach ([$unknown, $treeB['group'], $deleted['group']] as $group) {
        acadPost($a, $adminA, "/groups/{$group}/subgroups", ['code' => 'N', 'name' => 'N'])->assertNotFound();
        resetSessionState();
        test()->actingAs($adminA)->getJson(Api::url($a, "/groups/{$group}/subgroups"))->assertNotFound();
        resetSessionState();
        test()->actingAs($adminA)->getJson(Api::url($a, "/groups/{$group}/subjects"))->assertNotFound();
        resetSessionState();
        test()->actingAs($adminA)->putJson(Api::url($a, "/groups/{$group}/subjects"), ['subjects' => []])->assertNotFound();
    }

    expect(app(TenantContext::class)->runFor($a->id, fn () => DB::table('groups')->whereNull('deleted_at')->count()))->toBe(1)
        ->and($treeA['group'])->not->toBeEmpty();
});

test('CA-ACAD-007 RN-ACAD-08: el grupo de la ruta de un curso cerrado sin curso_historico.leer es 404 al escribir (con él, 409); un padre del cuerpo ilegible es 422 parent_not_found', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-007c');
    $tree = Api::tree($tenant, $admin, $year);
    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);
    $writer = Api::userWith($tenant, $admin, 'escritor', ['estructura_academica.leer', 'estructura_academica.crear']);
    $historian = Api::userWith($tenant, $admin, 'historiador2', ['estructura_academica.leer', 'estructura_academica.crear', 'curso_historico.leer']);

    acadPost($tenant, $writer, "/groups/{$tree['group']}/subgroups", ['code' => 'N', 'name' => 'N'])->assertNotFound();
    expect(acadPost($tenant, $historian, "/groups/{$tree['group']}/subgroups", ['code' => 'N', 'name' => 'N'])->assertStatus(409)->json('type'))->toBe('urn:pge:error:academic-year-closed');
    // Un padre del cuerpo en un curso cerrado que el usuario no puede leer es indistinguible de uno inexistente: 422 parent_not_found (CA-ACAD-007).
    foreach ([
        ['/courses', 'education_level', $tree['level'], ['name_translations' => ['es-ES' => 'N']]],
        ['/groups', 'course', $tree['course'], ['name' => 'N']],
        ['/subjects', 'course', $tree['course'], ['name_translations' => ['es-ES' => 'N']]],
    ] as [$path, $field, $parent, $rest]) {
        $denied = acadPost($tenant, $writer, $path, [$field => $parent, 'code' => 'N'] + $rest)->assertStatus(422);
        $missing = acadPost($tenant, $writer, $path, [$field => '01JA0000000000000000000099', 'code' => 'N'] + $rest)->assertStatus(422);

        expect($denied->json('errors'))->toBe($missing->json('errors'), $path);
        // Con curso_historico.leer el padre se ve y la escritura la rechaza el disparador.
        expect(acadPost($tenant, $historian, $path, [$field => $parent, 'code' => 'N'] + $rest)->assertStatus(409)->json('type'))->toBe('urn:pge:error:academic-year-closed');
    }
});

test('CA-ACAD-008 RN-ACAD-20: borrar con hijos vivos es 409 has_dependents con params.dependents (curso de etapa, grupo, asignatura); sin hijos, 204', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-008b');
    $tree = Api::tree($tenant, $admin, $year);
    resetSessionState();
    test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$tree['group']}/subjects"), ['subjects' => [$tree['subject']]])->assertOk();

    $delete = function (string $path) use ($tenant, $admin) {
        resetSessionState();

        return test()->actingAs($admin)->deleteJson(Api::url($tenant, $path));
    };

    expect($delete("/courses/{$tree['course']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))->toBe(['groups', 'subjects'])
        ->and($delete("/groups/{$tree['group']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))->toBe(['subgroups', 'subjects'])
        ->and($delete("/subjects/{$tree['subject']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))->toBe(['groups']);

    $conflict = $delete("/subjects/{$tree['subject']}")->assertStatus(409);
    expect($conflict->json('errors.dependents.0.code'))->toBe('acad.conflict.has_dependents')
        ->and($conflict->json('errors.dependents.0.message'))->toContain('MAT');

    // Quitar la asignación y el subgrupo libera al grupo y a la asignatura.
    resetSessionState();
    test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$tree['group']}/subjects"), ['subjects' => []])->assertOk();
    $delete("/subgroups/{$tree['subgroup']}")->assertNoContent();
    $delete("/subjects/{$tree['subject']}")->assertNoContent();
    $delete("/groups/{$tree['group']}")->assertNoContent();
    $delete("/courses/{$tree['course']}")->assertNoContent();

    expect(acadAuditCount($tenant, 'subgroup', 'deleted'))->toBe(1)
        ->and(acadAuditCount($tenant, 'course', 'deleted'))->toBe(1)
        ->and(acadAuditCount($tenant, 'group_subject', 'deleted'))->toBe(1);
});

test('CA-ACAD-017 RN-ACAD-32: PUT /groups/{id}/subjects sustituye el conjunto, con altas y bajas por instancia en audit_logs; repetir el cuerpo no escribe', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-017');
    $tree = Api::tree($tenant, $admin, $year);
    $extra = [];
    foreach (['LEN', 'FIS'] as $code) {
        $extra[] = acadPost($tenant, $admin, '/subjects', ['course' => $tree['course'], 'code' => $code, 'name_translations' => ['es-ES' => $code], 'position' => 5])->assertCreated()->json('public_id');
    }
    [$len, $fis] = $extra;
    $put = function (array $subjects) use ($tenant, $admin, $tree) {
        resetSessionState();

        return test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$tree['group']}/subjects"), ['subjects' => $subjects]);
    };

    expect(array_column($put([$tree['subject'], $len])->assertOk()->json('data'), 'code'))->toBe(['MAT', 'LEN']);
    expect(acadAuditCount($tenant, 'group_subject', 'created'))->toBe(2);

    // Quedan {FIS}: dos bajas y un alta.
    expect(array_column($put([$fis])->assertOk()->json('data'), 'code'))->toBe(['FIS']);
    expect(acadAuditCount($tenant, 'group_subject', 'deleted'))->toBe(2)->and(acadAuditCount($tenant, 'group_subject', 'created'))->toBe(3);

    // Repetir, y repetir con public_id duplicados (conjunto): sin entradas nuevas.
    $put([$fis])->assertOk();
    $put([$fis, $fis])->assertOk();
    expect(acadAuditCount($tenant, 'group_subject', 'created'))->toBe(3)->and(acadAuditCount($tenant, 'group_subject', 'deleted'))->toBe(2);

    // GET devuelve el conjunto; la fila de group_subjects no se expone.
    resetSessionState();
    $get = test()->actingAs($admin)->getJson(Api::url($tenant, "/groups/{$tree['group']}/subjects"))->assertOk()->json('data');
    expect(array_column($get, 'code'))->toBe(['FIS'])->and(array_keys($get[0]))->toBe(['public_id', 'academic_year', 'course', 'code', 'name', 'name_translations', 'position', 'created_at', 'updated_at']);

    // Lista vacía quita todas.
    expect($put([])->assertOk()->json('data'))->toBe([]);
});

test('CA-ACAD-018 RN-ACAD-30: asignatura de otro curso de etapa, borrada o de otro centro en PUT es 422 subject_not_in_course y nada cambia', function (): void {
    [$a, $adminA, $yearA] = Api::setup('acad-018a');
    [$b, $adminB, $yearB] = Api::setup('acad-018b');
    $tree = Api::tree($a, $adminA, $yearA);
    $foreign = Api::tree($b, $adminB, $yearB);
    $course2 = acadPost($a, $adminA, '/courses', ['education_level' => $tree['level'], 'code' => '2ESO', 'name_translations' => ['es-ES' => '2']])->assertCreated()->json('public_id');
    $otherCourse = acadPost($a, $adminA, '/subjects', ['course' => $course2, 'code' => 'FIS', 'name_translations' => ['es-ES' => 'F']])->assertCreated()->json('public_id');
    $gone = acadPost($a, $adminA, '/subjects', ['course' => $tree['course'], 'code' => 'GONE', 'name_translations' => ['es-ES' => 'G']])->assertCreated()->json('public_id');
    resetSessionState();
    test()->actingAs($adminA)->deleteJson(Api::url($a, "/subjects/{$gone}"))->assertNoContent();

    foreach ([$otherCourse, $gone, $foreign['subject'], '01JA0000000000000000000099'] as $bad) {
        resetSessionState();
        $response = test()->actingAs($adminA)->putJson(Api::url($a, "/groups/{$tree['group']}/subjects"), ['subjects' => [$tree['subject'], $bad]])->assertStatus(422);

        expect($response->json('errors.subjects.0.code'))->toBe('acad.validation.subject_not_in_course');
    }

    resetSessionState();
    expect(test()->actingAs($adminA)->getJson(Api::url($a, "/groups/{$tree['group']}/subjects"))->json('data'))->toBe([]);

    // Forma: `subjects` ausente o no lista.
    foreach ([[], ['subjects' => 'x'], ['subjects' => [5]]] as $body) {
        resetSessionState();
        test()->actingAs($adminA)->putJson(Api::url($a, "/groups/{$tree['group']}/subjects"), $body)->assertStatus(422);
    }
});

test('CA-ACAD-062 RN-ACAD-31: un grupo recién creado no tiene asignaturas y crear una asignatura después no la añade a los grupos existentes', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-062');
    $tree = Api::tree($tenant, $admin, $year);

    acadPost($tenant, $admin, '/subjects', ['course' => $tree['course'], 'code' => 'LEN', 'name_translations' => ['es-ES' => 'L']])->assertCreated();
    resetSessionState();

    expect(test()->actingAs($admin)->getJson(Api::url($tenant, "/groups/{$tree['group']}/subjects"))->assertOk()->json('data'))->toBe([]);
});

test('CA-ACAD-060 RN-ACAD-16: un subgrupo tiene un único group; un subject/subjects en el cuerpo no crea ninguna relación', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-060');
    $tree = Api::tree($tenant, $admin, $year);

    $response = acadPost($tenant, $admin, "/groups/{$tree['group']}/subgroups", ['code' => 'S2', 'name' => 'S2', 'subject' => $tree['subject'], 'subjects' => [$tree['subject']], 'group' => $tree['group']])->assertCreated();

    expect($response->json('group'))->toBe($tree['group'])
        ->and($response->json())->not->toHaveKeys(['subject', 'subjects'])
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('group_subjects')->count()))->toBe(0);
});

test('CA-ACAD-061 RN-ACAD-17: POST /subjects sin course es 422 parent_not_found; el mismo code en dos cursos de etapa crea dos asignaturas y cada una aparece solo en su listado ?course=', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-061');
    $tree = Api::tree($tenant, $admin, $year);
    $course2 = acadPost($tenant, $admin, '/courses', ['education_level' => $tree['level'], 'code' => '2ESO', 'name_translations' => ['es-ES' => '2']])->assertCreated()->json('public_id');

    expect(acadPost($tenant, $admin, '/subjects', ['code' => 'X', 'name_translations' => ['es-ES' => 'X']])->assertStatus(422)->json('errors.course.0.code'))->toBe('acad.validation.parent_not_found');

    $second = acadPost($tenant, $admin, '/subjects', ['course' => $course2, 'code' => 'MAT', 'name_translations' => ['es-ES' => 'Mates 2']])->assertCreated()->json('public_id');
    expect($second)->not->toBe($tree['subject']);

    foreach ([$tree['course'] => $tree['subject'], $course2 => $second] as $course => $subject) {
        resetSessionState();
        $list = test()->actingAs($admin)->getJson(Api::url($tenant, "/subjects?course={$course}"))->assertOk()->json('data');

        expect(array_column($list, 'public_id'))->toBe([$subject]);
    }
});

test('api.md §3: filtros por padre (course, education_level, múltiples), un padre de otro curso académico o inexistente da colección vacía, y el listado anidado de subgrupos ordena por position y code', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-filters');
    $other = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Planificacion, '2027-09-01', '2028-06-30');
    $tree = Api::tree($tenant, $admin, $year);
    $treeB = Api::tree($tenant, $admin, $other, 'B');
    $get = function (string $q) use ($tenant, $admin) {
        resetSessionState();

        return test()->actingAs($admin)->getJson(Api::url($tenant, $q));
    };
    $ids = fn ($r) => array_column($r->assertOk()->json('data'), 'public_id');
    $yearQ = "academic_year={$year->public_id}";

    expect($ids($get("/courses?{$yearQ}&education_level={$tree['level']}")))->toBe([$tree['course']])
        ->and($ids($get("/courses?{$yearQ}&education_level={$tree['level']},{$treeB['level']}")))->toBe([$tree['course']])
        ->and($ids($get("/courses?{$yearQ}&education_level={$treeB['level']}")))->toBe([])
        ->and($ids($get("/courses?{$yearQ}&education_level=01JA0000000000000000000099")))->toBe([])
        ->and($ids($get("/groups?{$yearQ}&course={$tree['course']}")))->toBe([$tree['group']])
        ->and($ids($get("/groups?{$yearQ}&education_level={$tree['level']}")))->toBe([$tree['group']])
        ->and($ids($get("/groups?{$yearQ}&course={$treeB['course']}")))->toBe([])
        ->and($ids($get("/subjects?{$yearQ}&education_level={$tree['level']}")))->toBe([$tree['subject']])
        ->and($ids($get("/subjects?academic_year={$other->public_id}")))->toBe([$treeB['subject']]);

    foreach ([['S3', 3], ['S1', 3], ['S2', 1]] as [$code, $position]) {
        acadPost($tenant, $admin, "/groups/{$tree['group']}/subgroups", ['code' => $code, 'name' => $code, 'position' => $position])->assertCreated();
    }

    // D1 (position 1 por defecto) y S2 (1): empate por code; luego S1, S3 (3).
    expect(array_column($get("/groups/{$tree['group']}/subgroups")->assertOk()->json('data'), 'code'))->toBe(['D1', 'S2', 'S1', 'S3']);
    // El listado anidado no admite academic_year: se ignora.
    $get("/groups/{$tree['group']}/subgroups?academic_year={$other->public_id}")->assertOk();
});

test('CA-ACAD-009 CA-ACAD-010 en cursos de etapa y asignaturas: idioma por defecto obligatorio, PATCH por fusión, un idioma retirado del centro no se toca si el PATCH no lo menciona', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-010');
    $tree = Api::tree($tenant, $admin, $year);
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en', 'fr']);

    foreach (['/courses' => ['education_level' => $tree['level']], '/subjects' => ['course' => $tree['course']]] as $path => $parent) {
        expect(acadPost($tenant, $admin, $path, $parent + ['code' => 'T1', 'name_translations' => ['en' => 'Only']])->assertStatus(422)->json('errors.name_translations.0.code'))->toBe('translations.default_locale_required');
        expect(acadPost($tenant, $admin, $path, $parent + ['code' => 'T1', 'name_translations' => ['es-ES' => "a\u{0000}b"]])->assertStatus(422)->json('errors')['name_translations.es-ES'][0]['code'])->toBe('translations.invalid_characters');
    }

    $id = acadPost($tenant, $admin, '/subjects', ['course' => $tree['course'], 'code' => 'T2', 'name_translations' => ['es-ES' => 'Lengua', 'en' => 'Language']])->assertCreated()->json('public_id');
    $patch = function (array $body) use ($tenant, $admin, $id) {
        resetSessionState();

        return test()->actingAs($admin)->patchJson(Api::url($tenant, "/subjects/{$id}"), $body);
    };

    expect($patch(['name_translations' => ['fr' => 'Langue']])->assertOk()->json('name_translations'))->toBe(['es-ES' => 'Lengua', 'en' => 'Language', 'fr' => 'Langue']);

    // El centro retira fr de sus idiomas activos: lo guardado se conserva.
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);
    expect($patch(['name_translations' => ['es-ES' => 'Lengua Castellana']])->assertOk()->json('name_translations'))->toBe(['es-ES' => 'Lengua Castellana', 'en' => 'Language', 'fr' => 'Langue']);
    // Una clave no nula de un idioma no activo: 422; quitarlo con null: permitido.
    expect($patch(['name_translations' => ['fr' => 'Autre']])->assertStatus(422)->json('errors')['name_translations.fr'][0]['code'])->toBe('translations.locale_not_active');
    expect($patch(['name_translations' => ['fr' => null]])->assertOk()->json('name_translations'))->toBe(['es-ES' => 'Lengua Castellana', 'en' => 'Language']);

    $course = $tree['course'];
    resetSessionState();
    expect(test()->actingAs($admin)->patchJson(Api::url($tenant, "/courses/{$course}"), ['name_translations' => ['en' => 'First year']])->assertOk()->json('name_translations'))->toBe(['es-ES' => 'Primero', 'en' => 'First year']);
});

test('CA-ACAD-059 RN-ACAD-12: stage y regime llegan como código técnico, idénticos con cualquier idioma de la petición', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-059');
    Api::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en', 'de', 'fr']);
    $tree = Api::tree($tenant, $admin, $year);

    foreach (['es-ES', 'en', 'de', 'fr'] as $locale) {
        app(TenantContext::class)->runFor($tenant->id, fn () => $admin->person->update(['locale' => $locale]));
        resetSessionState();
        $level = test()->actingAs($admin->unsetRelation('person'))->getJson(Api::url($tenant, "/education-levels/{$tree['level']}"))->assertOk()->json();

        expect([$level['stage'], $level['regime']])->toBe(['eso', 'concertado'], $locale);
    }
});

test('CA-ACAD-004 RN-ACAD-01: reintentar un alta con el mismo código produce 422 code_taken, no un duplicado (sin Idempotency-Key)', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-004');
    $tree = Api::tree($tenant, $admin, $year);
    $body = ['course' => $tree['course'], 'code' => 'DUP', 'name' => 'Dup'];

    acadPost($tenant, $admin, '/groups', $body)->assertCreated();
    expect(acadPost($tenant, $admin, '/groups', $body)->assertStatus(422)->json('errors.code.0.code'))->toBe('acad.validation.code_taken');
    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('groups')->where('code', 'DUP')->count()))->toBe(1);
});

test('S-2 REQ-ACAD-001: code y name rechazan NUL, controles, DEL y, en code, saltos de línea (422 invalid_characters, antes del trim); un NUL interior ya no da 500', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-s2');
    $tree = Api::tree($tenant, $admin, $year);

    $bodies = [
        '/education-levels' => ['academic_year' => $year->public_id, 'name_translations' => ['es-ES' => 'X'], 'stage' => 'eso', 'regime' => 'publico'],
        '/courses' => ['education_level' => $tree['level'], 'name_translations' => ['es-ES' => 'X']],
        '/groups' => ['course' => $tree['course'], 'name' => 'X'],
        "/groups/{$tree['group']}/subgroups" => ['name' => 'X'],
        '/subjects' => ['course' => $tree['course'], 'name_translations' => ['es-ES' => 'X']],
    ];

    foreach ($bodies as $path => $body) {
        foreach (["A\u{0000}B", "A\u{0007}", "A\u{007F}", "A\nB", "A\r\nB", "\u{0000}"] as $code) {
            $response = acadPost($tenant, $admin, $path, $body + ['code' => $code])->assertStatus(422);

            expect($response->json('errors.code.0.code'))->toBe('acad.validation.invalid_characters', $path.' '.json_encode($code));
        }

        // La tabulación sí es un carácter admitido.
        acadPost($tenant, $admin, $path, $body + ['code' => "T\tab".random_int(1, 99999)])->assertCreated();
    }

    foreach (['/groups' => ['course' => $tree['course'], 'code' => 'N1'], "/groups/{$tree['group']}/subgroups" => ['code' => 'N2']] as $path => $body) {
        expect(acadPost($tenant, $admin, $path, $body + ['name' => "A\u{0000}B"])->assertStatus(422)->json('errors.name.0.code'))->toBe('acad.validation.invalid_characters');
        // El nombre simple admite saltos de línea.
        acadPost($tenant, $admin, $path, $body + ['name' => "Línea 1\nLínea 2"])->assertCreated();
    }

    foreach (["/groups/{$tree['group']}" => 'name', "/subgroups/{$tree['subgroup']}" => 'code', "/subjects/{$tree['subject']}" => 'code', "/courses/{$tree['course']}" => 'code', "/education-levels/{$tree['level']}" => 'code'] as $path => $field) {
        resetSessionState();
        expect(test()->actingAs($admin)->patchJson(Api::url($tenant, $path), [$field => "A\u{0000}B"])->assertStatus(422)->json("errors.{$field}.0.code"))->toBe('acad.validation.invalid_characters', $path);
    }

    // Padres, curso del cuerpo, parámetros de consulta y public_id del PUT con NUL: 422/404, nunca 500.
    expect(acadPost($tenant, $admin, '/courses', ['education_level' => "01\u{0000}X", 'code' => 'Q', 'name_translations' => ['es-ES' => 'Q']])->assertStatus(422)->json('errors.education_level.0.code'))->toBe('acad.validation.parent_not_found');
    expect(acadPost($tenant, $admin, '/education-levels', ['academic_year' => "01\u{0000}X", 'code' => 'Q', 'name_translations' => ['es-ES' => 'Q'], 'stage' => 'eso', 'regime' => 'publico'])->assertStatus(422)->json('errors.academic_year.0.code'))->toBe('acad.validation.academic_year_not_found');

    foreach (['/education-levels?academic_year=%00', "/courses?academic_year={$year->public_id}&education_level=a%00b", '/groups?course=%00', '/subjects?education_level=%00'] as $url) {
        resetSessionState();
        test()->actingAs($admin)->getJson(Api::url($tenant, $url))->assertStatus(422);
    }

    resetSessionState();
    test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$tree['group']}/subjects"), ['subjects' => ["01\u{0000}"]])->assertStatus(422);
    resetSessionState();
    test()->actingAs($admin)->getJson(Api::url($tenant, '/groups/%00/subgroups'))->assertNotFound();
});

test('S-3 CA-ACAD-007: un academic_year del cuerpo de una etapa en un curso cerrado ilegible es 422 academic_year_not_found, igual que uno inexistente', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-s3');
    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Cerrado);
    $writer = Api::userWith($tenant, $admin, 'escritor3', ['estructura_academica.leer', 'estructura_academica.crear']);
    $historian = Api::userWith($tenant, $admin, 'historiador3', ['estructura_academica.leer', 'estructura_academica.crear', 'curso_historico.leer']);
    $body = ['code' => 'N', 'name_translations' => ['es-ES' => 'N'], 'stage' => 'eso', 'regime' => 'publico'];

    $denied = acadPost($tenant, $writer, '/education-levels', $body + ['academic_year' => $year->public_id])->assertStatus(422);
    $missing = acadPost($tenant, $writer, '/education-levels', $body + ['academic_year' => '01JA0000000000000000000099'])->assertStatus(422);

    expect($denied->json('errors'))->toBe($missing->json('errors'))
        ->and($denied->json('errors.academic_year.0.code'))->toBe('acad.validation.academic_year_not_found');
    // Con curso_historico.leer lo ve y el disparador lo rechaza.
    expect(acadPost($tenant, $historian, '/education-levels', $body + ['academic_year' => $year->public_id])->assertStatus(409)->json('type'))->toBe('urn:pge:error:academic-year-closed');
});
