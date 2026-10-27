<?php

use App\Models\Person;
use App\Models\User;
use App\Modules\FamUnit\Domain\Models\TermsVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-004 (1.14-b), docs/modulos/REQ-FAM-UNIT/api.md §1.9, §2.7, funcional.md §4.6 y RN-FAM-UNIT-75 (OPEN-FAM-UNIT-23),
// CA-FAM-UNIT-103 a -105, INV-001, INV-002, INV-015: lectura del texto publicado de cada nivel por el tutor.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Fija el idioma de la persona de un usuario (el idioma de la petición, ADR-038 §11). */
function textsLocale(object $tenant, User $user, string $locale): void
{
    app(TenantContext::class)->runFor($tenant->id, fn () => Person::query()->whereKey($user->person_id)->update(['locale' => $locale]));
}

test('CA-FAM-UNIT-103 RN-FAM-UNIT-75: el tutor ve el texto publicado de cada nivel en su idioma — web con la v2 en francés y redes_sociales sin versión —, nunca la retirada ni el borrador', function (): void {
    [$tenant, $admin] = Fam::setup('tx-103');
    Fam::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'fr']);
    $unit = Fam::unitId($tenant, $admin);
    $guardian = Fam::consentGuardian($tenant, $admin, 'r1', $unit);

    $v1 = Fam::publishText($tenant, $admin, 'web', ['es-ES' => 'Título v1'], ['es-ES' => 'Cuerpo v1']);
    $v2 = Fam::publishText($tenant, $admin, 'web', ['es-ES' => 'Título v2', 'fr' => 'Titre v2'], ['es-ES' => 'Cuerpo v2', 'fr' => 'Corps v2']);
    Fam::draftText($tenant, $admin, 'web', ['es-ES' => 'Título v3'], ['es-ES' => 'Cuerpo v3']);
    textsLocale($tenant, $guardian, 'fr');

    $response = Fam::call($tenant, $guardian, 'get', '/image-consent-texts')->assertOk();

    expect(array_keys($response->json()))->toBe(['data'])
        ->and($response->json('data'))->toHaveCount(2)
        ->and(array_keys($response->json('data.0')))->toBe(['level', 'terms_version', 'title', 'body', 'locale', 'published_at'])
        ->and($response->json('data.0'))->toMatchArray(['level' => 'web', 'title' => 'Titre v2', 'body' => 'Corps v2', 'locale' => 'fr'])
        ->and($response->json('data.0.terms_version'))->toBe(['public_id' => $v2, 'kind' => 'consentimiento_imagen_web', 'version_number' => 2])
        ->and($response->json('data.0.published_at'))->not->toBeNull()
        ->and($response->json('data.1'))->toBe(['level' => 'redes_sociales', 'terms_version' => null, 'title' => null, 'body' => null, 'locale' => null, 'published_at' => null]);

    $raw = json_encode($response->json());
    expect($raw)->not->toContain($v1)->and($raw)->not->toContain('v1')->and($raw)->not->toContain('v3');

    // Ni las traducciones completas (eso es la gestión de texto_legal).
    expect(array_keys($response->json('data.0')))->not->toContain('title_translations', 'body_translations');
});

test('CA-FAM-UNIT-104 ADR-062 RN-FAM-UNIT-75: sin traducción al idioma de la petición, título y cuerpo vienen en el idioma por defecto del centro y locale lo dice; el locale coincide con el shown_locale que registra una decisión', function (): void {
    [$tenant, $admin] = Fam::setup('tx-104');
    Fam::setLocales($tenant, $admin, 'es-ES', ['es-ES', 'de']);
    Fam::freeze();
    $unit = Fam::unitId($tenant, $admin);
    $student = Fam::person($tenant);
    Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated();
    $guardian = Fam::consentGuardian($tenant, $admin, 'r1', $unit);
    $g = Fam::guardian($tenant, $admin, $guardian, $unit)->assertCreated()->json('public_id');
    Fam::link($tenant, $admin, $g, $student, ['is_legal_guardian' => true])->assertCreated();
    Fam::publishText($tenant, $admin, 'web', ['es-ES' => 'Título'], ['es-ES' => 'Cuerpo']);
    textsLocale($tenant, $guardian, 'de');
    Fam::tick();

    $text = Fam::call($tenant, $guardian, 'get', '/image-consent-texts?level=web')->assertOk();
    expect($text->json('data'))->toHaveCount(1)
        ->and($text->json('data.0'))->toMatchArray(['level' => 'web', 'title' => 'Título', 'body' => 'Cuerpo', 'locale' => 'es-ES']);

    $shown = Fam::decideAs($tenant, $guardian, $student, 'web', 'autorizo')->assertCreated()->json('decision.shown_locale');
    expect($shown)->toBe($text->json('data.0.locale'));
});

test('CA-FAM-UNIT-105 RN-FAM-UNIT-75: sin consentimiento_imagen.leer es 403 (aunque tenga texto_legal.leer o familia.leer); con el permiso unidad_familiar, un tutor sin ningún vínculo activo recibe lo mismo que cualquier otro sujeto con el permiso', function (): void {
    [$tenant, $admin] = Fam::setup('tx-105');
    Fam::publishText($tenant, $admin, 'web', ['es-ES' => 'Título'], ['es-ES' => 'Cuerpo']);
    $unit = Fam::unitId($tenant, $admin);

    $withoutPermission = Fam::userWith($tenant, $admin, 'sin', ['texto_legal.leer' => 'todos', 'familia.leer' => 'todos']);
    $lonelyGuardian = Fam::consentGuardian($tenant, $admin, 'solo', $unit);
    Fam::guardian($tenant, $admin, $lonelyGuardian, $unit)->assertCreated();
    $staff = Fam::userWith($tenant, $admin, 'centro', ['consentimiento_imagen.leer' => 'todos']);

    Fam::call($tenant, $withoutPermission, 'get', '/image-consent-texts')->assertForbidden();

    $expected = Fam::call($tenant, $staff, 'get', '/image-consent-texts')->assertOk()->json();
    $lonely = Fam::call($tenant, $lonelyGuardian, 'get', '/image-consent-texts')->assertOk()->json();

    expect($lonely)->toBe($expected)->and($expected['data'][0]['title'])->toBe('Título');

    // Un usuario que es tutor de otra familia, con vínculos, ve lo mismo: el texto es del centro, no de la familia.
    $other = Fam::unitId($tenant, $admin, 'UF-OTRA');
    $familyGuardian = Fam::consentGuardian($tenant, $admin, 'familia', $other);
    $gf = Fam::guardian($tenant, $admin, $familyGuardian, $other)->assertCreated()->json('public_id');
    $student = Fam::person($tenant);
    Fam::addStudent($tenant, $admin, $other, $student)->assertCreated();
    Fam::link($tenant, $admin, $gf, $student, ['is_legal_guardian' => true])->assertCreated();
    expect(Fam::call($tenant, $familyGuardian, 'get', '/image-consent-texts')->assertOk()->json())->toBe($expected);

    resetSessionState();
    test()->getJson(Fam::url($tenant, '/image-consent-texts'))->assertUnauthorized();
});

test('CA-FAM-UNIT-043 INV-001 api.md §2.7: el texto de otro centro nunca aparece; el filtro level se valida; sin módulo contratado es 403 module-disabled', function (): void {
    [$tenantA, $adminA] = Fam::setup('tx-iso-a');
    [$tenantB, $adminB] = Fam::setup('tx-iso-b');
    Fam::publishText($tenantA, $adminA, 'web', ['es-ES' => 'Texto del centro A'], ['es-ES' => 'Cuerpo A']);
    Fam::publishText($tenantB, $adminB, 'web', ['es-ES' => 'Texto del centro B'], ['es-ES' => 'Cuerpo B']);

    $a = Fam::call($tenantA, $adminA, 'get', '/image-consent-texts')->assertOk();
    $b = Fam::call($tenantB, $adminB, 'get', '/image-consent-texts')->assertOk();

    expect($a->json('data.0.title'))->toBe('Texto del centro A')->and(json_encode($a->json()))->not->toContain('centro B')
        ->and($b->json('data.0.title'))->toBe('Texto del centro B')->and(json_encode($b->json()))->not->toContain('centro A');

    Fam::call($tenantA, $adminA, 'get', '/image-consent-texts?level=twitter')->assertStatus(422);
    expect(Fam::call($tenantA, $adminA, 'get', '/image-consent-texts?level=redes_sociales')->assertOk()->json('data'))->toHaveCount(1);

    Fam::disableModule($tenantA);
    expect(Fam::call($tenantA, $adminA, 'get', '/image-consent-texts')->assertForbidden()->json('type'))->toBe('urn:pge:error:module-disabled');
});

test('RN-FAM-UNIT-75: la lectura no escribe nada ni audita (no es categoría especial) y solo expone versiones publicadas', function (): void {
    [$tenant, $admin] = Fam::setup('tx-ro');
    Fam::draftText($tenant, $admin, 'web');
    $before = count(Fam::audit($tenant, 'terms_version'));

    $data = Fam::call($tenant, $admin, 'get', '/image-consent-texts')->assertOk()->json('data');

    expect($data[0]['terms_version'])->toBeNull()
        ->and(count(Fam::audit($tenant, 'terms_version')))->toBe($before)
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => TermsVersion::query()->where('status', 'publicada')->count()))->toBe(0);
});
