<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\AlumEnrollmentHelpers;
use Tests\Support\AlumTestHelpers as Alum;

// CLAUDE.md §10 («el endpoint está documentado en OpenAPI»), REQ-ALUM/api.md §1 y §2, INV-006, INV-015, CA-ALUM-064. Los
// seis endpoints de 1.15-a, los siete de matrícula y exportación de 1.15-b y los seis de solicitudes de cambio de grupo de 1.15-c documentados, y solo ellos; sus referencias resuelven; los esquemas describen lo que la API
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

test('REQ-ALUM-002 api.md §1 y §2: los 22 endpoints de 1.15-a, 1.15-b (T2b y T2c) y 1.15-c (T2a, solicitudes de cambio de grupo; T2b, fotografía) están documentados en OpenAPI, enlazados desde openapi.yaml, y solo ellos', function (): void {
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

    expect(alumDocumentedOperations())->toHaveCount(22)->and($registered)->toBe(alumDocumentedOperations())
        ->and($root['tags'])->toContain(['name' => 'Alum / Alumnado'])->toContain(['name' => 'Alum / Matrícula']);
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
        foreach (array_intersect(['get', 'post', 'patch', 'delete', 'put'], array_keys($item)) as $method) {
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
        ->and($components['parameters']['EnrollmentSortParam']['schema']['enum'])->toBe(['name', '-name', 'enrolled_on', '-enrolled_on'])
        ->and($components['parameters']['IdempotencyKeyHeader']['required'])->toBeTrue()
        ->and($paths['/api/v1/students']['post']['parameters'])->toContain(['$ref' => '../components.yaml#/components/parameters/IdempotencyKeyHeader'])
        ->and($components['schemas']['Student']['properties']['document_number']['description'])->toContain('enmascarado');

    foreach ($paths as $uri => $item) {
        foreach (array_intersect(['get', 'patch', 'delete'], array_keys($item)) as $method) {
            expect(json_encode($item[$method]['parameters'] ?? []))->not->toContain('IdempotencyKeyHeader', "{$method} {$uri}");
        }
    }

    // Sin `Idempotency-Key` en matrícula (RN-ALUM-27): ni el alta, ni la baja, ni el resto.
    foreach (['/api/v1/enrollments', '/api/v1/enrollments/{publicId}/withdrawal', '/api/v1/students/{publicId}/restore'] as $uri) {
        expect(json_encode($paths[$uri]['post']))->not->toContain('IdempotencyKeyHeader', $uri);
    }
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

test('REQ-ALUM-002 api.md §2: el esquema Enrollment describe exactamente las claves que la API devuelve (listado, detalle, alta y elemento de enrollments[] de la ficha) y los resúmenes anidados', function (): void {
    $s = AlumEnrollmentHelpers::scenario('alum-openapi-enr');
    $schemas = Yaml::parseFile(base_path('openapi/components.yaml'))['components']['schemas'];
    $properties = array_keys($schemas['Enrollment']['properties']);
    $created = Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', AlumEnrollmentHelpers::body($s))->assertCreated()->json();
    $id = $created['public_id'];
    $listed = Alum::call($s['tenant'], $s['admin'], 'get', "/enrollments?academic_year={$s['year']->public_id}")->assertOk()->json('data.0');
    $ficha = Alum::call($s['tenant'], $s['admin'], 'get', "/students/{$s['students'][0]['public_id']}")->assertOk()->json();

    expect($schemas['Enrollment']['required'])->toEqualCanonicalizing($properties)
        ->and(array_keys($created))->toEqualCanonicalizing($properties)
        ->and(array_keys($listed))->toEqualCanonicalizing($properties)
        ->and(array_keys(Alum::call($s['tenant'], $s['admin'], 'get', "/enrollments/{$id}")->assertOk()->json()))->toEqualCanonicalizing($properties)
        ->and(array_keys($ficha['enrollments'][0]))->toEqualCanonicalizing($properties)
        ->and($schemas['Student']['properties']['enrollments']['items'])->toBe(['$ref' => '#/components/schemas/Enrollment'])
        ->and(array_keys($created['student']))->toEqualCanonicalizing($schemas['Enrollment']['properties']['student']['required'])
        ->and(array_keys($created['academic_year']))->toEqualCanonicalizing($schemas['Enrollment']['properties']['academic_year']['required'])
        ->and(array_keys($created['course']))->toEqualCanonicalizing($schemas['Enrollment']['properties']['course']['required'])
        ->and(array_keys($created['group']))->toEqualCanonicalizing($schemas['Enrollment']['properties']['group']['oneOf'][0]['required'])
        ->and($schemas['EnrollmentStatus']['enum'])->toBe(['activa', 'baja'])
        ->and(array_keys($created))->not->toContain('id', 'tenant_id', 'student_id', 'course_id', 'group_id', 'academic_year_id', 'created_by');
});

test('REQ-ALUM-003 api.md §3: el esquema GroupChangeRequest describe exactamente las claves que la API devuelve (alta, listado, detalle y transiciones) y los resúmenes anidados; aprobar y cancelar no exigen cuerpo, rechazar sí', function (): void {
    $s = AlumEnrollmentHelpers::scenario('alum-openapi-gcr');
    $components = Yaml::parseFile(base_path('openapi/components.yaml'))['components'];
    $schemas = $components['schemas'];
    $paths = Yaml::parseFile(base_path('openapi/paths/alum.yaml'))['paths'];
    $enrollment = Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', AlumEnrollmentHelpers::body($s))->assertCreated()->json('public_id');
    $created = Alum::call($s['tenant'], $s['admin'], 'post', '/group-change-requests', ['enrollment' => $enrollment, 'to_group' => $s['groupB'], 'reason' => 'Motivo ficticio'])->assertCreated()->json();
    $id = $created['public_id'];
    $properties = array_keys($schemas['GroupChangeRequest']['properties']);
    $listed = Alum::call($s['tenant'], $s['admin'], 'get', "/group-change-requests?academic_year={$s['year']->public_id}")->assertOk()->json('data.0');
    $cancelled = Alum::call($s['tenant'], $s['admin'], 'post', "/group-change-requests/{$id}/cancellation")->assertOk()->json();

    expect($schemas['GroupChangeRequest']['required'])->toEqualCanonicalizing($properties)
        ->and(array_keys($created))->toEqualCanonicalizing($properties)
        ->and(array_keys($listed))->toEqualCanonicalizing($properties)
        ->and(array_keys(Alum::call($s['tenant'], $s['admin'], 'get', "/group-change-requests/{$id}")->assertOk()->json()))->toEqualCanonicalizing($properties)
        ->and(array_keys($cancelled))->toEqualCanonicalizing($properties)
        ->and(array_keys($created['enrollment']))->toEqualCanonicalizing($schemas['GroupChangeRequest']['properties']['enrollment']['required'])
        ->and(array_keys($created['enrollment']['student']))->toEqualCanonicalizing($schemas['GroupChangeRequest']['properties']['enrollment']['properties']['student']['required'])
        ->and(array_keys($created['academic_year']))->toEqualCanonicalizing($schemas['GroupChangeRequest']['properties']['academic_year']['required'])
        ->and(array_keys($created['from_group']))->toEqualCanonicalizing($schemas['GroupChangeRequestGroup']['required'])
        ->and(array_keys($created['requested_by']))->toEqualCanonicalizing($schemas['GroupChangeRequestUser']['oneOf'][0]['required'])
        ->and($schemas['GroupChangeRequestStatus']['enum'])->toBe(['pendiente', 'aprobada', 'rechazada', 'cancelada'])
        ->and($paths['/api/v1/group-change-requests/{publicId}/approval']['post']['requestBody']['required'])->toBeFalse()
        ->and($paths['/api/v1/group-change-requests/{publicId}/cancellation']['post']['requestBody']['required'])->toBeFalse()
        ->and($paths['/api/v1/group-change-requests/{publicId}/rejection']['post']['requestBody']['required'])->toBeTrue()
        ->and(array_keys($created))->not->toContain('id', 'tenant_id', 'enrollment_id', 'from_group_id', 'to_group_id', 'requested_by_id', 'created_by');

    // Sin `Idempotency-Key` en ninguna (RN-ALUM-27, api.md §3).
    foreach (array_keys($paths) as $uri) {
        if (str_starts_with($uri, '/api/v1/group-change-requests')) {
            expect(json_encode($paths[$uri]))->not->toContain('IdempotencyKeyHeader', $uri);
        }
    }
});
