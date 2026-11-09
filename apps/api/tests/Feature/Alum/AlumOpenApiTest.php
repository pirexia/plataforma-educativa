<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\AlumTestHelpers as Alum;

// CLAUDE.md §10 («el endpoint está documentado en OpenAPI»), REQ-ALUM/api.md §1, INV-006, INV-015, CA-ALUM-064. Los seis
// endpoints de 1.15-a documentados, y solo ellos; sus referencias resuelven; los esquemas describen lo que la API
// devuelve de verdad.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return list<string> `VERBO /ruta` */
function alumDocumentedOperations(): array
{
    $documented = [];

    foreach (Yaml::parseFile(base_path('openapi/paths/alum.yaml'))['paths'] as $uri => $item) {
        foreach (array_intersect(['get', 'post', 'patch', 'delete', 'put'], array_keys($item)) as $method) {
            $documented[] = strtoupper($method).' '.$uri;
        }
    }

    sort($documented);

    return $documented;
}

test('REQ-ALUM-001 api.md §1: los 6 endpoints de 1.15-a están documentados en OpenAPI, enlazados desde openapi.yaml, y solo ellos', function (): void {
    $root = Yaml::parseFile(base_path('openapi.yaml'));
    $paths = Yaml::parseFile(base_path('openapi/paths/alum.yaml'))['paths'];

    foreach (array_keys($paths) as $uri) {
        expect($root['paths'])->toHaveKey($uri)
            ->and($root['paths'][$uri]['$ref'])->toStartWith('openapi/paths/alum.yaml#/paths/');
    }

    $registered = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'alum.')) {
            $registered[] = $route->methods()[0].' /'.$route->uri();
        }
    }

    sort($registered);

    expect(alumDocumentedOperations())->toHaveCount(6)->and($registered)->toBe(alumDocumentedOperations())
        ->and($root['tags'])->toContain(['name' => 'Alum / Alumnado']);
});

test('REQ-ALUM-001 api.md §1: toda referencia $ref de alum.yaml resuelve en components.yaml y cada operación tiene identificador, etiqueta, cookie de sesión y las respuestas 401 y 403', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'));
    $document = Yaml::parseFile(base_path('openapi/paths/alum.yaml'));
    $refs = [];

    array_walk_recursive($document, static function ($value, $key) use (&$refs): void {
        if ($key === '$ref' && is_string($value)) {
            $refs[] = $value;
        }
    });

    expect(count($refs))->toBeGreaterThan(15);

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

            expect($operation['tags'][0])->toStartWith('Alum / ')
                ->and($operation['security'])->toBe([['SessionCookie' => []]])
                ->and($operation['responses'])->toHaveKey('401')
                ->and($operation['responses'])->toHaveKey('403');
        }
    }

    expect(count($ids))->toBe(count(array_unique($ids)));
});

test('REQ-ALUM-001 api.md §1 RN-ALUM-27: lista blanca de sort, Idempotency-Key obligatoria solo en POST /students y el documento nunca completo en el contrato', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'))['components'];
    $paths = Yaml::parseFile(base_path('openapi/paths/alum.yaml'))['paths'];

    expect($components['parameters']['StudentSortParam']['schema']['enum'])->toBe(['name', '-name', 'created_at', '-created_at'])
        ->and($components['parameters']['IdempotencyKeyHeader']['required'])->toBeTrue()
        ->and($paths['/api/v1/students']['post']['parameters'])->toContain(['$ref' => '../components.yaml#/components/parameters/IdempotencyKeyHeader'])
        ->and($components['schemas']['Student']['properties']['document_number']['description'])->toContain('enmascarado');

    foreach ($paths as $uri => $item) {
        foreach (array_intersect(['get', 'patch', 'delete'], array_keys($item)) as $method) {
            expect(json_encode($item[$method]['parameters'] ?? []))->not->toContain('IdempotencyKeyHeader', "{$method} {$uri}");
        }
    }

    expect(json_encode($paths['/api/v1/students/{publicId}/restore']['post']))->not->toContain('IdempotencyKeyHeader');
});

test('REQ-ALUM-001 api.md §1: los esquemas de OpenAPI describen exactamente las claves que la API devuelve (Student y StudentSummary, con y sin baja) y no filtran claves internas', function (): void {
    [$tenant, $admin] = Alum::setup('alum-openapi');
    $schemas = Yaml::parseFile(base_path('openapi/components.yaml'))['components']['schemas'];

    $student = Alum::student($tenant, $admin, ['document_type' => 'dni', 'document_number' => Alum::dni(77777777)]);
    $id = $student['public_id'];

    // `deleted_at` es el único campo condicional de ambos esquemas y de ahí que no sea obligatorio.
    $fichaProperties = array_values(array_diff(array_keys($schemas['Student']['properties']), ['deleted_at']));
    $summaryProperties = array_values(array_diff(array_keys($schemas['StudentSummary']['properties']), ['deleted_at']));
    $summary = Alum::call($tenant, $admin, 'get', '/students')->assertOk()->json('data.0');

    expect(array_keys($student))->toEqualCanonicalizing($fichaProperties)
        ->and($schemas['Student']['required'])->toEqualCanonicalizing($fichaProperties)
        ->and(array_keys(Alum::call($tenant, $admin, 'get', "/students/{$id}")->assertOk()->json()))->toEqualCanonicalizing($fichaProperties)
        ->and(array_keys($summary))->toEqualCanonicalizing($summaryProperties)
        ->and($schemas['StudentSummary']['required'])->toEqualCanonicalizing($summaryProperties)
        ->and(array_keys($student['person']))->toBe(['public_id']);

    // Con la baja aparece `deleted_at`, documentado.
    Alum::call($tenant, $admin, 'delete', "/students/{$id}")->assertNoContent();
    $withDeleted = Alum::call($tenant, $admin, 'get', '/students?include_deleted=true')->assertOk()->json('data.0');

    expect(array_keys($withDeleted))->toEqualCanonicalizing(array_keys($schemas['StudentSummary']['properties']));

    // Ninguna clave interna ni el documento completo salen por la API.
    foreach ([array_keys($student), array_keys($summary)] as $names) {
        expect($names)->not->toContain('id', 'tenant_id', 'person_id', 'created_by', 'updated_by', 'email', 'phone');
    }

    expect(json_encode($student))->not->toContain(Alum::dni(77777777));
});
