<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\AcadApiHelpers as Api;

// CLAUDE.md §10 («el endpoint está documentado en OpenAPI»), REQ-ACAD/api.md
// §10, INV-006, INV-015. Los 27 endpoints de api.md §2 documentados, y solo
// ellos; sus referencias resuelven; los esquemas describen lo que la API
// devuelve de verdad.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return list<string> `VERBO /ruta` */
function acadDocumentedOperations(): array
{
    $paths = Yaml::parseFile(base_path('openapi/paths/acad.yaml'))['paths'];
    $documented = [];

    foreach ($paths as $uri => $item) {
        foreach (array_intersect(['get', 'post', 'patch', 'delete', 'put'], array_keys($item)) as $method) {
            $documented[] = strtoupper($method).' '.$uri;
        }
    }

    sort($documented);

    return $documented;
}

test('REQ-ACAD-001 api.md §10: los 27 endpoints están documentados en OpenAPI, enlazados desde openapi.yaml, y solo ellos', function (): void {
    $root = Yaml::parseFile(base_path('openapi.yaml'));
    $paths = Yaml::parseFile(base_path('openapi/paths/acad.yaml'))['paths'];

    foreach (array_keys($paths) as $uri) {
        expect($root['paths'])->toHaveKey($uri);
    }

    $registered = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'acad.')) {
            $registered[] = $route->methods()[0].' /'.$route->uri();
        }
    }

    sort($registered);
    expect(acadDocumentedOperations())->toHaveCount(27)->and($registered)->toBe(acadDocumentedOperations());
});

test('REQ-ACAD-001 api.md §10: toda referencia $ref de acad.yaml resuelve en components.yaml', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'));
    $document = Yaml::parseFile(base_path('openapi/paths/acad.yaml'));
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
});

test('REQ-ACAD-001 api.md §10: enumerados extensibles, lista blanca de sort, filtros en estilo form sin explode, name de solo lectura y name_translations sin propiedades adicionales', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'))['components'];
    $paths = Yaml::parseFile(base_path('openapi/paths/acad.yaml'))['paths'];

    expect($components['schemas']['EducationLevelStage']['x-extensible-enum'])->toBeTrue()
        ->and($components['schemas']['EducationLevelStage']['enum'])->toBe(['infantil', 'primaria', 'eso', 'bachillerato', 'fp', 'universidad'])
        ->and($components['schemas']['EducationLevelRegime']['enum'])->toBe(['publico', 'privado', 'concertado'])
        ->and($components['parameters']['AcadSortParam']['schema']['enum'])->toBe(['position', '-position', 'code', '-code'])
        ->and($components['schemas']['TranslationsMap']['additionalProperties'])->toBeFalse()
        ->and(array_keys($components['schemas']['TranslationsMap']['properties']))->toBe(['es-ES', 'en', 'de', 'fr'])
        ->and($components['schemas']['TranslationsPatchMap']['properties']['fr']['nullable'])->toBeTrue()
        ->and($components['schemas']['TranslationsMap']['properties']['fr'])->not->toHaveKey('nullable');

    foreach (['EducationLevel', 'Course', 'Subject'] as $schema) {
        expect($components['schemas'][$schema]['properties']['name']['readOnly'])->toBeTrue($schema);
    }

    foreach (['Group', 'Subgroup'] as $schema) {
        expect($components['schemas'][$schema]['properties']['name'])->not->toHaveKey('readOnly')
            ->and($components['schemas'][$schema]['properties'])->not->toHaveKey('name_translations');
    }

    $filters = collect($paths['/api/v1/education-levels']['get']['parameters'])->whereIn('name', ['stage', 'regime']);

    expect($filters)->toHaveCount(2);

    foreach ($filters as $filter) {
        expect($filter['style'])->toBe('form')->and($filter['explode'])->toBeFalse();
    }
});

test('REQ-ACAD-001 api.md §1: los esquemas de OpenAPI describen exactamente las claves que la API devuelve', function (): void {
    [$tenant, $admin, $year] = Api::setup('acad-openapi');
    $tree = Api::tree($tenant, $admin, $year);
    $schemas = Yaml::parseFile(base_path('openapi/components.yaml'))['components']['schemas'];

    foreach ([
        'EducationLevel' => "/education-levels/{$tree['level']}",
        'Course' => "/courses/{$tree['course']}",
        'Group' => "/groups/{$tree['group']}",
        'Subgroup' => "/subgroups/{$tree['subgroup']}",
        'Subject' => "/subjects/{$tree['subject']}",
    ] as $schema => $path) {
        resetSessionState();
        $body = test()->actingAs($admin)->getJson(Api::url($tenant, $path))->assertOk()->json();

        expect(array_keys($body))->toEqualCanonicalizing(array_keys($schemas[$schema]['properties']), $schema)
            ->and($schemas[$schema]['required'])->toEqualCanonicalizing(array_keys($body), $schema)
            ->and(array_keys($body))->not->toContain('id', 'tenant_id', 'created_by', 'updated_by', 'deleted_at');
    }
});
