<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

// CLAUDE.md §10 («el endpoint está documentado en OpenAPI»), REQ-CURSO/api.md
// §8, INV-006, INV-015. Los seis endpoints de api.md §2, el selector (1.11-a) y la reapertura (1.11c), con el esquema y el
// tipo de error nuevo, y ningún endpoint documentado que no exista (ni al
// revés).

test('REQ-CURSO-001 api.md §8: los ocho endpoints de cursos están documentados en OpenAPI, y solo ellos', function (): void {
    $root = Yaml::parseFile(base_path('openapi.yaml'));
    $paths = Yaml::parseFile(base_path('openapi/paths/curso.yaml'))['paths'];

    $documented = [];

    foreach ($paths as $uri => $item) {
        // Los de la apertura del curso siguiente (1.12b) los cubre `RolloverOpenApiTest`.
        if (str_starts_with($uri, '/api/v1/academic-year-rollovers')) {
            continue;
        }

        foreach (array_intersect(['get', 'post', 'patch', 'delete', 'put'], array_keys($item)) as $method) {
            $documented[] = strtoupper($method).' '.$uri;
        }

        expect($root['paths'])->toHaveKey($uri);
    }

    sort($documented);

    $registered = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/academic-years')) {
            $registered[] = $route->methods()[0].' /'.$route->uri();
        }
    }

    sort($registered);

    expect($documented)->toBe([
        'GET /api/v1/academic-years',
        'GET /api/v1/academic-years/current',
        'GET /api/v1/academic-years/selectable',
        'GET /api/v1/academic-years/{publicId}',
        'PATCH /api/v1/academic-years/{publicId}',
        'POST /api/v1/academic-years',
        'POST /api/v1/academic-years/{publicId}/reopen',
        'POST /api/v1/academic-years/{publicId}/status',
    ])->and($registered)->toBe($documented);
});

test('REQ-CURSO-001 api.md §8: el esquema AcademicYear, el enumerado extensible, la lista blanca de sort y el tipo academic-year-closed están en el contrato', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'))['components'];
    $paths = Yaml::parseFile(base_path('openapi/paths/curso.yaml'))['paths'];

    expect($components['schemas']['AcademicYear']['properties']['status']['enum'])->toBe(['planificacion', 'activo', 'cerrado', 'archivado'])
        ->and($components['schemas']['AcademicYear']['properties']['status']['x-extensible-enum'])->toBeTrue()
        ->and($components['responses']['AcademicYearClosed']['content']['application/problem+json']['example']['type'])->toBe('urn:pge:error:academic-year-closed');

    $list = $paths['/api/v1/academic-years']['get']['parameters'];
    $status = collect($list)->firstWhere('name', 'status');
    $sort = collect($list)->firstWhere('name', 'sort');

    expect($status['style'])->toBe('form')->and($status['explode'])->toBeFalse()
        ->and($sort['schema']['enum'])->toBe(['starts_on', '-starts_on', 'code', '-code'])
        ->and($sort['schema']['default'])->toBe('-starts_on');
});

test('CA-CURSO-100 api.md §2: la reapertura documenta el cuerpo con reason obligatorio y los 401, 403, 404, 409 y 422', function (): void {
    $paths = Yaml::parseFile(base_path('openapi/paths/curso.yaml'))['paths'];
    $operation = $paths['/api/v1/academic-years/{publicId}/reopen']['post'];

    expect($operation['operationId'])->toBe('reopenAcademicYear')
        ->and($operation['requestBody']['required'])->toBeTrue()
        ->and($operation['requestBody']['content']['application/json']['schema']['required'])->toBe(['reason'])
        ->and(array_map('strval', array_keys($operation['responses'])))->toBe(['200', '401', '403', '404', '409', '422']);
});
