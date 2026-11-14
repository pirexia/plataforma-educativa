<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\PrlTestHelpers as Prl;

// CLAUDE.md §10 («el endpoint está documentado en OpenAPI»), REQ-PRL/api.md §8.4, INV-006, INV-015. Los 9 endpoints de 1.14b-b
// documentados, y solo ellos; sus referencias resuelven; los esquemas describen lo que la API devuelve de verdad.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return list<string> `VERBO /ruta` */
function prlDocumentedOperations(): array
{
    $documented = [];

    foreach (Yaml::parseFile(base_path('openapi/paths/prl.yaml'))['paths'] as $uri => $item) {
        foreach (array_intersect(['get', 'post', 'patch', 'delete', 'put'], array_keys($item)) as $method) {
            $documented[] = strtoupper($method).' '.$uri;
        }
    }

    sort($documented);

    return $documented;
}

test('REQ-PRL-004 api.md §8.4: los 9 endpoints de la puerta están documentados en OpenAPI, enlazados desde openapi.yaml, y solo ellos', function (): void {
    $root = Yaml::parseFile(base_path('openapi.yaml'));

    foreach (array_keys(Yaml::parseFile(base_path('openapi/paths/prl.yaml'))['paths']) as $uri) {
        expect($root['paths'])->toHaveKey($uri)
            ->and($root['paths'][$uri]['$ref'])->toStartWith('openapi/paths/prl.yaml#/paths/');
    }

    $registered = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'prl.')) {
            $registered[] = $route->methods()[0].' /'.$route->uri();
        }
    }

    sort($registered);

    expect(prlDocumentedOperations())->toHaveCount(9)->and($registered)->toBe(prlDocumentedOperations());
});

test('REQ-PRL-004 api.md §8.4: toda referencia $ref de prl.yaml resuelve en components.yaml y cada operación tiene identificador, etiqueta y cookie de sesión', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'));
    $document = Yaml::parseFile(base_path('openapi/paths/prl.yaml'));
    $refs = [];

    array_walk_recursive($document, static function ($value, $key) use (&$refs): void {
        if ($key === '$ref' && is_string($value)) {
            $refs[] = $value;
        }
    });

    expect(count($refs))->toBeGreaterThan(20);

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

            expect($operation['tags'][0])->toStartWith('Prl / ')
                ->and($operation['security'])->toBe([['SessionCookie' => []]])
                ->and($operation['responses'])->toHaveKey('401')
                ->and($operation['responses'])->toHaveKey('403');
        }
    }

    expect(count($ids))->toBe(count(array_unique($ids)));
});

test('REQ-PRL-004 ADR-038 §7.3: enumerados extensibles, y los esquemas describen exactamente las claves que la API devuelve (StudentMovement, PickupAlert abierta y cerrada, DoorStudent y DoorPickupList)', function (): void {
    $schemas = Yaml::parseFile(base_path('openapi/components.yaml'))['components']['schemas'];

    expect($schemas['MovementDirection']['enum'])->toBe(['entrada', 'salida'])
        ->and($schemas['MovementVerificationMethod']['enum'])->toBe(['foto', 'documento', 'foto_y_documento'])
        ->and($schemas['MovementOffScheduleReason']['enum'])->toBe(['medico', 'familiar', 'actividad', 'retraso', 'otro'])
        ->and($schemas['PickupAlertKind']['enum'])->toBe(['persona_no_autorizada'])
        ->and($schemas['PickupAlertStatus']['enum'])->toBe(['abierta', 'cerrada'])
        ->and($schemas['PickupAlertResolution']['enum'])->toBe(['no_entregado', 'recogido_por_autorizado', 'autorizacion_puntual_registrada', 'otro']);

    foreach (['MovementDirection', 'MovementVerificationMethod', 'MovementOffScheduleReason', 'PickupAlertKind', 'PickupAlertStatus', 'PickupAlertResolution'] as $enum) {
        expect($schemas[$enum]['x-extensible-enum'])->toBeTrue($enum);
    }

    $f = Prl::doorFamily('prl-openapi');
    $keys = fn ($response) => array_keys($response->json());

    $movement = Prl::exit($f)->assertCreated();
    expect($keys($movement))->toEqualCanonicalizing(array_keys($schemas['StudentMovement']['properties']))
        ->and($schemas['StudentMovement']['required'])->toEqualCanonicalizing(array_keys($schemas['StudentMovement']['properties']));

    // La entrada fuera de horario (campos nulos) también lleva todas las claves.
    $entry = Prl::call($f['tenant'], $f['conserje'], 'post', '/student-movements', ['student_person' => $f['student']->public_id, 'direction' => 'entrada'])->assertCreated();
    expect($keys($entry))->toEqualCanonicalizing(array_keys($schemas['StudentMovement']['properties']));

    // Alerta abierta: con `protocol` y `contacts`; cerrada: sin ellos.
    $alert = Prl::call($f['tenant'], $f['conserje'], 'post', '/pickup-alerts', ['student_person' => $f['student']->public_id, 'presenter_description' => 'x'])->assertCreated();
    expect($keys($alert))->toEqualCanonicalizing(array_keys($schemas['PickupAlert']['properties']))
        ->and(array_keys($alert->json('contacts')))->toEqualCanonicalizing($schemas['PickupAlert']['properties']['contacts']['required']);

    $closed = Prl::call($f['tenant'], $f['direccion'], 'patch', '/pickup-alerts/'.$alert->json('public_id'), ['resolution' => 'otro'])->assertOk();
    expect($keys($closed))->toEqualCanonicalizing(array_values(array_diff(array_keys($schemas['PickupAlert']['properties']), ['protocol', 'contacts'])))
        ->and($schemas['PickupAlert']['required'])->toEqualCanonicalizing(array_values(array_diff(array_keys($schemas['PickupAlert']['properties']), ['protocol', 'contacts'])));

    $student = Prl::call($f['tenant'], $f['conserje'], 'get', '/door/students?q=Al')->assertOk()->json('data.0');
    $list = Prl::call($f['tenant'], $f['conserje'], 'get', "/door/students/{$f['student']->public_id}/pickup-list")->assertOk();

    expect(array_keys($student))->toEqualCanonicalizing(array_keys($schemas['DoorStudent']['properties']))
        ->and(array_keys($list->json()))->toEqualCanonicalizing(array_keys($schemas['DoorPickupList']['properties']))
        ->and(array_keys($list->json('meta')))->toEqualCanonicalizing(array_keys($schemas['DoorPickupList']['properties']['meta']['properties']))
        ->and(array_keys($list->json('data.0')))->toEqualCanonicalizing(array_keys($schemas['PickupListEntry']['properties']));
});
