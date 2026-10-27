<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FamUnitTestHelpers as Fam;

// CLAUDE.md §10 («el endpoint está documentado en OpenAPI»), REQ-FAM-UNIT/api.md §2, INV-006, INV-015. Los 32 endpoints de
// `1.14-a` y `1.14-b` (api.md §2.1 a §2.5, §2.7 y §2.8) documentados, y solo ellos; sus referencias resuelven; los
// esquemas describen lo que la API devuelve de verdad.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return list<string> `VERBO /ruta` */
function famDocumentedOperations(): array
{
    $paths = Yaml::parseFile(base_path('openapi/paths/fam-unit.yaml'))['paths'];
    $documented = [];

    foreach ($paths as $uri => $item) {
        foreach (array_intersect(['get', 'post', 'patch', 'delete', 'put'], array_keys($item)) as $method) {
            $documented[] = strtoupper($method).' '.$uri;
        }
    }

    sort($documented);

    return $documented;
}

test('REQ-FAM-UNIT-001 REQ-FAM-UNIT-004 api.md §2: los 32 endpoints de 1.14-a y 1.14-b están documentados en OpenAPI, enlazados desde openapi.yaml, y solo ellos', function (): void {
    $root = Yaml::parseFile(base_path('openapi.yaml'));
    $paths = Yaml::parseFile(base_path('openapi/paths/fam-unit.yaml'))['paths'];

    foreach (array_keys($paths) as $uri) {
        expect($root['paths'])->toHaveKey($uri)
            ->and($root['paths'][$uri]['$ref'])->toStartWith('openapi/paths/fam-unit.yaml#/paths/');
    }

    $registered = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'fam-unit.')) {
            $registered[] = $route->methods()[0].' /'.$route->uri();
        }
    }

    sort($registered);
    // `{membershipPublicId}` es el nombre de ruta del segundo parámetro, igual en el contrato.
    expect(famDocumentedOperations())->toHaveCount(32)->and($registered)->toBe(famDocumentedOperations());
});

test('REQ-FAM-UNIT-001 api.md §2: toda referencia $ref de fam-unit.yaml resuelve en components.yaml y cada operación tiene identificador, etiqueta y cookie de sesión', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'));
    $document = Yaml::parseFile(base_path('openapi/paths/fam-unit.yaml'));
    $refs = [];

    array_walk_recursive($document, static function ($value, $key) use (&$refs): void {
        if ($key === '$ref' && is_string($value)) {
            $refs[] = $value;
        }
    });

    expect(count($refs))->toBeGreaterThan(100);

    foreach (array_unique($refs) as $ref) {
        expect($ref)->toStartWith('../components.yaml#/');

        $node = $components;

        foreach (explode('/', substr($ref, strlen('../components.yaml#/'))) as $segment) {
            expect(is_array($node) && array_key_exists($segment, $node))->toBeTrue($ref);
            $node = $node[$segment];
        }
    }

    $ids = [];

    foreach ($document['paths'] as $item) {
        foreach (array_intersect(['get', 'post', 'patch', 'delete'], array_keys($item)) as $method) {
            $operation = $item[$method];
            $ids[] = $operation['operationId'];

            expect($operation['tags'][0])->toStartWith('FamUnit / ')
                ->and($operation['security'])->toBe([['SessionCookie' => []]])
                ->and($operation['responses'])->toHaveKey('401')
                ->and($operation['responses'])->toHaveKey('403');
        }
    }

    expect(count($ids))->toBe(count(array_unique($ids)));
});

test('REQ-FAM-UNIT-001 api.md §1: enumerados extensibles, lista blanca de sort y filtros de varios valores en estilo form sin explode', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'))['components'];
    $paths = Yaml::parseFile(base_path('openapi/paths/fam-unit.yaml'))['paths'];

    expect($components['schemas']['GuardianRelationship']['enum'])->toBe(['padre', 'madre', 'tutor_legal', 'abuelo', 'apoderado', 'otro'])
        ->and($components['schemas']['GuardianAccessLevel']['enum'])->toBe(['lectura', 'lectura_escritura'])
        ->and($components['schemas']['GuardianRestrictedCategory']['enum'])->toBe(['salud'])
        ->and($components['schemas']['GuardianLinkStatus']['enum'])->toBe(['activo', 'revocado'])
        ->and($components['schemas']['GuardianRevocationReason']['enum'])->toBe(['resolucion_judicial'])
        ->and($components['parameters']['FamilyUnitSortParam']['schema']['enum'])->toBe(['code', '-code', 'created_at', '-created_at'])
        ->and($components['parameters']['FamUnitCreatedAtSortParam']['schema']['enum'])->toBe(['created_at', '-created_at']);

    foreach (['GuardianRelationship', 'GuardianAccessLevel', 'GuardianRestrictedCategory', 'GuardianLinkStatus', 'GuardianRevocationReason'] as $enum) {
        expect($components['schemas'][$enum]['x-extensible-enum'])->toBeTrue($enum);
    }

    foreach ([['/api/v1/guardians', ['family_unit', 'user']], ['/api/v1/guardian-links', ['family_unit', 'guardian', 'student_person', 'status']]] as [$uri, $names]) {
        $filters = collect($paths[$uri]['get']['parameters'])->whereIn('name', $names);

        expect($filters)->toHaveCount(count($names));

        foreach ($filters as $filter) {
            expect($filter['style'])->toBe('form')->and($filter['explode'])->toBeFalse();
        }
    }
});

test('REQ-FAM-UNIT-001 api.md §1: los esquemas de OpenAPI describen exactamente las claves que la API devuelve (FamilyUnit, Guardian, FamilyUnitStudent y GuardianStudentLink, con y sin revocación)', function (): void {
    [$tenant, $admin] = Fam::setup('fam-openapi');
    $schemas = Yaml::parseFile(base_path('openapi/components.yaml'))['components']['schemas'];

    $unit = Fam::unitId($tenant, $admin);
    [, $guardian] = Fam::familyUser($tenant, $admin, 'tutor', $unit);
    $student = Fam::person($tenant);
    $membership = Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated()->json();
    $link = Fam::link($tenant, $admin, $guardian, $student, ['is_legal_guardian' => true])->assertCreated()->json();

    $keys = fn (string $path) => array_keys(Fam::call($tenant, $admin, 'get', $path)->assertOk()->json());

    // `deleted_at` es el único campo condicional de FamilyUnit y de ahí que no sea obligatorio.
    $unitKeys = $keys("/family-units/{$unit}");
    $unitProperties = array_values(array_diff(array_keys($schemas['FamilyUnit']['properties']), ['deleted_at']));

    expect($unitKeys)->toEqualCanonicalizing($unitProperties)
        ->and($schemas['FamilyUnit']['required'])->toEqualCanonicalizing($unitProperties)
        ->and($keys("/guardians/{$guardian}"))->toEqualCanonicalizing(array_keys($schemas['Guardian']['properties']))
        ->and($schemas['Guardian']['required'])->toEqualCanonicalizing(array_keys($schemas['Guardian']['properties']))
        ->and(array_keys($membership))->toEqualCanonicalizing(array_keys($schemas['FamilyUnitStudent']['properties']))
        ->and($schemas['FamilyUnitStudent']['required'])->toEqualCanonicalizing(array_keys($schemas['FamilyUnitStudent']['properties']));

    // Con revocacion_tutor.leer el vínculo trae `revocation` (documentada, no obligatoria); sin él, el resto exacto.
    $linkProperties = array_keys($schemas['GuardianStudentLink']['properties']);
    $withoutRevocation = array_values(array_diff($linkProperties, ['revocation']));

    expect($keys("/guardian-links/{$link['public_id']}"))->toEqualCanonicalizing($linkProperties)
        ->and($schemas['GuardianStudentLink']['required'])->toEqualCanonicalizing($withoutRevocation);

    $plain = Fam::userWith($tenant, $admin, 'plano', ['vinculo_tutor.leer' => 'todos']);
    expect(array_keys(Fam::call($tenant, $plain, 'get', "/guardian-links/{$link['public_id']}")->assertOk()->json()))->toEqualCanonicalizing($withoutRevocation);
    // Sin revocacion_tutor.leer, `revocation` no aparece; el vínculo revocado ni se ve (RN-FAM-UNIT-40).

    // La revocación añade `principal_cleared`, y `revocation` trae el detalle documentado.
    $revoked = Fam::call($tenant, $admin, 'post', "/guardian-links/{$link['public_id']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk()->json();

    expect(array_keys($revoked))->toEqualCanonicalizing([...$linkProperties, 'principal_cleared'])
        ->and(array_keys($revoked['revocation']))->toEqualCanonicalizing(array_keys($schemas['GuardianLinkRevocation']['properties']))
        ->and($schemas['GuardianLinkRevocation']['required'])->toEqualCanonicalizing(array_keys($schemas['GuardianLinkRevocation']['properties']));

    // Ninguna clave interna ni dato personal de contacto sale por la API.
    foreach ([$unitKeys, $keys("/guardians/{$guardian}"), array_keys($membership), array_keys($revoked)] as $names) {
        expect($names)->not->toContain('id', 'tenant_id', 'created_by', 'updated_by', 'person_id', 'email', 'phone', 'document_number');
    }
});

test('REQ-FAM-UNIT-004 api.md §1.5 a §1.9: enumerados extensibles de 1.14-b, parámetros de varios valores en estilo form sin explode y la lista blanca de sort', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'))['components'];
    $paths = Yaml::parseFile(base_path('openapi/paths/fam-unit.yaml'))['paths'];

    expect($components['schemas']['ImageConsentLevel']['enum'])->toBe(['web', 'redes_sociales'])
        ->and($components['schemas']['ImageConsentState']['enum'])->toBe(['autorizo', 'no_autorizo', 'pendiente'])
        ->and($components['schemas']['ImageConsentDecisionValue']['enum'])->toBe(['autorizo', 'no_autorizo'])
        ->and($components['schemas']['ImageConsentChannel']['enum'])->toBe(['portal', 'presencial'])
        ->and($components['schemas']['TermsVersionKind']['enum'])->toBe(['condiciones_uso', 'consentimiento_imagen_web', 'consentimiento_imagen_redes'])
        ->and($components['schemas']['TermsVersionStatus']['enum'])->toBe(['borrador', 'publicada', 'retirada'])
        ->and($components['parameters']['ImageConsentSortParam']['schema']['enum'])->toBe(['last_decision_at', '-last_decision_at', 'state', '-state'])
        ->and($components['parameters']['TermsVersionSortParam']['schema']['enum'])->toBe(['version_number', '-version_number']);

    foreach (['ImageConsentLevel', 'ImageConsentState', 'ImageConsentDecisionValue', 'ImageConsentChannel', 'TermsVersionKind', 'TermsVersionStatus'] as $enum) {
        expect($components['schemas'][$enum]['x-extensible-enum'])->toBeTrue($enum);
    }

    foreach ([['/api/v1/image-consents', ['family_unit', 'student_person', 'level', 'state']], ['/api/v1/terms-versions', ['kind', 'status']], ['/api/v1/image-consent-texts', ['level']]] as [$uri, $names]) {
        $filters = collect($paths[$uri]['get']['parameters'])->whereIn('name', $names);

        expect($filters)->toHaveCount(count($names));

        foreach ($filters as $filter) {
            expect($filter['style'])->toBe('form')->and($filter['explode'])->toBeFalse();
        }
    }

    // El histórico es por cursor y exige el alumno.
    $history = collect($paths['/api/v1/image-consent-decisions']['get']['parameters']);
    expect($history->firstWhere('name', 'student_person')['required'])->toBeTrue()
        ->and($paths['/api/v1/image-consent-decisions']['get']['responses']['200']['content']['application/json']['schema']['properties']['meta']['$ref'])->toEndWith('/CursorMeta');
});

test('RN-FAM-UNIT-74 api.md §2.4: la descripción de PATCH /guardian-links/{id} documenta que la representación legal solo se verifica en la transición false → true', function (): void {
    $description = Yaml::parseFile(base_path('openapi/paths/fam-unit.yaml'))['paths']['/api/v1/guardian-links/{publicId}']['patch']['description'];

    expect($description)->toContain('RN-FAM-UNIT-74')->and($description)->toContain('false → true')->and($description)->toContain('no** los cambia');
});

test('REQ-FAM-UNIT-004 api.md §1.5 a §1.9: los esquemas de 1.14-b describen exactamente las claves que la API devuelve (ImageConsent, ImageConsentDecision, ImageConsentText, TermsVersion)', function (): void {
    $f = Fam::consentFamily('fam-openapi-ic', twoGuardians: false);
    $schemas = Yaml::parseFile(base_path('openapi/components.yaml'))['components']['schemas'];

    $decision = Fam::decideAs($f['tenant'], $f['r1'], $f['student'], 'web', 'autorizo')->assertCreated()->json();
    $listed = Fam::call($f['tenant'], $f['admin'], 'get', '/image-consents')->assertOk()->json('data.0');
    $history = Fam::call($f['tenant'], $f['admin'], 'get', "/image-consent-decisions?student_person={$f['student']->public_id}")->assertOk()->json('data.0');
    $texts = Fam::call($f['tenant'], $f['r1'], 'get', '/image-consent-texts')->assertOk()->json('data');
    $version = Fam::call($f['tenant'], $f['admin'], 'get', '/terms-versions')->assertOk()->json('data.0');

    $propertiesOf = fn (string $schema): array => array_keys($schemas[$schema]['properties']);

    expect(array_keys($listed))->toEqualCanonicalizing($propertiesOf('ImageConsent'))
        ->and($schemas['ImageConsent']['required'])->toEqualCanonicalizing($propertiesOf('ImageConsent'))
        ->and(array_keys($decision['state']))->toEqualCanonicalizing($propertiesOf('ImageConsent'))
        ->and(array_keys($decision))->toEqualCanonicalizing($propertiesOf('ImageConsentDecisionRecorded'))
        ->and(array_keys($decision['decision']))->toEqualCanonicalizing($propertiesOf('ImageConsentDecision'))
        ->and(array_keys($history))->toEqualCanonicalizing($propertiesOf('ImageConsentDecision'))
        ->and($schemas['ImageConsentDecision']['required'])->toEqualCanonicalizing($propertiesOf('ImageConsentDecision'))
        ->and(array_keys($decision['decision']['consent_text_version']))->toEqualCanonicalizing($propertiesOf('TermsVersionRef'))
        ->and(array_keys($texts[0]))->toEqualCanonicalizing($propertiesOf('ImageConsentText'))
        ->and(array_keys($texts[1]))->toEqualCanonicalizing($propertiesOf('ImageConsentText'))
        ->and($schemas['ImageConsentText']['required'])->toEqualCanonicalizing($propertiesOf('ImageConsentText'))
        ->and(array_keys($version))->toEqualCanonicalizing($propertiesOf('TermsVersion'))
        ->and($schemas['TermsVersion']['required'])->toEqualCanonicalizing($propertiesOf('TermsVersion'));

    // Ninguna clave interna ni dato de evidencia sale por la API.
    foreach ([array_keys($listed), array_keys($history), array_keys($version), array_keys($texts[0])] as $names) {
        expect($names)->not->toContain('id', 'tenant_id', 'created_by', 'updated_by', 'student_person_id', 'guardian_id', 'ip_address', 'user_agent', 'recorded_by_id', 'consent_text_version_id');
    }
});
