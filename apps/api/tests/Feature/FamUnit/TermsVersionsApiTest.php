<?php

use App\Models\Person;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-003/-004 (1.14-b), docs/modulos/REQ-FAM-UNIT/api.md §1.7, §2.8, funcional.md §3.9, §4.9 y RN-FAM-UNIT-60,
// -65, -70, -73, CA-FAM-UNIT-098 a -100, ADR-066 §5.5, ADR-062, INV-003, INV-010, INV-015: versiones de los textos legales
// del centro (los dos tipos de imagen; `condiciones_uso` es de 1.14-c).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array{0: object, 1: User} */
function termsScenario(string $slug): array
{
    return Fam::setup($slug);
}

test('CA-FAM-UNIT-099 RN-FAM-UNIT-73: en 1.14-b condiciones_uso es 422 kind_not_available, un tipo desconocido 422 kind_invalid y la ausencia de kind 422 required', function (): void {
    [$tenant, $admin] = termsScenario('tv-099a');
    $texts = ['title_translations' => ['es-ES' => 'T'], 'body_translations' => ['es-ES' => 'Cuerpo']];

    $r = Fam::call($tenant, $admin, 'post', '/terms-versions', ['kind' => 'condiciones_uso'] + $texts)->assertStatus(422);
    expect(Fam::errorCode($r, 'kind'))->toBe('fam_unit.terms.kind_not_available');

    $r = Fam::call($tenant, $admin, 'post', '/terms-versions', ['kind' => 'otro'] + $texts)->assertStatus(422);
    expect(Fam::errorCode($r, 'kind'))->toBe('fam_unit.terms.kind_invalid');

    $r = Fam::call($tenant, $admin, 'post', '/terms-versions', $texts)->assertStatus(422);
    expect(Fam::errorCode($r, 'kind'))->toBe('fam_unit.validation.required');

    $r = Fam::call($tenant, $admin, 'post', '/terms-versions', ['kind' => 5] + $texts)->assertStatus(422);
    expect(Fam::errorCode($r, 'kind'))->toBe('fam_unit.terms.kind_invalid');

    expect(Fam::call($tenant, $admin, 'get', '/terms-versions')->assertOk()->json('data'))->toBe([]);
});

test('RN-FAM-UNIT-73 api.md §1.7: el alta crea un borrador numerado por centro y tipo (1, 2... sin reutilizar, aunque se borre un borrador) y la forma coincide con api.md §1.7', function (): void {
    [$tenant, $admin] = termsScenario('tv-num');

    $a = Fam::call($tenant, $admin, 'post', '/terms-versions', [
        'kind' => 'consentimiento_imagen_web', 'title_translations' => ['es-ES' => 'Título'], 'body_translations' => ['es-ES' => 'Cuerpo'],
    ])->assertCreated();

    expect(array_keys($a->json()))->toBe(['public_id', 'kind', 'version_number', 'title', 'title_translations', 'body', 'body_translations', 'status', 'requires_reacceptance', 'published_at', 'created_at', 'updated_at'])
        ->and($a->json())->toMatchArray(['kind' => 'consentimiento_imagen_web', 'version_number' => 1, 'title' => 'Título', 'body' => 'Cuerpo', 'status' => 'borrador', 'requires_reacceptance' => false, 'published_at' => null])
        ->and($a->headers->get('Location'))->toContain('/api/v1/terms-versions/'.$a->json('public_id'));

    // Otro tipo cuenta aparte; borrar un borrador no libera su número.
    $b = Fam::draftText($tenant, $admin, 'redes_sociales');
    expect(Fam::row($tenant, 'terms_versions', $b)->version_number)->toBe(1);

    $second = Fam::draftText($tenant, $admin, 'web');
    expect(Fam::row($tenant, 'terms_versions', $second)->version_number)->toBe(2);
    Fam::call($tenant, $admin, 'delete', "/terms-versions/{$second}")->assertNoContent();
    expect(Fam::row($tenant, 'terms_versions', Fam::draftText($tenant, $admin, 'web'))->version_number)->toBe(3);

    // El borrado fue lógico.
    $deleted = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('terms_versions')->where('public_id', $second)->value('deleted_at'));
    expect($deleted)->not->toBeNull();
});

test('RN-FAM-UNIT-60 CA-FAM-UNIT-099: publicar retira la publicada anterior del mismo tipo en la misma transacción y no toca las de otros tipos', function (): void {
    [$tenant, $admin] = termsScenario('tv-099b');

    $web1 = Fam::publishText($tenant, $admin, 'web');
    $redes1 = Fam::publishText($tenant, $admin, 'redes_sociales');
    $web2 = Fam::draftText($tenant, $admin, 'web');
    $published = Fam::call($tenant, $admin, 'post', "/terms-versions/{$web2}/publish")->assertOk();

    expect($published->json())->toMatchArray(['status' => 'publicada', 'version_number' => 2, 'requires_reacceptance' => false])
        ->and($published->json('published_at'))->not->toBeNull()
        ->and(Fam::row($tenant, 'terms_versions', $web1)->status)->toBe('retirada')
        ->and(Fam::row($tenant, 'terms_versions', $redes1)->status)->toBe('publicada');

    // Una publicada solo por tipo, garantizado por índice: una inserción directa de otra lo rechaza.
    app(TenantContext::class)->runFor($tenant->id, function () use ($tenant): void {
        $insert = fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('terms_versions')->insert([
            'tenant_id' => $tenant->id, 'public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'kind' => 'consentimiento_imagen_web', 'version_number' => 99,
            'title_translations' => '{"es-ES":"x"}', 'body_translations' => '{"es-ES":"x"}', 'status' => 'publicada', 'requires_reacceptance' => false, 'published_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        expect($insert)->toThrow(QueryException::class, 'terms_versions_tenant_kind_published_unique');
    });

    // Auditoría: alta, publicación de la nueva y retirada de la anterior quedan en audit_logs (ADR-066 §5.8).
    $updated = Fam::audit($tenant, 'terms_version', 'updated');
    expect(Fam::audit($tenant, 'terms_version', 'created'))->toHaveCount(3)
        ->and(count($updated))->toBeGreaterThanOrEqual(4);
});

test('RN-FAM-UNIT-73 CA-FAM-UNIT-100: una versión publicada o retirada no se edita ni se borra (409 not_draft); solo un borrador', function (): void {
    [$tenant, $admin] = termsScenario('tv-100');
    $v1 = Fam::publishText($tenant, $admin, 'web');
    $v2 = Fam::publishText($tenant, $admin, 'web');
    $draft = Fam::draftText($tenant, $admin, 'web');

    foreach ([$v1 => 'retirada', $v2 => 'publicada'] as $id => $status) {
        expect(Fam::row($tenant, 'terms_versions', $id)->status)->toBe($status);

        $patch = Fam::call($tenant, $admin, 'patch', "/terms-versions/{$id}", ['body_translations' => ['es-ES' => 'Cambio']])->assertStatus(409);
        expect(Fam::errorCode($patch, 'status'))->toBe('fam_unit.terms.not_draft');

        $delete = Fam::call($tenant, $admin, 'delete', "/terms-versions/{$id}")->assertStatus(409);
        expect(Fam::errorCode($delete, 'status'))->toBe('fam_unit.terms.not_draft');

        $publish = Fam::call($tenant, $admin, 'post', "/terms-versions/{$id}/publish")->assertStatus(409);
        expect(Fam::errorCode($publish, 'status'))->toBe('fam_unit.terms.not_draft');
    }

    expect(Fam::row($tenant, 'terms_versions', $v2)->body_translations)->toContain('Texto legal del nivel web');

    // El borrador sí se edita y se borra.
    Fam::call($tenant, $admin, 'patch', "/terms-versions/{$draft}", ['body_translations' => ['es-ES' => 'Cambiado']])->assertOk()->assertJsonPath('body', 'Cambiado');
    Fam::call($tenant, $admin, 'delete', "/terms-versions/{$draft}")->assertNoContent();
    Fam::call($tenant, $admin, 'get', "/terms-versions/{$draft}")->assertNotFound();
});

test('CA-FAM-UNIT-100 datos.md §1.6 ADR-066 §5.8: una versión a la que apunta una decisión no se puede borrar ni físicamente (RESTRICT) y la decisión conserva la versión con la que se tomó', function (): void {
    $f = Fam::consentFamily('tv-100b', twoGuardians: false);
    $decisionId = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated()->json('decision.consent_text_version.public_id');

    // La versión publicada y referenciada no se borra por la API...
    Fam::call($f['tenant'], $f['admin'], 'delete', "/terms-versions/{$decisionId}")->assertStatus(409);

    // ...ni físicamente: la clave foránea es RESTRICT. Se prueba con el rol de la aplicación, que sí tiene DELETE sobre la
    // tabla (la del propietario no ve las filas de esta transacción de prueba).
    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($decisionId): void {
        $delete = fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->statement('DELETE FROM terms_versions WHERE public_id = ?', [$decisionId]));

        expect($delete)->toThrow(QueryException::class, 'image_consent_decisions_tenant_text_version_foreign');
    });
});

test('RN-FAM-UNIT-73 api.md §2.8: kind es inmutable (422 kind_not_editable), y un PATCH sin campos editables responde 200 sin cambios', function (): void {
    [$tenant, $admin] = termsScenario('tv-kind');
    $draft = Fam::draftText($tenant, $admin, 'web');

    $r = Fam::call($tenant, $admin, 'patch', "/terms-versions/{$draft}", ['kind' => 'consentimiento_imagen_redes'])->assertStatus(422);
    expect(Fam::errorCode($r, 'kind'))->toBe('fam_unit.terms.kind_not_editable')
        ->and(Fam::row($tenant, 'terms_versions', $draft)->kind)->toBe('consentimiento_imagen_web');

    // La misma `kind` también es error: es inmutable, no «no cambia».
    Fam::call($tenant, $admin, 'patch', "/terms-versions/{$draft}", ['kind' => 'consentimiento_imagen_web'])->assertStatus(422);

    Fam::call($tenant, $admin, 'patch', "/terms-versions/{$draft}", [])->assertOk()->assertJsonPath('version_number', 1);
});

test('ADR-062 RN-FAM-UNIT-65: el título y el cuerpo exigen el idioma por defecto y solo idiomas activos; el PATCH fusiona por idioma y publicar exige el idioma por defecto', function (): void {
    [$tenant, $admin] = termsScenario('tv-i18n');
    $ok = ['kind' => 'consentimiento_imagen_web', 'title_translations' => ['es-ES' => 'T'], 'body_translations' => ['es-ES' => 'C']];

    // Sin el idioma por defecto, o con uno inactivo, o vacío: 422 con los códigos de ADR-062.
    $r = Fam::call($tenant, $admin, 'post', '/terms-versions', ['title_translations' => ['en' => 'T']] + $ok)->assertStatus(422);
    expect(Fam::errorCode($r, 'title_translations.en'))->toBe('translations.locale_not_active');
    $r = Fam::call($tenant, $admin, 'post', '/terms-versions', ['body_translations' => ['es-ES' => 'C', 'de' => 'Text']] + $ok)->assertStatus(422);
    expect(Fam::errorCode($r, 'body_translations.de'))->toBe('translations.locale_not_active');
    Fam::call($tenant, $admin, 'post', '/terms-versions', ['body_translations' => ['es-ES' => '  ']] + $ok)->assertStatus(422);
    Fam::call($tenant, $admin, 'post', '/terms-versions', ['title_translations' => null] + $ok)->assertStatus(422);

    // Fusión por idioma en el PATCH.
    Fam::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);
    $id = Fam::call($tenant, $admin, 'post', '/terms-versions', $ok)->assertCreated()->json('public_id');
    $patched = Fam::call($tenant, $admin, 'patch', "/terms-versions/{$id}", ['title_translations' => ['en' => 'Title']])->assertOk();
    expect($patched->json('title_translations'))->toEqual(['es-ES' => 'T', 'en' => 'Title']);
    $r = Fam::call($tenant, $admin, 'patch', "/terms-versions/{$id}", ['title_translations' => ['es-ES' => null]])->assertStatus(422);
    expect($r->json('errors'))->toHaveKey('title_translations');

    // Publicar tras cambiar el idioma por defecto del centro a uno que el texto no tiene: 422 default_locale_missing.
    Fam::setLocales($tenant, $admin, 'en', ['es-ES', 'en']);
    Fam::call($tenant, $admin, 'patch', "/terms-versions/{$id}", ['body_translations' => ['en' => null]])->assertStatus(422);
    $other = Fam::call($tenant, $admin, 'post', '/terms-versions', ['title_translations' => ['en' => 'T'], 'body_translations' => ['en' => 'B']] + $ok)->assertCreated()->json('public_id');
    Fam::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'en']);
    $r = Fam::call($tenant, $admin, 'post', "/terms-versions/{$other}/publish")->assertStatus(422);
    expect(Fam::errorCode($r, 'title_translations'))->toBe('fam_unit.terms.default_locale_missing')
        ->and(Fam::row($tenant, 'terms_versions', $other)->status)->toBe('borrador');
});

test('api.md §2.8 datos.md §1.8: requires_reacceptance = true en un tipo de imagen es 422 requires_reacceptance_not_allowed; false o ausente publica con false', function (): void {
    [$tenant, $admin] = termsScenario('tv-reacc');
    $id = Fam::draftText($tenant, $admin, 'web');

    $r = Fam::call($tenant, $admin, 'post', "/terms-versions/{$id}/publish", ['requires_reacceptance' => true])->assertStatus(422);
    expect(Fam::errorCode($r, 'requires_reacceptance'))->toBe('fam_unit.terms.requires_reacceptance_not_allowed')
        ->and(Fam::row($tenant, 'terms_versions', $id)->status)->toBe('borrador');

    Fam::call($tenant, $admin, 'post', "/terms-versions/{$id}/publish", ['requires_reacceptance' => 'si'])->assertStatus(422);
    Fam::call($tenant, $admin, 'post', "/terms-versions/{$id}/publish", ['requires_reacceptance' => false])->assertOk()->assertJsonPath('requires_reacceptance', false);

    // El CHECK del motor también lo impide.
    app(TenantContext::class)->runFor($tenant->id, function () use ($id): void {
        $update = fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->statement('UPDATE terms_versions SET requires_reacceptance = true WHERE public_id = ?', [$id]));

        expect($update)->toThrow(QueryException::class, 'terms_versions_reacceptance_kind_check');
    });
});

test('CA-FAM-UNIT-098 RN-FAM-UNIT-70: publicar la versión 2 no altera ningún estado — el autorizo sigue y el histórico muestra la decisión con la versión 1, ya retirada', function (): void {
    $f = Fam::consentFamily('tv-098', twoGuardians: false);
    $v1 = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated()->json('decision.consent_text_version.public_id');

    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('autorizo');

    Fam::tick();
    $v2 = Fam::publishText($f['tenant'], $f['admin'], 'web', ['es-ES' => 'Versión 2'], ['es-ES' => 'Cuerpo 2']);

    expect(Fam::consentState($f['tenant'], $f['admin'], $f['student'], 'web'))->toBe('autorizo')
        ->and(Fam::row($f['tenant'], 'terms_versions', $v1)->status)->toBe('retirada');

    $history = Fam::call($f['tenant'], $f['admin'], 'get', "/image-consent-decisions?student_person={$f['student']->public_id}")->assertOk();
    expect($history->json('data'))->toHaveCount(1)
        ->and($history->json('data.0.consent_text_version.public_id'))->toBe($v1)
        ->and($history->json('data.0.consent_text_version.version_number'))->toBe(1)
        ->and($v2)->not->toBe($v1);
});

test('api.md §3: el listado de versiones filtra por kind y status, ordena por version_number (defecto descendente) y valida los parámetros', function (): void {
    [$tenant, $admin] = termsScenario('tv-list');
    $w1 = Fam::publishText($tenant, $admin, 'web');
    $w2 = Fam::publishText($tenant, $admin, 'web');
    $w3 = Fam::draftText($tenant, $admin, 'web');
    $r1 = Fam::publishText($tenant, $admin, 'redes_sociales');

    $ids = fn (string $query) => array_column(Fam::call($tenant, $admin, 'get', "/terms-versions{$query}")->assertOk()->json('data'), 'public_id');

    expect($ids('?kind=consentimiento_imagen_web'))->toBe([$w3, $w2, $w1])
        ->and($ids('?kind=consentimiento_imagen_web&sort=version_number'))->toBe([$w1, $w2, $w3])
        ->and($ids('?status=borrador'))->toBe([$w3])
        ->and($ids('?kind=consentimiento_imagen_redes&status=publicada'))->toBe([$r1])
        ->and($ids('?kind=consentimiento_imagen_web,consentimiento_imagen_redes&status=retirada'))->toBe([$w1]);

    Fam::call($tenant, $admin, 'get', '/terms-versions?kind=otro')->assertStatus(422);
    Fam::call($tenant, $admin, 'get', '/terms-versions?status=otro')->assertStatus(422);
    Fam::call($tenant, $admin, 'get', '/terms-versions?sort=title')->assertStatus(422);
    Fam::call($tenant, $admin, 'get', '/terms-versions?per_page=101')->assertStatus(422);

    $page = Fam::call($tenant, $admin, 'get', '/terms-versions?per_page=2&page=2')->assertOk();
    expect($page->json('meta'))->toBe(['current_page' => 2, 'per_page' => 2, 'total' => 4, 'last_page' => 2])
        ->and($page->json('data'))->toHaveCount(2);

    Fam::call($tenant, $admin, 'get', '/terms-versions/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

test('ADR-062 §5.4: title y body se resuelven en el idioma de la petición con la caída al idioma por defecto, junto a las traducciones completas', function (): void {
    [$tenant, $admin] = termsScenario('tv-resolve');
    Fam::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'fr']);

    $id = Fam::call($tenant, $admin, 'post', '/terms-versions', [
        'kind' => 'consentimiento_imagen_web',
        'title_translations' => ['es-ES' => 'Título', 'fr' => 'Titre'],
        'body_translations' => ['es-ES' => 'Cuerpo'],
    ])->assertCreated()->json('public_id');

    // Idioma francés: el título está traducido, el cuerpo no. La etiqueta de idioma describe el texto entero (ambos
    // campos a la vez), así que cae al idioma por defecto para los dos (consistencia de `locale`, OPEN-FAM-UNIT-25).
    app(TenantContext::class)->runFor($tenant->id, fn () => Person::query()->whereKey($admin->person_id)->update(['locale' => 'fr']));
    $show = Fam::call($tenant, $admin, 'get', "/terms-versions/{$id}")->assertOk();

    expect($show->json('title_translations'))->toEqual(['es-ES' => 'Título', 'fr' => 'Titre'])
        ->and($show->json('body'))->toBe('Cuerpo')
        ->and($show->json('title'))->toBe('Título');
});

test('INV-002 RPERM-011 permisos.md §4: crear, editar, borrar y publicar textos legales exigen sus permisos — secretaría solo lee — y sin sesión es 401', function (): void {
    [$tenant, $admin] = termsScenario('tv-perm');
    $draft = Fam::draftText($tenant, $admin, 'web');
    $reader = Fam::userWith($tenant, $admin, 'lector', ['texto_legal.leer' => 'todos']);
    $body = ['kind' => 'consentimiento_imagen_web', 'title_translations' => ['es-ES' => 'T'], 'body_translations' => ['es-ES' => 'C']];

    Fam::call($tenant, $reader, 'get', '/terms-versions')->assertOk();
    Fam::call($tenant, $reader, 'get', "/terms-versions/{$draft}")->assertOk();
    Fam::call($tenant, $reader, 'post', '/terms-versions', $body)->assertForbidden();
    Fam::call($tenant, $reader, 'patch', "/terms-versions/{$draft}", ['body_translations' => ['es-ES' => 'X']])->assertForbidden();
    Fam::call($tenant, $reader, 'delete', "/terms-versions/{$draft}")->assertForbidden();
    Fam::call($tenant, $reader, 'post', "/terms-versions/{$draft}/publish")->assertForbidden();

    $nobody = Fam::userWith($tenant, $admin, 'nadie', ['consentimiento_imagen.leer' => 'todos']);
    Fam::call($tenant, $nobody, 'get', '/terms-versions')->assertForbidden();

    resetSessionState();
    test()->getJson(Fam::url($tenant, '/terms-versions'))->assertUnauthorized();
    expect(Fam::row($tenant, 'terms_versions', $draft)->status)->toBe('borrador');
});
