<?php

use App\Modules\FamUnit\Application\TermsVersionService;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// ADR-070 (issue #481), REQ-FAM-UNIT-003/-004/-005, INV-010, INV-015: el texto legal de `terms_versions` es texto
// plano; el servidor rechaza el marcado (nunca lo limpia) en create, update y publish.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

dataset('markup', [
    'negrita' => ['<b>hola</b>'],
    'cierre' => ['texto </p>'],
    'script' => ['<script>alert(1)</script>'],
    'comentario' => ['a <!-- x --> b'],
    'doctype' => ['<!DOCTYPE html>'],
    'cdata' => ['<![CDATA[x]]>'],
    'instruccion' => ['<?php echo 1; ?>'],
    'img' => ['<img src=x onerror=alert(1)>'],
    'espacio tras <' => ['< b>x'],
]);

test('CA-070-01 ADR-070: POST /terms-versions rechaza el marcado en título o cuerpo de cualquier idioma y no crea nada', function (string $markup): void {
    [$tenant, $admin] = Fam::setup('tp-01');

    $r = Fam::call($tenant, $admin, 'post', '/terms-versions', [
        'kind' => 'condiciones_uso',
        'title_translations' => ['es-ES' => $markup],
        'body_translations' => ['es-ES' => $markup],
    ])->assertStatus(422);

    expect(Fam::errorCode($r, 'title_translations.es-ES'))->toBe('fam_unit.terms.markup_not_allowed')
        ->and(Fam::errorCode($r, 'body_translations.es-ES'))->toBe('fam_unit.terms.markup_not_allowed')
        ->and(Fam::call($tenant, $admin, 'get', '/terms-versions')->assertOk()->json('data'))->toHaveCount(0);
})->with('markup');

test('CA-070-02 ADR-070: PATCH /terms-versions/{id} rechaza el marcado y el borrador queda como estaba', function (): void {
    [$tenant, $admin] = Fam::setup('tp-02');
    $id = Fam::draftText($tenant, $admin, 'web', ['es-ES' => 'Título'], ['es-ES' => 'Cuerpo limpio']);

    $r = Fam::call($tenant, $admin, 'patch', "/terms-versions/{$id}", ['body_translations' => ['es-ES' => 'Cuerpo <b>x</b>']])->assertStatus(422);

    expect(Fam::errorCode($r, 'body_translations.es-ES'))->toBe('fam_unit.terms.markup_not_allowed');
    $row = Fam::row($tenant, 'terms_versions', $id);
    expect(json_decode($row->body_translations, true))->toBe(['es-ES' => 'Cuerpo limpio']);
});

test('CA-070-03 ADR-070: un borrador con marcado escrito en la base no se publica y sigue en borrador', function (): void {
    [$tenant, $admin] = Fam::setup('tp-03');
    $id = Fam::draftText($tenant, $admin, 'web');

    app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('terms_versions')->where('public_id', $id)
        ->update(['body_translations' => json_encode(['es-ES' => 'Anterior <i>a la regla</i>'])]));

    $r = Fam::call($tenant, $admin, 'post', "/terms-versions/{$id}/publish")->assertStatus(422);

    expect(Fam::errorCode($r, 'body_translations.es-ES'))->toBe('fam_unit.terms.markup_not_allowed')
        ->and(Fam::row($tenant, 'terms_versions', $id)->status)->toBe('borrador');
});

test('CA-070-04 ADR-070: se aceptan y devuelven intactos saltos de línea, un < suelto, &amp; y caracteres no latinos', function (): void {
    [$tenant, $admin] = Fam::setup('tp-04');
    $body = "Línea 1\nLínea 2\n\nmenores de < 14 años, 3<4, A & B, &amp; &#60; 日本語 Ελληνικά";

    $r = Fam::call($tenant, $admin, 'post', '/terms-versions', [
        'kind' => 'condiciones_uso',
        'title_translations' => ['es-ES' => 'Título < 5'],
        'body_translations' => ['es-ES' => $body],
    ])->assertCreated();

    expect($r->json('body'))->toBe($body)->and($r->json('body_translations'))->toBe(['es-ES' => $body]);

    $id = $r->json('public_id');
    Fam::call($tenant, $admin, 'patch', "/terms-versions/{$id}", ['body_translations' => ['es-ES' => $body."\nfin"]])->assertOk();
    Fam::call($tenant, $admin, 'post', "/terms-versions/{$id}/publish")->assertOk();
});

test('CA-070-05 ADR-070: la comprobación vive en TermsVersionService (create y update sin HTTP)', function (): void {
    [$tenant] = Fam::setup('tp-05');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $service = app(TermsVersionService::class);

        $caught = null;
        try {
            $service->create('condiciones_uso', ['es-ES' => 'T'], ['es-ES' => '<script>x</script>']);
        } catch (ApiException $e) {
            $caught = $e;
        }
        expect($caught)->toBeInstanceOf(ApiException::class);

        $version = $service->create('condiciones_uso', ['es-ES' => 'T'], ['es-ES' => 'limpio']);

        expect(fn () => $service->update($version->public_id, ['es-ES' => '</b>'], null))->toThrow(ApiException::class);
    });
});
