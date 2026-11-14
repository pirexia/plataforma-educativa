<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AlumTestHelpers as Alum;
use Tests\Support\FamUnitTestHelpers as Fam;
use Tests\Support\ImageFixtures;

// REQ-ALUM-001 (1.15-c T2b), docs/modulos/REQ-ALUM/api.md §4, funcional.md §4.6, RN-ALUM-50 a -53, -64, CA-ALUM-056, -057, -058,
// -018c, ADR-068 §5.3, ADR-013, INV-001, INV-002, INV-003, INV-010, INV-015: fotografía del alumno.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, mixed> */
function photoScenario(string $slug): array
{
    [$tenant, $admin] = Alum::setup($slug);
    $student = Alum::student($tenant, $admin, ['given_name' => 'Alumno', 'family_name_1' => 'Foto']);

    return compact('tenant', 'admin', 'student');
}

/** Filas de `student_photos` del centro, de la más antigua a la más reciente. */
function photoRows(object $tenant): array
{
    return app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('student_photos')->orderBy('id')->get()->all());
}

test('CA-ALUM-056 RN-ALUM-51: un SVG, un HEIC, un fichero con extensión de imagen pero otro contenido, un WebP, uno demasiado grande y unas dimensiones excesivas son 422 y no guardan nada; sin fichero, 422; alumno inexistente, 404', function (): void {
    $c = photoScenario('ph-056');
    $id = $c['student']['public_id'];
    config(['alum.photo.max_bytes' => 2000, 'alum.photo.max_input_pixels' => 100000]);

    $code = fn ($r) => Alum::errorCode($r->assertStatus(422), 'file');

    expect($code(Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::svg(), 'foto.svg')))->toBe('alum.photo.type_unsupported')
        ->and($code(Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::svg(), 'foto.png')))->toBe('alum.photo.type_unsupported')
        ->and($code(Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::heic(), 'foto.heic')))->toBe('alum.photo.type_unsupported')
        ->and($code(Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::webp(), 'foto.webp')))->toBe('alum.photo.type_unsupported')
        ->and($code(Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::png(), 'foto.jpg')))->toBe('alum.photo.invalid_image')
        ->and($code(Alum::putPhoto($c['tenant'], $c['admin'], $id, str_repeat('x', 6 * 1024 * 1024), 'grande.jpg')))->toBe('alum.photo.too_large')
        ->and($code(Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::pngClaiming(20000, 20000), 'bomba.png')))->toBe('alum.photo.invalid_image');

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(photoRows($c['tenant']))->toBe([]);

    resetSessionState();
    $none = test()->actingAs($c['admin'])->putJson(coreApiUrl($c['tenant']->slug, "/students/{$id}/photo"), [])->assertStatus(422);
    expect(Alum::errorCode($none, 'file'))->toBe('alum.photo.file_required');
    resetSessionState();
    test()->actingAs($c['admin'])->putJson(coreApiUrl($c['tenant']->slug, '/students/01JZZZZZZZZZZZZZZZZZZZZZZZ/photo'), [])->assertNotFound();
});

test('CA-ALUM-056 RN-ALUM-51 INV-003: un JPEG con EXIF y GPS se guarda recodificado, girado y sin metadatos, bajo el prefijo del centro y sin el original; el alta se audita sin la clave del objeto; la ficha dice has_photo', function (): void {
    $c = photoScenario('ph-056b');
    $id = $c['student']['public_id'];
    $original = ImageFixtures::jpegWithExifAndGps(1600, 800, 6);

    expect(Alum::call($c['tenant'], $c['admin'], 'get', "/students/{$id}")->assertOk()->json('has_photo'))->toBeFalse();

    $response = Alum::putPhoto($c['tenant'], $c['admin'], $id, $original, 'movil.jpg')->assertOk();
    expect($response->json())->toBe(['has_photo' => true]);

    $row = photoRows($c['tenant'])[0];
    $stored = Storage::disk('local')->get($row->object_key);

    expect($row->object_key)->toMatch('#^tenants/'.$c['tenant']->public_id.'/alum/student-photos/[0-9A-HJKMNP-TV-Z]{26}\.jpg$#')
        ->and($row->content_type)->toBe('image/jpeg')
        ->and($row->byte_size)->toBe(strlen($stored))
        ->and(ImageFixtures::size($stored))->toBe([512, 1024])
        ->and($stored)->not->toContain('Exif')->not->toContain('comentario-secreto-del-movil')
        ->and($stored)->not->toBe($original)
        ->and(Storage::disk('local')->allFiles())->toBe([$row->object_key]);

    $audit = Alum::audit($c['tenant'], 'alum_student_photo', 'created');
    expect($audit)->toHaveCount(1)
        ->and(json_encode($audit[0]->changes))->not->toContain('tenants/')->not->toContain($row->object_key);

    $card = Alum::call($c['tenant'], $c['admin'], 'get', "/students/{$id}")->assertOk();
    expect($card->json('has_photo'))->toBeTrue()
        ->and(array_key_exists('photo', $card->json()))->toBeFalse()
        ->and($card->getContent())->not->toContain($row->object_key)->not->toContain('tenants/');
});

test('CA-ALUM-057 RN-ALUM-50: subir otra foto da de baja la anterior en la misma transacción (una viva por alumno); borrar la deja sin foto y es idempotente', function (): void {
    $c = photoScenario('ph-057');
    $id = $c['student']['public_id'];

    Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::jpeg(), 'a.jpg')->assertOk();
    Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::png(), 'b.png')->assertOk();

    $rows = photoRows($c['tenant']);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->deleted_at)->not->toBeNull()
        ->and($rows[1]->deleted_at)->toBeNull()
        ->and($rows[1]->content_type)->toBe('image/png')
        ->and(Storage::disk('local')->allFiles())->toBe([$rows[1]->object_key])
        ->and(count(Alum::audit($c['tenant'], 'alum_student_photo', 'deleted')))->toBe(1);

    Alum::call($c['tenant'], $c['admin'], 'delete', "/students/{$id}/photo")->assertNoContent();
    Alum::call($c['tenant'], $c['admin'], 'delete', "/students/{$id}/photo")->assertNoContent();

    expect(Alum::call($c['tenant'], $c['admin'], 'get', "/students/{$id}")->json('has_photo'))->toBeFalse();
    Alum::call($c['tenant'], $c['admin'], 'get', "/students/{$id}/photo")->assertNotFound();
});

test('CA-ALUM-058 RN-ALUM-52 RN-ALUM-64: dentro de ámbito GET …/photo devuelve una URL firmada de 5 minutos con Cache-Control no-store y escribe UNA fila read sin nombre ni clave de objeto; fuera de ámbito, o sin foto, es 404 idéntico a un inexistente y sin fila read', function (): void {
    Fam::freeze('2026-11-11 09:00:00');
    $c = photoScenario('ph-058');
    $other = Alum::student($c['tenant'], $c['admin'], ['given_name' => 'Otro', 'family_name_1' => 'Alumno']);
    $unit = Fam::unitId($c['tenant'], $c['admin'], 'U1');
    $mine = Alum::student($c['tenant'], $c['admin'], ['given_name' => 'Hijo', 'family_name_1' => 'Tutelado', 'family_unit' => $unit]);
    $tutor = Alum::userWithScopes($c['tenant'], $c['admin'], 'tutor', ['alumno.leer' => 'unidad_familiar', 'familia.leer' => 'unidad_familiar', 'vinculo_tutor.leer' => 'unidad_familiar']);
    $guardian = Fam::guardian($c['tenant'], $c['admin'], $tutor, $unit)->assertCreated()->json('public_id');
    Fam::link($c['tenant'], $c['admin'], $guardian, Alum::person($c['tenant'], $mine['person']['public_id']))->assertCreated();

    Alum::putPhoto($c['tenant'], $c['admin'], $mine['public_id'])->assertOk();
    Alum::putPhoto($c['tenant'], $c['admin'], $other['public_id'])->assertOk();
    $reads = fn () => Alum::audit($c['tenant'], 'alum_student_photo', 'read');
    $before = count($reads());

    $ok = Alum::call($c['tenant'], $tutor, 'get', "/students/{$mine['public_id']}/photo")->assertOk();
    $key = photoRows($c['tenant'])[0]->object_key;

    expect(array_keys($ok->json()))->toBe(['url', 'expires_at'])
        ->and($ok->json('expires_at'))->toBe('2026-11-11T09:05:00Z')
        ->and($ok->headers->get('Cache-Control'))->toContain('no-store')
        ->and(count($reads()))->toBe($before + 1);

    $read = $reads()[$before];
    expect($read->changes)->toBeNull()
        ->and($read->context)->toEqual(['purpose' => 'gestion', 'entries' => 1, 'student' => $mine['public_id']])
        ->and(json_encode($read->toArray()))->not->toContain('Hijo')->not->toContain('Tutelado')->not->toContain($key)->not->toContain('student-photos');

    // Fuera de ámbito: igual que un inexistente, y no deja fila de lectura.
    $outside = Alum::call($c['tenant'], $tutor, 'get', "/students/{$other['public_id']}/photo")->assertNotFound();
    $missing = Alum::call($c['tenant'], $tutor, 'get', '/students/01JZZZZZZZZZZZZZZZZZZZZZZZ/photo')->assertNotFound();
    expect(Arr::except($outside->json(), ['instance', 'request_id']))->toBe(Arr::except($missing->json(), ['instance', 'request_id']))
        ->and(count($reads()))->toBe($before + 1);

    // Sin foto: 404 y sin fila.
    $bare = Alum::student($c['tenant'], $c['admin'], ['given_name' => 'Sin', 'family_name_1' => 'Foto']);
    Alum::call($c['tenant'], $c['admin'], 'get', "/students/{$bare['public_id']}/photo")->assertNotFound();
    expect(count($reads()))->toBe($before + 1);

    // La URL cambia en cada respuesta.
    Fam::tick();
    expect(Alum::call($c['tenant'], $tutor, 'get', "/students/{$mine['public_id']}/photo")->json('url'))->not->toBe($ok->json('url'));
});

test('INV-002 api.md §4: PUT y DELETE exigen alumno.actualizar con ámbito todos (el tutor con unidad_familiar y un usuario sin permisos, 403) y GET exige alumno.leer; sin sesión, 401', function (): void {
    $c = photoScenario('ph-perm');
    $id = $c['student']['public_id'];
    Alum::putPhoto($c['tenant'], $c['admin'], $id)->assertOk();
    $reader = Alum::userWith($c['tenant'], $c['admin'], 'lector', ['alumno.leer']);
    $nobody = Alum::userWith($c['tenant'], $c['admin'], 'nadie', ['rol.leer']);

    Alum::putPhoto($c['tenant'], $reader, $id)->assertForbidden();
    Alum::call($c['tenant'], $reader, 'delete', "/students/{$id}/photo")->assertForbidden();
    Alum::call($c['tenant'], $reader, 'get', "/students/{$id}/photo")->assertOk();
    Alum::putPhoto($c['tenant'], $nobody, $id)->assertForbidden();
    Alum::call($c['tenant'], $nobody, 'get', "/students/{$id}/photo")->assertForbidden();

    resetSessionState();
    test()->getJson(coreApiUrl($c['tenant']->slug, "/students/{$id}/photo"))->assertUnauthorized();

    expect(photoRows($c['tenant']))->toHaveCount(1);
});

test('RN-ALUM-52: la lectura N+1 de fotografías por el mismo usuario y centro es 429 con Retry-After y texto propio; otro usuario no se ve afectado', function (): void {
    Fam::freeze('2026-11-11 09:00:00');
    config(['alum.photo.read_rate_limit.attempts' => 3]);
    $c = photoScenario('ph-rate');
    $id = $c['student']['public_id'];
    Alum::putPhoto($c['tenant'], $c['admin'], $id)->assertOk();
    $other = Alum::userWith($c['tenant'], $c['admin'], 'otro', ['alumno.leer']);

    foreach (range(1, 3) as $i) {
        Alum::call($c['tenant'], $c['admin'], 'get', "/students/{$id}/photo")->assertOk();
    }

    $limited = Alum::call($c['tenant'], $c['admin'], 'get', "/students/{$id}/photo")->assertStatus(429);
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($limited->json('type'))->toBe('urn:pge:error:too-many-requests')
        ->and($limited->getContent())->not->toContain('"url"');

    Alum::call($c['tenant'], $other, 'get', "/students/{$id}/photo")->assertOk();
});

test('RN-ALUM-51 INV-012: con el almacenamiento caído el PUT es 503, no guarda fila alguna y la ficha sigue sirviéndose sin foto', function (): void {
    $c = photoScenario('ph-503');
    $id = $c['student']['public_id'];

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andThrow(new RuntimeException('S3 caído'));
    Storage::set('s3_caido', $disk);
    config(['files.disk' => 's3_caido']);

    $response = Alum::putPhoto($c['tenant'], $c['admin'], $id)->assertStatus(503);
    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and(photoRows($c['tenant']))->toBe([]);

    $card = Alum::call($c['tenant'], $c['admin'], 'get', "/students/{$id}")->assertOk();
    expect($card->json('has_photo'))->toBeFalse();
});

test('datos.md §6 RN-ALUM-05: dar de baja al alumno purga la fotografía viva (fila y objeto) y restaurarlo no la recupera', function (): void {
    $c = photoScenario('ph-baja');
    $id = $c['student']['public_id'];
    Alum::putPhoto($c['tenant'], $c['admin'], $id)->assertOk();
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);

    Alum::call($c['tenant'], $c['admin'], 'delete', "/students/{$id}")->assertNoContent();

    expect(photoRows($c['tenant']))->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    Alum::call($c['tenant'], $c['admin'], 'post', "/students/{$id}/restore")->assertOk()->assertJsonPath('has_photo', false);
    Alum::call($c['tenant'], $c['admin'], 'get', "/students/{$id}/photo")->assertNotFound();
    Alum::putPhoto($c['tenant'], $c['admin'], '01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

test('CA-ALUM-018c INV-001: dos centros con fotografías equivalentes no se ven: el otro centro ni lee, ni sustituye, ni borra la foto (404) y sus filas siguen intactas', function (): void {
    $a = photoScenario('ph-iso-a');
    $b = photoScenario('ph-iso-b');
    Alum::putPhoto($a['tenant'], $a['admin'], $a['student']['public_id'])->assertOk();
    Alum::putPhoto($b['tenant'], $b['admin'], $b['student']['public_id'])->assertOk();
    $aId = $a['student']['public_id'];

    Alum::call($b['tenant'], $b['admin'], 'get', "/students/{$aId}/photo")->assertNotFound();
    Alum::putPhoto($b['tenant'], $b['admin'], $aId)->assertNotFound();
    Alum::call($b['tenant'], $b['admin'], 'delete', "/students/{$aId}/photo")->assertNotFound();

    expect(photoRows($a['tenant']))->toHaveCount(1)->and(photoRows($a['tenant'])[0]->deleted_at)->toBeNull()
        ->and(photoRows($b['tenant']))->toHaveCount(1)
        ->and(photoRows($a['tenant'])[0]->object_key)->toContain($a['tenant']->public_id)
        ->and(photoRows($b['tenant'])[0]->object_key)->toContain($b['tenant']->public_id)
        ->and(Alum::audit($b['tenant'], 'alum_student_photo', 'read'))->toBe([]);
});

test('RN-ALUM-50 datos.md §4: sustituir borra el objeto anterior y deja el nuevo; DELETE borra el objeto vivo y conserva la fila soft-deleted', function (): void {
    $c = photoScenario('ph-objetos');
    $id = $c['student']['public_id'];

    Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::jpeg(), 'a.jpg')->assertOk();
    $first = photoRows($c['tenant'])[0]->object_key;
    Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::png(), 'b.png')->assertOk();
    $second = photoRows($c['tenant'])[1]->object_key;

    expect(Storage::disk('local')->exists($first))->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([$second]);

    Alum::call($c['tenant'], $c['admin'], 'delete', "/students/{$id}/photo")->assertNoContent();

    $rows = photoRows($c['tenant']);
    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($rows)->toHaveCount(2)
        ->and($rows[1]->deleted_at)->not->toBeNull();
});

test('RN-ALUM-50 INV-012: si falla el borrado del objeto anterior, la sustitución y el DELETE siguen siendo correctos', function (): void {
    $c = photoScenario('ph-obj-fallo');
    $id = $c['student']['public_id'];

    $disk = Mockery::mock(Storage::disk('local'))->makePartial();
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('S3 caído'));
    Storage::set('flaky', $disk);
    config(['files.disk' => 'flaky']);

    Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::jpeg(), 'a.jpg')->assertOk();
    Alum::putPhoto($c['tenant'], $c['admin'], $id, ImageFixtures::png(), 'b.png')->assertOk();
    Alum::call($c['tenant'], $c['admin'], 'delete', "/students/{$id}/photo")->assertNoContent();

    $rows = photoRows($c['tenant']);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->deleted_at)->not->toBeNull()
        ->and($rows[1]->deleted_at)->not->toBeNull();
});

test('datos.md §6 INV-012: si falla el borrado del objeto al dar de baja al alumno, la baja y la purga de la fila siguen siendo correctas y el fallo se registra sin la clave del objeto', function (): void {
    $c = photoScenario('ph-baja-fallo');
    $id = $c['student']['public_id'];

    $disk = Mockery::mock(Storage::disk('local'))->makePartial();
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('S3 caído'));
    Storage::set('flaky', $disk);
    config(['files.disk' => 'flaky']);

    Alum::putPhoto($c['tenant'], $c['admin'], $id)->assertOk();
    $key = (string) Arr::first(Storage::disk('local')->allFiles());

    Log::spy();
    Alum::call($c['tenant'], $c['admin'], 'delete', "/students/{$id}")->assertNoContent();

    expect(photoRows($c['tenant']))->toBe([]);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'alum.photo.object_delete_failed'
            && $context === ['exception' => RuntimeException::class]
            && ! str_contains(json_encode($context), $key))
        ->once();
});
