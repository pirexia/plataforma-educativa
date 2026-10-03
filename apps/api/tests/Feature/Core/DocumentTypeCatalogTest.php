<?php

use App\Models\Person;
use App\Models\User;
use App\Modules\Core\Domain\DocumentType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

// REQ-CORE-003, funcional.md §14.6.4 (RN-CORE-90 a 93, CA-CORE-273 a 285),
// issues #292 (catálogo), #308 (F1-F3), #309 y #310 (F4-F7).

beforeEach(function (): void {
    Mail::fake();
});

afterEach(function (): void {
    // `people` no cae con el tenant: se purga lo que sembró cada prueba para
    // no dejar filas huérfanas que una migración de datos real vería.
    DB::connection('pgsql_platform')->table('people')->whereIn('id', dtcTrackedPeople())->delete();
    dtcTrackedPeople(reset: true);
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/**
 * @param  array<string, mixed>  $person
 */
function dtcPost(object $tenant, User $admin, string $email, array $person): TestResponse
{
    return test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
        'email' => $email,
        'person' => ['given_name' => 'Prueba', 'family_name_1' => 'Sintetica', ...$person],
        'send_invitation' => false,
    ]);
}

function dtcError(TestResponse $response, string $field): ?string
{
    return $response->json('errors')[$field][0]['code'] ?? null;
}

function dtcPersonOf(object $tenant, string $publicId): Person
{
    return app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => User::query()->where('public_id', $publicId)->firstOrFail()->person->fresh(),
    );
}

// CA-CORE-273
test('CA-CORE-273 (RN-CORE-90, INV-010, #308): un tipo fuera del catálogo da 422 en el alta y en la edición, sin crear ni modificar nada', function (): void {
    [$tenant, $admin] = provisionCoreTenant('dtc-273');
    config(['core.documents.validate_check_digit' => false]);

    $response = dtcPost($tenant, $admin, 'carnet@example.com', ['document_type' => 'carnet', 'document_number' => 'ABC123'])
        ->assertStatus(422);

    expect(dtcError($response, 'person.document_type'))->toBe('core.validation.document_type_invalid')
        // S3: el valor recibido no se refleja ni en `params` ni en el mensaje.
        ->and($response->json('errors')['person.document_type'][0]['params'])->toBe([])
        ->and($response->json('errors')['person.document_type'][0]['message'])->not->toContain('carnet');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(User::query()->where('email', 'carnet@example.com')->exists())->toBeFalse();
    });

    $created = dtcPost($tenant, $admin, 'ok-273@example.com', ['document_type' => 'dni', 'document_number' => '12345678Z'])
        ->assertCreated();

    $patch = test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/users/'.$created->json('public_id')), ['person' => ['document_type' => 'carnet']])
        ->assertStatus(422);

    expect(dtcError($patch, 'person.document_type'))->toBe('core.validation.document_type_invalid');

    $person = dtcPersonOf($tenant, $created->json('public_id'));
    expect($person->document_type)->toBe('dni')->and($person->document_number)->toBe('12345678Z');
});

// CA-CORE-273 / F1: la API exige el código exacto (OPEN-CORE-50 = A).
test('CA-CORE-273 (F1, #308): la API exige el código exacto en minúsculas: DNI o Dni son 422', function (): void {
    [$tenant, $admin] = provisionCoreTenant('dtc-273b');
    config(['core.documents.validate_check_digit' => false]);

    foreach (['DNI', 'Dni', 'otro'] as $type) {
        $response = dtcPost($tenant, $admin, Str::slug($type).'@example.com', ['document_type' => $type, 'document_number' => '12345678Z'])
            ->assertStatus(422);

        expect(dtcError($response, 'person.document_type'))->toBe('core.validation.document_type_invalid');
    }
});

// CA-CORE-274
test('CA-CORE-274 (RN-CORE-90, OPEN-CORE-06, REQ-SEED-005, F6, #309): cada código acepta su formato válido y rechaza el inválido; el formato se exige con o sin dígito de control', function (): void {
    [$tenant, $admin] = provisionCoreTenant('dtc-274');

    config(['core.documents.validate_check_digit' => true]);

    $cases = [
        'dni' => ['00000000T', '1234'],
        'nie' => ['X0000000T', '00000000T'],
        'pasaporte' => ['AB1234567', 'AB 1234567'],
    ];

    foreach ($cases as $type => [$valid, $invalid]) {
        dtcPost($tenant, $admin, "ok-{$type}@example.com", ['document_type' => $type, 'document_number' => $valid])
            ->assertCreated();

        $response = dtcPost($tenant, $admin, "ko-{$type}@example.com", ['document_type' => $type, 'document_number' => $invalid])
            ->assertStatus(422);

        expect(dtcError($response, 'person.document_number'))->toBe('core.validation.document_number_invalid');
    }

    // dni y nie con formato válido y letra de control incorrecta.
    foreach (['dni' => '00000000X', 'nie' => 'X0000000Z'] as $type => $badLetter) {
        config(['core.documents.validate_check_digit' => true]);
        dtcPost($tenant, $admin, "letra-{$type}-on@example.com", ['document_type' => $type, 'document_number' => $badLetter])
            ->assertStatus(422);

        config(['core.documents.validate_check_digit' => false]);
        dtcPost($tenant, $admin, "letra-{$type}-off@example.com", ['document_type' => $type, 'document_number' => $badLetter])
            ->assertCreated();

        // El formato se exige en los dos casos.
        $bad = dtcPost($tenant, $admin, "formato-{$type}-off@example.com", ['document_type' => $type, 'document_number' => 'formato-invalido'])
            ->assertStatus(422);
        expect(dtcError($bad, 'person.document_number'))->toBe('core.validation.document_number_invalid');
    }
});

// CA-CORE-275
test('CA-CORE-275 (RN-CORE-92, RN-CORE-03, F1, F5, #308, #310): el mismo documento con otra grafía es duplicado y se guarda el valor canónico', function (): void {
    [$tenant, $admin] = provisionCoreTenant('dtc-275');
    config(['core.documents.validate_check_digit' => false]);

    $first = dtcPost($tenant, $admin, 'primera@example.com', ['document_type' => 'dni', 'document_number' => '12345678Z'])
        ->assertCreated();

    $second = dtcPost($tenant, $admin, 'segunda@example.com', ['document_type' => 'dni', 'document_number' => ' 12345678-z '])
        ->assertStatus(422);

    expect(dtcError($second, 'person.document_number'))->toBe('core.validation.document_duplicate');

    // F5: en minúsculas, con la comprobación desactivada, se guarda normalizado.
    $lower = dtcPost($tenant, $admin, 'tercera@example.com', ['document_type' => 'nie', 'document_number' => 'x0000000t'])
        ->assertCreated();

    $person = dtcPersonOf($tenant, $first->json('public_id'));
    expect($person->document_type)->toBe('dni')->and($person->document_number)->toBe('12345678Z')
        ->and(dtcPersonOf($tenant, $lower->json('public_id'))->document_number)->toBe('X0000000T');

    // La edición excluye a la propia persona de la unicidad y normaliza.
    test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/users/'.$first->json('public_id')), ['person' => ['document_number' => '12345678-z']])
        ->assertOk();

    $other = dtcPost($tenant, $admin, 'cuarta@example.com', ['document_type' => 'pasaporte', 'document_number' => 'AB1234567'])
        ->assertCreated();

    $clash = test()->actingAs($admin)
        ->patchJson(coreApiUrl($tenant->slug, '/users/'.$other->json('public_id')), ['person' => ['document_type' => 'dni', 'document_number' => '12345678 z']])
        ->assertStatus(422);

    expect(dtcError($clash, 'person.document_number'))->toBe('core.validation.document_duplicate');
});

// CA-CORE-276
test('CA-CORE-276 (RN-CORE-91, F3, #308): tipo sin número o número sin tipo da 422 document_incomplete; sin ninguno, 201', function (): void {
    [$tenant, $admin] = provisionCoreTenant('dtc-276');
    config(['core.documents.validate_check_digit' => false]);

    $onlyNumber = dtcPost($tenant, $admin, 'solo-numero@example.com', ['document_number' => '12345678Z'])->assertStatus(422);
    $onlyType = dtcPost($tenant, $admin, 'solo-tipo@example.com', ['document_type' => 'dni'])->assertStatus(422);

    expect(dtcError($onlyNumber, 'person.document_type'))->toBe('core.validation.document_incomplete')
        ->and(dtcError($onlyType, 'person.document_number'))->toBe('core.validation.document_incomplete');

    dtcPost($tenant, $admin, 'ninguno@example.com', [])->assertCreated();
    dtcPost($tenant, $admin, 'ambos-null@example.com', ['document_type' => null, 'document_number' => null])->assertCreated();
});

// CA-CORE-277
test('CA-CORE-277 (RN-CORE-93, F2, #308): un PATCH que cambia solo el tipo revalida el par resultante y no modifica a la persona', function (): void {
    [$tenant, $admin] = provisionCoreTenant('dtc-277');
    config(['core.documents.validate_check_digit' => false]);

    $created = dtcPost($tenant, $admin, 'pasaporte@example.com', ['document_type' => 'pasaporte', 'document_number' => 'AB1234567'])
        ->assertCreated();
    $url = coreApiUrl($tenant->slug, '/users/'.$created->json('public_id'));

    $response = test()->actingAs($admin)
        ->patchJson($url, ['person' => ['document_type' => 'dni']])
        ->assertStatus(422);

    expect(dtcError($response, 'person.document_number'))->toBe('core.validation.document_number_invalid');

    $person = dtcPersonOf($tenant, $created->json('public_id'));
    expect($person->document_type)->toBe('pasaporte')->and($person->document_number)->toBe('AB1234567');

    // Vaciar el par se hace enviando los dos a null; uno solo es incompleto.
    $half = test()->actingAs($admin)->patchJson($url, ['person' => ['document_number' => null]])->assertStatus(422);
    expect(dtcError($half, 'person.document_number'))->toBe('core.validation.document_incomplete');

    test()->actingAs($admin)
        ->patchJson($url, ['person' => ['document_type' => null, 'document_number' => null]])
        ->assertOk();

    expect(dtcPersonOf($tenant, $created->json('public_id'))->document_type)->toBeNull();

    // Un PATCH que no toca el documento no lo revalida.
    test()->actingAs($admin)->patchJson($url, ['person' => ['given_name' => 'Otra']])->assertOk();
});

// CA-CORE-279
test('CA-CORE-279 (RN-CORE-90, INV-006): el enum de OpenAPI de document_type es exactamente el del enumerado PHP, en el mismo orden', function (): void {
    $components = Yaml::parseFile(base_path('openapi/components.yaml'))['components']['schemas'];
    $paths = Yaml::parseFile(base_path('openapi/paths/core.yaml'))['paths'];

    expect($components['DocumentType']['enum'])->toBe(DocumentType::values())
        ->and($components['Person']['properties']['document_type']['allOf'][0]['$ref'])->toBe('#/components/schemas/DocumentType');

    $post = $paths['/api/v1/users']['post']['requestBody']['content']['application/json']['schema']['properties']['person']['properties']['document_type'];
    $patch = $paths['/api/v1/users/{publicId}']['patch']['requestBody']['content']['application/json']['schema']['properties']['person']['properties']['document_type'];

    expect($post['$ref'])->toBe('../components.yaml#/components/schemas/DocumentType')
        ->and($patch['allOf'][0]['$ref'])->toBe('../components.yaml#/components/schemas/DocumentType');
});

// CA-CORE-280
test('CA-CORE-280 (RN-CORE-90, §14.6.4.4): la migración normaliza DNI, dni y " Nie " a su código y número canónicos', function (): void {
    [$tenant] = provisionCoreTenant('dtc-280');

    $tenantIds = [$tenant->id];
    $ids = dtcInsertPeople($tenant->id, [
        ['DNI', ' 12345678-z '],
        ['dni', '87654321x'],
        [' Nie ', 'x-0000000-t'],
        ['PASAPORTE', ' ab1234567 '],
        [null, null],
    ]);

    dtcMigration()->normalize(DB::connection('pgsql_platform'), $tenantIds);

    $rows = DB::connection('pgsql_platform')->table('people')->whereIn('id', $ids)->orderBy('id')->get(['document_type', 'document_number']);

    expect($rows->map(fn ($row) => [$row->document_type, $row->document_number])->all())->toBe([
        ['dni', '12345678Z'],
        ['dni', '87654321X'],
        ['nie', 'X0000000T'],
        ['pasaporte', 'AB1234567'],
        [null, null],
    ]);
});

test('CA-CORE-280 (§14.6.4.4): un tipo sin correspondencia o un duplicado creado por la normalización abortan enumerando los public_id y sin tocar nada', function (): void {
    [$tenant] = provisionCoreTenant('dtc-280b');

    $tenantIds = [$tenant->id];
    $ids = dtcInsertPeople($tenant->id, [
        ['DNI', '12345678-Z'],
        ['dni', '12345678Z'],       // duplicado tras normalizar con la anterior
        ['carnet', 'ZZ999'],        // sin correspondencia
        ['DNI', '11111111h'],       // normalizable, pero no debe tocarse al abortar
    ]);

    $publicIds = DB::connection('pgsql_platform')->table('people')->whereIn('id', $ids)->orderBy('id')->pluck('public_id')->all();

    try {
        dtcMigration()->normalize(DB::connection('pgsql_platform'), $tenantIds);
        $message = null;
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->not->toBeNull()
        ->and($message)->toContain($publicIds[0])->toContain($publicIds[1])->toContain($publicIds[2])
        ->and($message)->not->toContain($publicIds[3]);

    $stored = DB::connection('pgsql_platform')->table('people')->whereIn('id', $ids)->orderBy('id')->get(['document_type', 'document_number']);

    expect($stored->map(fn ($row) => [$row->document_type, $row->document_number])->all())->toBe([
        ['DNI', '12345678-Z'],
        ['dni', '12345678Z'],
        ['carnet', 'ZZ999'],
        ['DNI', '11111111h'],
    ]);
});

test('CA-CORE-280 (§14.6.4.4): una persona dada de baja no cuenta como duplicado, el mismo número en otro centro tampoco', function (): void {
    [$tenant] = provisionCoreTenant('dtc-280c');
    [$other] = provisionCoreTenant('dtc-280d');

    $tenantIds = [$tenant->id, $other->id];
    $ids = dtcInsertPeople($tenant->id, [['DNI', '12345678Z'], ['dni', '12345678-Z']], deleteSecond: true);
    $otherIds = dtcInsertPeople($other->id, [['dni', '12345678Z']]);

    dtcMigration()->normalize(DB::connection('pgsql_platform'), $tenantIds);

    $rows = DB::connection('pgsql_platform')->table('people')->whereIn('id', [...$ids, ...$otherIds])->orderBy('id')->get(['document_type', 'document_number']);

    expect($rows->map(fn ($row) => [$row->document_type, $row->document_number])->all())->toBe([
        ['dni', '12345678Z'],
        ['dni', '12345678Z'],
        ['dni', '12345678Z'],
    ]);
});

// CA-CORE-285
test('CA-CORE-285 (INV-009): los cuatro lang/*/core.php tienen los errores nuevos del catálogo', function (): void {
    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        $catalog = require base_path("lang/{$locale}/core.php");

        expect($catalog['validation'])->toHaveKeys(['document_type_invalid', 'document_incomplete'])
            ->and($catalog['import'])->toHaveKeys(['tipo_documento_no_valido', 'documento_incompleto']);

        expect($catalog['validation']['document_type_invalid'])->not->toContain(':value');
    }
});

/**
 * @param  list<array{0: ?string, 1: ?string}>  $documents
 * @return list<int>
 */
function dtcInsertPeople(int $tenantId, array $documents, bool $deleteSecond = false): array
{
    $ids = [];

    foreach ($documents as $index => [$type, $number]) {
        $ids[] = DB::connection('pgsql_platform')->table('people')->insertGetId([
            'tenant_id' => $tenantId,
            'public_id' => (string) Str::ulid(),
            'given_name' => 'Sintetica',
            'family_name_1' => 'Prueba',
            'document_type' => $type,
            'document_number' => $number,
            'locale' => 'es-ES',
            'deleted_at' => $deleteSecond && $index === 1 ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    dtcTrackedPeople($ids);

    return $ids;
}

/**
 * @param  list<int>  $add
 * @return list<int>
 */
function dtcTrackedPeople(array $add = [], bool $reset = false): array
{
    static $tracked = [];

    $tracked = $reset ? [] : [...$tracked, ...$add];

    return $tracked;
}

function dtcMigration(): Migration
{
    return require base_path('app/Modules/Core/Database/migrations/2026_10_02_100200_normalize_people_document_to_catalog.php');
}
