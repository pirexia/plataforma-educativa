<?php

use App\Models\Person;
use App\Models\User;
use App\Support\Files\ForeignObjectKeyException;
use App\Support\Files\PrivateObjectStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;
use Tests\Support\ImageFixtures;

// REQ-FAM-UNIT-005 (1.14b-a), docs/modulos/REQ-FAM-UNIT/api.md §8.1, §8.2, funcional.md §21.6, ADR-068 §5.2, §5.3, §5.4:
// personas de recogida y su fotografía. CA-FAM-UNIT-121, -122, -126, -127, -128, -130, OPEN-068-07, RN-FAM-UNIT-83, -89, -90,
// -93, INV-001, INV-002, INV-003, INV-010, INV-015.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array{0: object, 1: User} */
function personsScenario(string $slug): array
{
    return Fam::setup($slug);
}

test('api.md §8.2 RN-FAM-UNIT-89: POST /pickup-persons crea una persona sin cuenta con nombre, documento y teléfono (201) y la audita Core', function (): void {
    [$tenant, $admin] = personsScenario('pp-crea');

    $response = Fam::registerPerson($tenant, $admin, ['given_name' => 'Rosa', 'family_name_1' => 'Gil', 'family_name_2' => 'Soler', 'document_type' => 'dni', 'document_number' => ' '.strtolower(Fam::dni(2)).' ', 'contact_phone' => '611222333'])->assertCreated();

    expect($response->json())->toBe([
        'person' => $response->json('person'),
        'display_name' => 'Rosa Gil',
        'document' => ['type' => 'dni', 'number' => Fam::dni(2)],
        'contact_phone' => '611222333',
        'has_photo' => false,
        'photo_updated_at' => null,
        'reused' => false,
        'data_mismatch' => false,
    ]);

    $row = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('people')->where('public_id', $response->json('person'))->first());
    expect($row->family_name_2)->toBe('Soler')
        ->and($row->birth_date)->toBeNull()
        ->and($row->contact_email)->toBeNull()
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('users')->where('person_id', $row->id)->exists()))->toBeFalse()
        ->and(count(Fam::audit($tenant, 'person', 'created')))->toBeGreaterThanOrEqual(1);
});

test('CA-FAM-UNIT-126 RN-FAM-UNIT-89: dar de alta un tercero con el documento de una persona viva y otro nombre reutiliza la persona (200), no cambia su nombre ni su teléfono, e indica data_mismatch', function (): void {
    $f = Fam::pickupFamily('pp-126');
    $first = Fam::registerPerson($f['tenant'], $f['admin'], ['given_name' => 'María', 'family_name_1' => 'Soler', 'document_type' => 'dni', 'document_number' => Fam::dni(3), 'contact_phone' => '611111111'])->assertCreated();
    Fam::call($f['tenant'], $f['admin'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $first->json('person')))->assertCreated();
    $before = app(TenantContext::class)->runFor($f['tenant']->id, fn () => (array) DB::table('people')->where('public_id', $first->json('person'))->first());

    $again = Fam::registerPerson($f['tenant'], $f['admin'], ['given_name' => 'Marta', 'family_name_1' => 'Solé', 'document_type' => 'dni', 'document_number' => Fam::dni(3), 'contact_phone' => '699999999'])->assertOk();

    expect($again->json('person'))->toBe($first->json('person'))
        ->and($again->json('reused'))->toBeTrue()
        ->and($again->json('data_mismatch'))->toBeTrue()
        // El nombre y el teléfono registrados no cambian y siguen siendo los de la ficha.
        ->and($again->json('display_name'))->toBe('María Soler')
        ->and($again->json('contact_phone'))->toBe('611111111')
        ->and(app(TenantContext::class)->runFor($f['tenant']->id, fn () => (array) DB::table('people')->where('public_id', $first->json('person'))->first()))->toBe($before);

    // Mismos datos: reutiliza y no hay discrepancia.
    $same = Fam::registerPerson($f['tenant'], $f['admin'], ['given_name' => 'María', 'family_name_1' => 'Soler', 'document_type' => 'dni', 'document_number' => Fam::dni(3), 'contact_phone' => '611111111'])->assertOk();
    expect($same->json('data_mismatch'))->toBeFalse();
});

test('api.md §8.2: una persona reutilizada que estos endpoints no alcanzan (p. ej. un empleado) solo expone su identificador y los indicadores, y no es alcanzable después', function (): void {
    [$tenant, $admin] = personsScenario('pp-empleado');
    $employee = app(TenantContext::class)->runFor($tenant->id, fn () => Person::query()->whereHas('user')->firstOrFail());
    app(TenantContext::class)->runFor($tenant->id, fn () => Person::query()->whereKey($employee->id)->update(['document_type' => 'dni', 'document_number' => Fam::dni(4)]));

    $response = Fam::registerPerson($tenant, $admin, ['given_name' => 'Otro', 'family_name_1' => 'Nombre', 'document_type' => 'dni', 'document_number' => Fam::dni(4)])->assertOk();

    expect($response->json())->toBe(['person' => $employee->public_id, 'reused' => true, 'data_mismatch' => true]);

    // Ni se encuentra por documento, ni se lee, ni se corrige, ni se le sube foto: no hay directorio de personas del centro.
    expect(Fam::call($tenant, $admin, 'get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(4))->assertOk()->json('data'))->toBe([]);
    Fam::call($tenant, $admin, 'get', "/pickup-persons/{$employee->public_id}")->assertNotFound();
    Fam::call($tenant, $admin, 'patch', "/pickup-persons/{$employee->public_id}", ['contact_phone' => '600'])->assertNotFound();
    Fam::putPhoto($tenant, $admin, $employee->public_id)->assertNotFound();
    Fam::call($tenant, $admin, 'delete', "/pickup-persons/{$employee->public_id}/photo")->assertNotFound();
});

test('api.md §8.2 INV-010: el documento se valida con las reglas de Core (catálogo, par completo, dígito de control) y los datos mínimos son obligatorios — 422 sin escribir nada', function (): void {
    [$tenant, $admin] = personsScenario('pp-valida');
    $count = fn () => app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('people')->count());
    $before = $count();

    $cases = [
        [['document_type' => 'carnet', 'document_number' => 'ABC'], 'document_type', 'core.validation.document_type_invalid'],
        [['document_type' => 'dni'], 'document_number', 'core.validation.document_incomplete'],
        [['document_number' => Fam::dni(5)], 'document_type', 'core.validation.document_incomplete'],
        [['document_type' => 'dni', 'document_number' => '12345678A'], 'document_number', 'core.validation.document_number_invalid'],
        [['given_name' => ''], 'given_name', 'fam_unit.validation.required'],
        [['family_name_1' => null], 'family_name_1', 'fam_unit.validation.required'],
        [['given_name' => 5], 'given_name', 'fam_unit.validation.text_invalid'],
        [['contact_phone' => str_repeat('1', 33)], 'contact_phone', 'fam_unit.validation.text_too_long'],
    ];

    foreach ($cases as [$body, $field, $code]) {
        $response = Fam::registerPerson($tenant, $admin, $body)->assertStatus(422);
        expect(Fam::errorCode($response, $field))->toBe($code, json_encode($body));
    }

    expect($count())->toBe($before);
});

test('api.md §8.2: GET /pickup-persons busca por documento — 0 o 1 persona alcanzable — y exige document_type y document_number', function (): void {
    $f = Fam::pickupFamily('pp-busca');
    $third = Fam::thirdParty($f, 6);

    $found = Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-persons?document_type=dni&document_number='.strtolower(Fam::dni(6)))->assertOk();
    expect($found->json('data'))->toHaveCount(1)
        ->and($found->json('data.0.person'))->toBe($third['person'])
        ->and($found->json('data.0.document.number'))->toBe(Fam::dni(6))
        ->and($found->json('data.0.has_photo'))->toBeTrue();

    expect(Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(7))->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-persons?document_type=carnet&document_number=X')->assertOk()->json('data'))->toBe([]);

    Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-persons')->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-persons?document_type=dni')->assertStatus(422);

    // Un tutor del centro también es una persona de recogida alcanzable.
    $tutorId = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['principal']->person_id));
    app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->whereKey($tutorId->id)->update(['document_type' => 'dni', 'document_number' => Fam::dni(8)]));
    expect(Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(8))->assertOk()->json('data.0.person'))->toBe($tutorId->public_id);
});

test('api.md §8.2: GET /pickup-persons/{person} devuelve la ficha (documento completo) de un tercero o un tutor, y 404 de una persona inexistente', function (): void {
    $f = Fam::pickupFamily('pp-show');
    $third = Fam::thirdParty($f, 9);

    $show = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-persons/{$third['person']}")->assertOk();
    expect(array_keys($show->json()))->toBe(['person', 'display_name', 'document', 'contact_phone', 'has_photo', 'photo_updated_at'])
        ->and($show->json('document'))->toBe(['type' => 'dni', 'number' => Fam::dni(9)])
        ->and($show->json('photo_updated_at'))->not->toBeNull()
        ->and(json_encode($show->json()))->not->toContain('object_key')->not->toContain('tenants/');

    Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-persons/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

test('CA-FAM-UNIT-130 OPEN-068-07: PATCH corrige apellido o teléfono de una persona sin usuario por correctUnaccounted (200, audita Core); con usuario, no alcanzable o de otro centro, 404; sin permiso 403; con documento 422', function (): void {
    $f = Fam::pickupFamily('pp-130');
    $other = Fam::pickupFamily('pp-130b');
    $third = Fam::thirdParty($f, 10);
    $foreign = Fam::thirdParty($other, 11);
    $tutorPerson = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['principal']->person_id));
    $auditsBefore = count(Fam::audit($f['tenant'], 'person', 'updated'));

    $patched = Fam::call($f['tenant'], $f['admin'], 'patch', "/pickup-persons/{$third['person']}", ['family_name_1' => 'Corregido', 'contact_phone' => '655444333', 'family_name_2' => null])->assertOk();

    expect($patched->json('display_name'))->toBe('Abuelo Corregido')
        ->and($patched->json('contact_phone'))->toBe('655444333')
        // El documento no cambia.
        ->and($patched->json('document.number'))->toBe(Fam::dni(10))
        ->and(count(Fam::audit($f['tenant'], 'person', 'updated')))->toBe($auditsBefore + 1);

    // El cambio de un campo no toca los demás.
    $partial = Fam::call($f['tenant'], $f['admin'], 'patch', "/pickup-persons/{$third['person']}", ['given_name' => 'Abuela'])->assertOk();
    expect($partial->json('display_name'))->toBe('Abuela Corregido')->and($partial->json('contact_phone'))->toBe('655444333');

    // Una persona con usuario (un tutor del centro con cuenta) se corrige por /users: 404 sin modificar nada ni revelar que existe.
    $phone = $tutorPerson->contact_phone;
    Fam::call($f['tenant'], $f['admin'], 'patch', "/pickup-persons/{$tutorPerson->public_id}", ['contact_phone' => '600000001'])->assertNotFound();
    expect(app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($tutorPerson->id)->contact_phone))->toBe($phone);

    // De otro centro, inexistente: 404.
    Fam::call($f['tenant'], $f['admin'], 'patch', "/pickup-persons/{$foreign['person']}", ['contact_phone' => '600000002'])->assertNotFound();
    Fam::call($f['tenant'], $f['admin'], 'patch', '/pickup-persons/01JZZZZZZZZZZZZZZZZZZZZZZZ', ['contact_phone' => '600000003'])->assertNotFound();

    // El documento no es editable (422), y un cuerpo vacío o sin campos reconocidos tampoco.
    foreach ([['document_number' => Fam::dni(12)], ['document_type' => 'nie'], ['document_type' => 'dni', 'document_number' => Fam::dni(12), 'contact_phone' => '1']] as $body) {
        $response = Fam::call($f['tenant'], $f['admin'], 'patch', "/pickup-persons/{$third['person']}", $body)->assertStatus(422);
        expect(array_keys($response->json('errors')))->toContain(array_key_first(array_intersect_key($body, ['document_type' => 1, 'document_number' => 1])));
    }

    expect(Fam::errorCode(Fam::call($f['tenant'], $f['admin'], 'patch', "/pickup-persons/{$third['person']}", [])->assertStatus(422), 'body'))->toBe('fam_unit.validation.at_least_one_field')
        ->and(Fam::errorCode(Fam::call($f['tenant'], $f['admin'], 'patch', "/pickup-persons/{$third['person']}", ['given_name' => ''])->assertStatus(422), 'given_name'))->toBe('fam_unit.validation.required');

    // Sin permiso, 403.
    $reader = Fam::userWith($f['tenant'], $f['admin'], 'lector', ['autorizado_recogida.leer' => 'todos']);
    Fam::call($f['tenant'], $reader, 'patch', "/pickup-persons/{$third['person']}", ['contact_phone' => '600000004'])->assertForbidden();
});

test('CA-FAM-UNIT-121 RN-FAM-UNIT-83: la fotografía se reprocesa — un JPEG con EXIF de geolocalización se guarda girado y sin ningún metadato bajo una clave con el prefijo del centro y nombre ULID — y se sirve solo por URL firmada', function (): void {
    $f = Fam::pickupFamily('pp-121');
    $person = Fam::authorizedPerson($f);
    $original = ImageFixtures::jpegWithExifAndGps(1600, 800, 6);

    $response = Fam::putPhoto($f['tenant'], $f['admin'], $person, $original, 'movil.jpg')->assertOk();
    expect($response->json('has_photo'))->toBeTrue()
        ->and($response->json('photo_updated_at'))->not->toBeNull();

    $row = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->first());
    $stored = Storage::disk('local')->get($row->object_key);

    expect($row->object_key)->toMatch('#^tenants/'.$f['tenant']->public_id.'/fam-unit/pickup-photos/[0-9A-HJKMNP-TV-Z]{26}\.jpg$#')
        ->and($row->mime_type)->toBe('image/jpeg')
        ->and($row->byte_size)->toBe(strlen($stored))
        // Lo guardado es otra imagen: girada (1600x800 con orientación 6 es 800x1024 tras reducir) y sin metadatos.
        ->and(ImageFixtures::size($stored))->toBe([512, 1024])
        ->and($stored)->not->toContain('Exif')->not->toContain('comentario-secreto-del-movil')
        ->and($stored)->not->toBe($original)
        // El original no se guarda en ningún sitio del bucket.
        ->and(Storage::disk('local')->allFiles())->toBe([$row->object_key]);

    $audit = Fam::audit($f['tenant'], 'pickup_photo', 'created');
    expect($audit)->toHaveCount(1)
        ->and(json_encode($audit[0]->changes))->not->toContain('tenants/')->not->toContain($row->object_key);
});

test('CA-FAM-UNIT-121: un PNG con extensión .jpg es 422 type_mismatch, un SVG y un HEIC son 415, un fichero de más de 5 MB es 413 y unas dimensiones excesivas son 422 dimensions — sin escribir nada en el bucket', function (): void {
    $f = Fam::pickupFamily('pp-121b');
    $person = Fam::authorizedPerson($f);
    config(['fam_unit.pickup.photo.max_bytes' => 2000, 'fam_unit.pickup.photo.max_input_pixels' => 100000]);

    $mismatch = Fam::putPhoto($f['tenant'], $f['admin'], $person, ImageFixtures::png(), 'foto.jpg')->assertStatus(422);
    expect(Fam::errorCode($mismatch, 'file'))->toBe('fam_unit.photo.type_mismatch');

    Fam::putPhoto($f['tenant'], $f['admin'], $person, ImageFixtures::svg(), 'foto.svg')->assertStatus(415);
    Fam::putPhoto($f['tenant'], $f['admin'], $person, ImageFixtures::svg(), 'foto.png')->assertStatus(415);
    Fam::putPhoto($f['tenant'], $f['admin'], $person, ImageFixtures::heic(), 'foto.heic')->assertStatus(415);
    Fam::putPhoto($f['tenant'], $f['admin'], $person, str_repeat('x', 6 * 1024 * 1024), 'grande.jpg')->assertStatus(413);

    $dimensions = Fam::putPhoto($f['tenant'], $f['admin'], $person, ImageFixtures::pngClaiming(20000, 20000), 'bomba.png')->assertStatus(422);
    expect(Fam::errorCode($dimensions, 'file'))->toBe('fam_unit.photo.dimensions');

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->count()))->toBe(0);

    // Sin fichero: 422 required (después de resolver la persona: una inexistente es 404).
    resetSessionState();
    $none = test()->actingAs($f['admin'])->putJson(Fam::url($f['tenant'], "/pickup-persons/{$person}/photo"), [])->assertStatus(422);
    expect(Fam::errorCode($none, 'file'))->toBe('fam_unit.validation.required');
    resetSessionState();
    test()->actingAs($f['admin'])->putJson(Fam::url($f['tenant'], '/pickup-persons/01JZZZZZZZZZZZZZZZZZZZZZZZ/photo'), [])->assertNotFound();
});

test('RN-FAM-UNIT-83: sustituir la fotografía da de baja la anterior (una viva por persona), borrar la deja sin foto, y el barrido de huérfanos retira el objeto anterior', function (): void {
    $f = Fam::pickupFamily('pp-sustituye');
    $person = Fam::authorizedPerson($f);

    Fam::putPhoto($f['tenant'], $f['admin'], $person, ImageFixtures::png(), 'a.png')->assertOk();
    $first = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->first());
    Fam::tick();
    Fam::putPhoto($f['tenant'], $f['admin'], $person, ImageFixtures::webp(), 'b.webp')->assertOk();

    $rows = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->orderBy('id')->get());
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->deleted_at)->not->toBeNull()
        ->and($rows[1]->deleted_at)->toBeNull()
        ->and($rows[1]->mime_type)->toBe('image/webp')
        // Los dos objetos siguen hasta que el barrido retire el de la fila dada de baja.
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);

    Fam::call($f['tenant'], $f['admin'], 'delete', "/pickup-persons/{$person}/photo")->assertNoContent();
    expect(Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-persons/{$person}")->assertOk()->json('has_photo'))->toBeFalse();
    // Idempotente.
    Fam::call($f['tenant'], $f['admin'], 'delete', "/pickup-persons/{$person}/photo")->assertNoContent();

    expect($first->object_key)->not->toBe($rows[1]->object_key);
});

test('CA-FAM-UNIT-122 api.md §8.2: GET …/photo devuelve una URL firmada de 5 minutos que cambia en cada respuesta, escribe UNA fila read sobre la persona de la foto sin datos personales ni clave de objeto, y 404 si no hay foto', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pp-122');
    $third = Fam::thirdParty($f, 13);
    $reads = fn () => count(Fam::audit($f['tenant'], 'person', 'read'));
    $before = $reads();

    $first = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-persons/{$third['person']}/photo")->assertOk();
    expect(array_keys($first->json()))->toBe(['url', 'expires_at'])
        ->and($first->json('expires_at'))->toBe('2026-11-02T09:05:00Z')
        ->and($first->json('url'))->toContain($f['tenant']->public_id)
        ->and($reads())->toBe($before + 1);

    $read = Fam::audit($f['tenant'], 'person', 'read')[$before];
    expect($read->auditable_public_id)->toBe($third['person'])
        ->and($read->changes)->toBeNull()
        ->and($read->context)->toEqual(['purpose' => 'gestion', 'entries' => 1, 'photos' => 1])
        ->and(json_encode($read->toArray()))->not->toContain('Abuelo')->not->toContain(Fam::dni(13))->not->toContain('pickup-photos');

    Fam::tick();
    $second = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-persons/{$third['person']}/photo")->assertOk();
    expect($second->json('url'))->not->toBe($first->json('url'));

    $noPhoto = Fam::authorizedPerson($f);
    Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-persons/{$noPhoto}/photo")->assertNotFound();
});

test('CA-FAM-UNIT-127 RN-FAM-UNIT-93: la lectura N+1 de la lista con fotografía por el mismo usuario y centro es 429 con Retry-After; otro usuario no se ve afectado', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    config(['fam_unit.pickup.read_rate_limit.attempts' => 3]);
    $f = Fam::pickupFamily('pp-127');
    $third = Fam::thirdParty($f, 14);
    $other = Fam::userWith($f['tenant'], $f['admin'], 'otro', ['autorizado_recogida.leer' => 'todos']);

    foreach (range(1, 3) as $i) {
        Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
    }

    $limited = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->not->toBeNull()
        ->and((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($limited->json('type'))->toBe('urn:pge:error:too-many-requests')
        ->and($limited->getContent())->not->toContain('photo_url');

    // La foto cuenta en el mismo cubo; otro usuario del mismo centro sigue pudiendo leer.
    Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-persons/{$third['person']}/photo")->assertStatus(429);
    Fam::call($f['tenant'], $other, 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
});

test('CA-FAM-UNIT-128 RN-FAM-UNIT-92 INV-003: el alta y la sustitución de una fotografía y el alta de una persona se auditan con política Selective y sin valores redactados en changes', function (): void {
    $f = Fam::pickupFamily('pp-128');
    $third = Fam::thirdParty($f, 15);
    Fam::tick();
    Fam::putPhoto($f['tenant'], $f['admin'], $third['person'], ImageFixtures::png(), 'otra.png')->assertOk();

    $photoLogs = Fam::audit($f['tenant'], 'pickup_photo');
    $created = collect($photoLogs)->where('event', 'created');
    $deleted = collect($photoLogs)->where('event', 'deleted');

    expect($created)->toHaveCount(2)
        ->and($deleted)->toHaveCount(1)
        ->and($created->first()->changes)->toHaveKeys(['mime_type', 'byte_size'])
        ->and($created->first()->changes['mime_type'])->toEqual(['from' => null, 'to' => 'image/jpeg']);

    foreach (['person_id', 'object_key'] as $column) {
        expect($created->first()->changes[$column])->toHaveKey('redacted');
    }

    $dump = json_encode(array_map(fn ($log) => $log->changes, $photoLogs));
    expect($dump)->not->toContain('tenants/')->not->toContain('.jpg')->not->toContain('.png');

    $authLogs = collect(Fam::audit($f['tenant'], 'pickup_authorization', 'created'));
    expect($authLogs)->toHaveCount(1)
        ->and($authLogs->first()->changes['relationship'])->toEqual(['from' => null, 'to' => 'abuelo'])
        ->and($authLogs->first()->changes['channel']['to'])->toBe('presencial')
        ->and($authLogs->first()->changes['student_person_id'])->toHaveKey('redacted')
        ->and($authLogs->first()->changes['authorized_by_guardian_id'])->toHaveKey('redacted');
});

test('INV-002 CA-FAM-UNIT-123: las rutas de personas de recogida y fotografía deniegan por defecto — sin permiso 403, solo-leer no escribe, un deny en otro rol gana (RPERM-007) y tutor_legal no ve fotografías', function (): void {
    $f = Fam::pickupFamily('pp-perm');
    $third = Fam::thirdParty($f, 16);
    $nobody = Fam::userWith($f['tenant'], $f['admin'], 'nadie', ['familia.leer' => 'todos']);
    $reader = Fam::userWith($f['tenant'], $f['admin'], 'lector', ['autorizado_recogida.leer' => 'todos']);
    $creator = Fam::userWith($f['tenant'], $f['admin'], 'creador', ['autorizado_recogida.crear' => 'todos']);
    $denied = Fam::userWithRoles($f['tenant'], $f['admin'], 'denegado', [
        'allow' => ['autorizado_recogida.actualizar' => ['allow', 'todos'], 'autorizado_recogida.leer' => ['allow', 'todos']],
        'deny' => ['autorizado_recogida.actualizar' => ['deny', 'todos']],
    ]);

    $calls = [
        ['get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(16)],
        ['post', '/pickup-persons'],
        ['get', "/pickup-persons/{$third['person']}"],
        ['patch', "/pickup-persons/{$third['person']}"],
        ['get', "/pickup-persons/{$third['person']}/photo"],
        ['delete', "/pickup-persons/{$third['person']}/photo"],
    ];

    foreach ($calls as [$method, $path]) {
        expect(Fam::call($f['tenant'], $nobody, $method, $path, ['given_name' => 'X', 'family_name_1' => 'Y'])->getStatusCode())->toBe(403, "nadie {$method} {$path}");
    }

    // Solo leer: ve la foto, no la gestiona; solo crear: da de alta pero no lee ni corrige.
    Fam::call($f['tenant'], $reader, 'get', "/pickup-persons/{$third['person']}/photo")->assertOk();
    Fam::call($f['tenant'], $reader, 'get', "/pickup-persons/{$third['person']}")->assertForbidden();
    Fam::call($f['tenant'], $reader, 'post', '/pickup-persons', ['given_name' => 'X', 'family_name_1' => 'Y'])->assertForbidden();
    Fam::putPhoto($f['tenant'], $reader, $third['person'])->assertForbidden();
    Fam::registerPerson($f['tenant'], $creator)->assertCreated();
    Fam::call($f['tenant'], $creator, 'get', "/pickup-persons/{$third['person']}")->assertForbidden();
    Fam::call($f['tenant'], $creator, 'get', "/pickup-persons/{$third['person']}/photo")->assertForbidden();

    // Un deny en otro rol sobrescribe el allow (RPERM-007).
    Fam::call($f['tenant'], $denied, 'get', "/pickup-persons/{$third['person']}")->assertForbidden();
    Fam::putPhoto($f['tenant'], $denied, $third['person'])->assertForbidden();
    Fam::call($f['tenant'], $denied, 'get', "/pickup-persons/{$third['person']}/photo")->assertOk();

    // Un tutor con unidad_familiar lee la lista de sus alumnos pero nunca la fotografía de un tercero (OPEN-FAM-UNIT-43).
    Fam::call($f['tenant'], $f['principal'], 'get', "/pickup-persons/{$third['person']}/photo")->assertNotFound();
    Fam::call($f['tenant'], $f['principal'], 'get', "/pickup-persons/{$third['person']}")->assertForbidden();
});

test('INV-001 CA-FAM-UNIT-124: ninguna ruta de personas de recogida de un centro alcanza personas, fotografías ni claves de otro', function (): void {
    $a = Fam::pickupFamily('pp-iso-a');
    $b = Fam::pickupFamily('pp-iso-b');
    $thirdB = Fam::thirdParty($b, 17);
    $thirdA = Fam::thirdParty($a, 17);

    // Mismo documento en los dos centros: cada uno ve el suyo.
    expect($thirdA['person'])->not->toBe($thirdB['person'])
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(17))->assertOk()->json('data.0.person'))->toBe($thirdA['person']);

    Fam::call($a['tenant'], $a['admin'], 'get', "/pickup-persons/{$thirdB['person']}")->assertNotFound();
    Fam::call($a['tenant'], $a['admin'], 'patch', "/pickup-persons/{$thirdB['person']}", ['contact_phone' => '600'])->assertNotFound();
    Fam::call($a['tenant'], $a['admin'], 'get', "/pickup-persons/{$thirdB['person']}/photo")->assertNotFound();
    Fam::putPhoto($a['tenant'], $a['admin'], $thirdB['person'])->assertNotFound();
    Fam::call($a['tenant'], $a['admin'], 'delete', "/pickup-persons/{$thirdB['person']}/photo")->assertNotFound();

    // La foto de B sigue intacta y su clave no es firmable desde A (la clave de B jamás sale de B).
    $keyB = app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('pickup_photos')->value('object_key'));
    expect(app(TenantContext::class)->runFor($b['tenant']->id, fn () => DB::table('pickup_photos')->count()))->toBe(1)
        ->and(Storage::disk('local')->exists($keyB))->toBeTrue()
        ->and($keyB)->toStartWith("tenants/{$b['tenant']->public_id}/")
        ->and(fn () => app(PrivateObjectStore::class))->not->toThrow(Throwable::class);

    app(TenantContext::class)->runFor($a['tenant']->id, function () use ($keyB): void {
        expect(fn () => app(PrivateObjectStore::class)->temporaryUrl($keyB, now()->addMinutes(5)))->toThrow(ForeignObjectKeyException::class);
    });
});

test('api.md §8.2: sin el módulo contratado la subida de fotografía no escribe nada (403 module-disabled)', function (): void {
    $f = Fam::pickupFamily('pp-modulo');
    $person = Fam::authorizedPerson($f);
    Fam::disableModule($f['tenant']);

    $response = Fam::putPhoto($f['tenant'], $f['admin'], $person)->assertForbidden();

    expect($response->json('type'))->toBe('urn:pge:error:module-disabled')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('RN-FAM-UNIT-83 ADR-068 §5.3 (security M2): una fotografía del máximo admitido — 12 MP con orientación EXIF 6 — se reprocesa girada y reducida sin sobrepasar la memoria, y se mide el tiempo de la subida', function (): void {
    $f = Fam::pickupFamily('pp-12mp');
    $person = Fam::authorizedPerson($f);
    $big = ImageFixtures::jpegWithExifAndGps(4000, 3000, 6);
    expect(strlen($big))->toBeLessThan(5 * 1024 * 1024);

    // El `memory_limit` del contenedor (Containerfile) es de 256 MB. Aquí no se puede fijar con ini_set (un proceso de la suite
    // ya usa más); se comprueba aparte, en un proceso nuevo: `php -d memory_limit=256M vendor/bin/pest --filter="12 MP"`.
    $start = microtime(true);
    Fam::putPhoto($f['tenant'], $f['admin'], $person, $big, 'camara.jpg')->assertOk();
    $elapsed = microtime(true) - $start;

    $key = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_photos')->value('object_key'));
    $stored = Storage::disk('local')->get($key);

    // 4000 x 3000 con orientación 6 es 3000 x 4000 al verla; a 1024 px de lado mayor, 768 x 1024. Sin metadatos.
    expect(ImageFixtures::size($stored))->toBe([768, 1024])
        ->and($stored)->not->toContain('Exif')->not->toContain('comentario-secreto-del-movil')
        ->and($stored)->not->toBe($big);
    fwrite(STDERR, sprintf("[medición ADR-068 §5.3] subida de 12 MP: %.2f s\n", $elapsed));
});

test('RN-FAM-UNIT-92 enmendada (decisión del usuario del 2026-11-02): GET /pickup-persons con coincidencia y GET /pickup-persons/{p} escriben una fila read sobre la persona, sin valores; sin coincidencia, ninguna', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pp-lectura');
    $third = Fam::thirdParty($f, 20);
    $reads = fn () => Fam::audit($f['tenant'], 'person', 'read');
    $before = count($reads());

    Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(21))->assertOk();
    expect(count($reads()))->toBe($before);

    Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(20))->assertOk();
    Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-persons/{$third['person']}")->assertOk();

    $new = array_slice($reads(), $before);
    expect($new)->toHaveCount(2);

    foreach ($new as $read) {
        expect($read->auditable_public_id)->toBe($third['person'])
            ->and($read->changes)->toBeNull()
            ->and($read->context)->toEqual(['purpose' => 'gestion', 'entries' => 1, 'photos' => 0])
            ->and(json_encode($read->toArray()))->not->toContain(Fam::dni(20))->not->toContain('Abuelo')->not->toContain('600000000');
    }
});

test('RN-FAM-UNIT-92 enmendada: la búsqueda por documento y el alta con reutilización tienen cada una su cubo de frecuencia (429 con Retry-After), distintos entre sí y del de la lista', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    config(['fam_unit.pickup.person_rate_limit.lookup.attempts' => 2, 'fam_unit.pickup.person_rate_limit.register.attempts' => 2]);
    $f = Fam::pickupFamily('pp-cubos');
    $other = Fam::userWith($f['tenant'], $f['admin'], 'otro', ['autorizado_recogida.actualizar' => 'todos', 'autorizado_recogida.crear' => 'todos']);
    $search = fn ($user) => Fam::call($f['tenant'], $user, 'get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(30));
    $register = fn ($user) => Fam::registerPerson($f['tenant'], $user, ['document_type' => 'dni', 'document_number' => Fam::dni(31)]);

    $search($f['admin'])->assertOk();
    $search($f['admin'])->assertOk();
    $limited = $search($f['admin'])->assertStatus(429);
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0);

    // El alta tiene su propio cubo: sigue disponible tras agotar la búsqueda (2 intentos, incluida la reutilización).
    $register($f['admin'])->assertCreated();
    $register($f['admin'])->assertOk();
    $register($f['admin'])->assertStatus(429);

    // Otro usuario no se ve afectado, y la lista (otro cubo) tampoco.
    $search($other)->assertOk();
    Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
});

test('RN-FAM-UNIT-92 enmendada (security-reviewer N1): las respuestas que devuelven el documento completo — POST con reutilización, PATCH y PUT …/photo — escriben también una fila read sobre la persona; el alta de una persona nueva, no', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pp-n1');
    $reads = fn () => count(Fam::audit($f['tenant'], 'person', 'read'));
    $body = ['given_name' => 'Rosa', 'family_name_1' => 'Gil', 'document_type' => 'dni', 'document_number' => Fam::dni(50)];

    $before = $reads();
    $created = Fam::registerPerson($f['tenant'], $f['admin'], $body)->assertCreated();
    expect($reads())->toBe($before);

    Fam::call($f['tenant'], $f['admin'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $created->json('person')))->assertCreated();

    Fam::registerPerson($f['tenant'], $f['admin'], $body)->assertOk();
    expect($reads())->toBe($before + 1);

    Fam::call($f['tenant'], $f['admin'], 'patch', "/pickup-persons/{$created->json('person')}", ['contact_phone' => '611000000'])->assertOk();
    expect($reads())->toBe($before + 2);

    Fam::putPhoto($f['tenant'], $f['admin'], $created->json('person'))->assertOk();
    expect($reads())->toBe($before + 3);

    $last = Fam::audit($f['tenant'], 'person', 'read')[$before + 2];
    expect($last->auditable_public_id)->toBe($created->json('person'))->and($last->changes)->toBeNull()
        ->and(json_encode($last->toArray()))->not->toContain(Fam::dni(50))->not->toContain('611000000');
});

test('RN-FAM-UNIT-81 issue #510: las lecturas de la lista de recogida, de personas y de fotografía llevan Cache-Control: no-store', function (): void {
    $f = Fam::pickupFamily('pp-nostore');
    $third = Fam::thirdParty($f, 14);
    $urls = [
        '/pickup-authorizations',
        "/pickup-authorizations/{$third['authorization']}",
        "/pickup-lists/{$f['student']->public_id}",
        '/pickup-persons?document_type=dni&document_number='.Fam::dni(14),
        "/pickup-persons/{$third['person']}",
        "/pickup-persons/{$third['person']}/photo",
    ];

    foreach ($urls as $url) {
        $response = Fam::call($f['tenant'], $f['admin'], 'get', $url)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});
