<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

// CLAUDE.md §10 («el endpoint está documentado en OpenAPI»), REQ-CURSO/api.md §8, INV-006, INV-015 (1.12b,
// REQ-CURSO-002): los siete endpoints de api.md §2.1, sus esquemas, el campo `copy_teaching_assignments` y el 409
// `rollover_in_progress` de la transición de estado; y ningún endpoint documentado que no exista (ni al revés).

test('REQ-CURSO-002 api.md §8: los siete endpoints de la apertura del curso siguiente están documentados en OpenAPI y solo ellos', function (): void {
    $root = Yaml::parseFile(base_path('openapi.yaml'));
    $paths = Yaml::parseFile(base_path('openapi/paths/curso.yaml'))['paths'];

    $documented = [];

    foreach ($paths as $uri => $item) {
        if (! str_starts_with($uri, '/api/v1/academic-year-rollovers')) {
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
        if (str_starts_with($route->uri(), 'api/v1/academic-year-rollovers')) {
            $registered[] = $route->methods()[0].' /'.$route->uri();
        }
    }

    sort($registered);

    expect($documented)->toBe([
        'GET /api/v1/academic-year-rollovers',
        'GET /api/v1/academic-year-rollovers/elements',
        'GET /api/v1/academic-year-rollovers/preview',
        'GET /api/v1/academic-year-rollovers/{publicId}',
        'POST /api/v1/academic-year-rollovers',
        'POST /api/v1/academic-year-rollovers/{publicId}/cancel',
        'POST /api/v1/academic-year-rollovers/{publicId}/revert',
    ])->and($registered)->toBe($documented);
});

test('REQ-CURSO-002 api.md §8: los esquemas, el enumerado extensible de status, la respuesta 202 con Location y copy_teaching_assignments están en el contrato', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'))['components']['schemas'];
    $paths = Yaml::parseFile(base_path('openapi/paths/curso.yaml'))['paths'];

    foreach (['AcademicYearRolloverElement', 'AcademicYearRolloverPreview', 'AcademicYearRollover'] as $schema) {
        expect($components)->toHaveKey($schema);
    }

    expect($components['AcademicYearRollover']['properties']['status']['enum'])->toBe(['pendiente', 'en_curso', 'completado', 'fallido', 'revirtiendo', 'revertido'])
        ->and($components['AcademicYearRollover']['properties']['status']['x-extensible-enum'])->toBeTrue()
        ->and($components['AcademicYearRollover']['properties'])->toHaveKey('copy_teaching_assignments')
        ->and($components['AcademicYearRolloverPreview']['properties'])->toHaveKey('copy_teaching_assignments')
        ->and($components['AcademicYearRolloverElement']['properties']['bundle']['enum'])->toBe(['teaching_assignments'])
        ->and($components['AcademicYearRollover']['required'])->toContain('copy_teaching_assignments', 'revertible', 'cancellable_at');

    $post = $paths['/api/v1/academic-year-rollovers']['post'];
    expect($post['responses'])->toHaveKey('202')
        ->and($post['responses']['202']['headers'])->toHaveKey('Location')
        ->and($post['requestBody']['content']['application/json']['schema']['properties'])->toHaveKey('copy_teaching_assignments')
        ->and($paths['/api/v1/academic-year-rollovers/preview']['get']['parameters'])->toContain(collect($paths['/api/v1/academic-year-rollovers/preview']['get']['parameters'])->firstWhere('name', 'copy_teaching_assignments'))
        ->and($paths['/api/v1/academic-year-rollovers/{publicId}/revert']['post']['responses'])->toHaveKey('202')
        ->and($paths['/api/v1/academic-year-rollovers/{publicId}/cancel']['post']['responses'])->toHaveKey('409')
        ->and($paths['/api/v1/academic-years/{publicId}/status']['post']['responses']['409']['description'])->toContain('rollover_in_progress');
});
