<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Modules\FamUnit\Application\PickupListReader;
use App\Modules\FamUnit\Domain\AuthorizedPickupList;
use App\Modules\FamUnit\Domain\FamilyMembership;
use App\Modules\FamUnit\Domain\PickupBasis;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-005 (1.14b-a), docs/modulos/REQ-FAM-UNIT/funcional.md §21.6, §21.8, ADR-032, ADR-068 §5.5: la lista maestra
// calculada y su interfaz pública. CA-FAM-UNIT-111 a -117, -125, -129 y CA-068-04. Sin tabla de lista: se calcula en cada
// consulta con el reloj del servidor.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** La interfaz pública, dentro del contexto del centro. */
function pickupList(object $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->runFor($tenant->id, fn () => $callback(app(AuthorizedPickupList::class)));
}

/** @return array{0: string, 1: string} persona y autorización de una puntual para hoy con teléfono */
function pickupPunctual(array $f, int $doc, string $date, array $overrides = [], bool $photo = true): array
{
    $third = Fam::thirdParty($f, $doc, $photo, $overrides + [
        'kind' => 'puntual', 'valid_from' => $date, 'valid_until' => $date, 'reason' => 'Cita médica', 'channel' => 'telefono', 'relationship' => 'otra_familia',
    ], 'Vecina');

    return [$third['person'], $third['authorization']];
}

test('CA-FAM-UNIT-111 RN-FAM-UNIT-79: la lista de un alumno contiene a la madre y al padre (base tutor), al abuelo (tercero) y a la vecina (puntual), una vez cada uno', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-111');
    $abuelo = Fam::thirdParty($f, 1, given: 'Abuelo');
    [$vecina] = pickupPunctual($f, 2, '2026-11-02');

    $list = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
    $entries = collect($list->json('data'));

    expect($entries)->toHaveCount(4)
        ->and($entries->pluck('person')->unique())->toHaveCount(4)
        ->and($entries->pluck('person')->all())->toContain($abuelo['person'], $vecina)
        ->and($entries->flatMap(fn ($e) => collect($e['bases'])->pluck('basis'))->sort()->values()->all())->toBe(['puntual', 'tercero', 'tutor', 'tutor']);

    // Tutores primero, después terceros y puntuales.
    expect(collect($list->json('data'))->map(fn ($e) => $e['bases'][0]['basis'])->all())->toBe(['tutor', 'tutor', 'tercero', 'puntual'])
        ->and($list->json('meta.as_of'))->toBe('2026-11-02T09:00:00Z');

    $tercero = $entries->firstWhere('person', $abuelo['person']);
    $puntual = $entries->firstWhere('person', $vecina);
    expect($tercero['bases'][0])->toMatchArray(['basis' => 'tercero', 'authorization' => $abuelo['authorization'], 'relationship' => 'abuelo', 'valid_until' => null])
        ->and($puntual['bases'][0])->toMatchArray(['basis' => 'puntual', 'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02', 'relationship' => 'otra_familia'])
        // Nunca el motivo de una puntual en la lista.
        ->and(json_encode($list->json()))->not->toContain('Cita médica')
        ->and(array_keys($tercero))->toBe(['person', 'display_name', 'bases', 'has_photo', 'photo_url', 'photo_url_expires_at', 'contact_phone', 'document']);
});

test('RN-FAM-UNIT-79: una persona con varias bases (tutor y tercero) aparece una sola vez con todas', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-bases');
    $tutorPersonPublicId = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['other']->person_id)->public_id);
    Fam::putPhoto($f['tenant'], $f['admin'], $tutorPersonPublicId)->assertOk();
    Fam::call($f['tenant'], $f['admin'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $tutorPersonPublicId, ['relationship' => 'otro']))->assertCreated();

    $entries = collect(Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk()->json('data'));
    $mine = $entries->firstWhere('person', $tutorPersonPublicId);

    expect($entries)->toHaveCount(2)
        ->and(array_column($mine['bases'], 'basis'))->toBe(['tutor', 'tercero']);
});

test('CA-FAM-UNIT-112 RN-FAM-UNIT-80 issue #473: con el vínculo (P, A) revocado, P no figura en la lista ni en isAuthorized — por ninguna base —, y para toda persona X, X ∈ lista(A) implica isAccessRevoked(X, A) = false', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-112');
    $abuelo = Fam::thirdParty($f, 1);
    // El padre (otro tutor) además es un tercero autorizado por la madre: dos bases, y se revoca el vínculo.
    $padre = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['other']->person_id));
    Fam::putPhoto($f['tenant'], $f['admin'], $padre->public_id)->assertOk();
    Fam::call($f['tenant'], $f['admin'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $padre->public_id))->assertCreated();

    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->toContain($padre->public_id);
    expect(pickupList($f['tenant'], fn ($list) => $list->isAuthorized($padre->id, $f['student']->id)))->toBeTrue();

    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lo']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($padre->public_id)->toContain($abuelo['person'])
        ->and(pickupList($f['tenant'], fn ($list) => $list->isAuthorized($padre->id, $f['student']->id)))->toBeFalse();

    // Test de equivalencia: toda persona del centro que figura en la lista no está revocada según FamilyMembership.
    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $membership = app(FamilyMembership::class);
        $listed = array_map(fn ($e) => $e->personId, app(AuthorizedPickupList::class)->entriesFor($f['student']->id, AuthorizedPickupList::PURPOSE_MANAGEMENT));
        $everyone = Person::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        expect($listed)->not->toBeEmpty();

        foreach ($everyone as $personId) {
            if (in_array($personId, $listed, true)) {
                expect($membership->isAccessRevoked($personId, $f['student']->id))->toBeFalse("persona {$personId} figura y está revocada");
            }
        }

        // Y a la inversa: la revocada es justo la que no figura aunque tenga una autorización viva con foto.
        expect($membership->isAccessRevoked($f['other']->person_id, $f['student']->id))->toBeTrue()
            ->and($listed)->not->toContain((int) $f['other']->person_id);
    });
});

test('CA-FAM-UNIT-113 RN-FAM-UNIT-86: con P revocado sobre A y una autorización creada antes de la revocación, P no figura; crear ahora otra autorización de P (o del propio alumno) es 422 person_not_eligible con el mismo cuerpo', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-113');
    $padre = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['other']->person_id));
    Fam::putPhoto($f['tenant'], $f['admin'], $padre->public_id)->assertOk();
    $created = Fam::call($f['tenant'], $f['admin'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $padre->public_id))->assertCreated();

    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lo']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    // La autorización sigue en base de datos, pero la persona no figura.
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($padre->public_id);

    $revoked = Fam::call($f['tenant'], $f['admin'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $padre->public_id, ['relationship' => 'otro']))->assertStatus(422);
    $self = Fam::call($f['tenant'], $f['admin'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $f['student']->public_id))->assertStatus(422);

    expect(Fam::errorCode($revoked, 'person'))->toBe('fam_unit.pickup.person_not_eligible')
        ->and(Fam::errorCode($self, 'person'))->toBe('fam_unit.pickup.person_not_eligible')
        // Mismo cuerpo: no revela una resolución judicial.
        ->and($revoked->json('errors'))->toBe($self->json('errors'))
        ->and($revoked->json('detail'))->toBe($self->json('detail'))
        ->and($created->json('is_effective'))->toBeTrue();

    // La autorización previa, vista por quien no tiene revocacion_tutor.leer, solo es «no vigente».
    $plain = Fam::userWith($f['tenant'], $f['admin'], 'sinrevoc', ['autorizado_recogida.leer' => 'todos']);
    $seen = Fam::call($f['tenant'], $plain, 'get', "/pickup-authorizations/{$created->json('public_id')}")->assertOk();
    $admin = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-authorizations/{$created->json('public_id')}")->assertOk();
    expect($seen->json('ineffective_reason'))->toBe('no_vigente')->and($admin->json('ineffective_reason'))->toBe('persona_excluida');
});

test('CA-FAM-UNIT-114 RN-FAM-UNIT-80: P revocado sobre A1 y activo sobre A2 figura en la lista de A2 y no en la de A1', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-114');
    $a2 = Fam::person($f['tenant'], 'Hermano', 'Recogida');
    Fam::addStudent($f['tenant'], $f['admin'], $f['unit'], $a2)->assertCreated();
    Fam::link($f['tenant'], $f['admin'], $f['go'], $a2, ['relationship' => 'padre'])->assertCreated();
    $padre = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['other']->person_id));

    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lo']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($padre->public_id)
        ->and(Fam::listedPersons($f['tenant'], $f['admin'], $a2))->toContain($padre->public_id);
});

test('CA-FAM-UNIT-115 RN-FAM-UNIT-81: una retirada, una revocación y un levantamiento en la petición N se ven en la N+1, sin caché', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-115');
    $abuelo = Fam::thirdParty($f, 1);
    $padre = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['other']->person_id));
    $list = fn () => Fam::listedPersons($f['tenant'], $f['admin'], $f['student']);

    expect($list())->toContain($abuelo['person'], $padre->public_id);

    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$abuelo['authorization']}/withdrawal")->assertOk();
    expect($list())->not->toContain($abuelo['person'])->toContain($padre->public_id);

    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lo']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
    expect($list())->not->toContain($padre->public_id);

    Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$f['lo']}/revocation")->assertOk();
    expect($list())->toContain($padre->public_id);

    // Y la interfaz pública, dentro de la misma petición de PHP, tampoco memoiza entre llamadas.
    pickupList($f['tenant'], function (AuthorizedPickupList $pickup) use ($f, $padre): void {
        expect($pickup->isAuthorized($padre->id, $f['student']->id))->toBeTrue();
        DB::table('guardian_student_links')->where('public_id', $f['lo'])->update(['status' => 'revocado', 'revocation_reason' => 'resolucion_judicial', 'revoked_at' => now()]);
        expect($pickup->isAuthorized($padre->id, $f['student']->id))->toBeFalse();
    });
});

test('CA-FAM-UNIT-116 RN-FAM-UNIT-82: una puntual para el día D en Europe/Madrid figura a las 23:59 y deja de figurar a las 00:01, sin tarea programada', function (): void {
    // 2026-11-02 en Madrid es CET (UTC+1): 23:59:30 hora local = 22:59:30 UTC.
    Fam::freeze('2026-11-02 22:59:30');
    $f = Fam::pickupFamily('pl-116');
    [$vecina] = pickupPunctual($f, 3, '2026-11-02');

    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->toContain($vecina);

    // 00:01 del 3 de noviembre hora del centro = 23:01 UTC del día 2.
    test()->travelTo(CarbonImmutable::parse('2026-11-02 23:01:00'));
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($vecina);

    // Una puntual de una fecha futura no figura todavía, y figura el propio día (aquí, el 4 a las 07:00 en Madrid).
    [$mañana] = pickupPunctual($f, 4, '2026-11-04', ['relationship' => 'otro']);
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($mañana);
    test()->travelTo(CarbonImmutable::parse('2026-11-04 06:00:00'));
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->toContain($mañana);
});

test('RN-FAM-UNIT-82: una permanente figura entre valid_from y valid_until (ambos incluidos, fechas civiles del centro), no antes ni después', function (): void {
    // La familia se crea con el reloj ya fijado (el plazo de gracia del MFA del administrador corre desde su alta).
    Fam::freeze('2026-11-09 12:00:00');
    $f = Fam::pickupFamily('pl-vigencia');
    $window = Fam::thirdParty($f, 5, overrides: ['valid_from' => '2026-11-10', 'valid_until' => '2026-11-12'])['person'];

    // Instantes UTC y su fecha civil en Madrid (CET, UTC+1): el 9 a las 13:00, el 10 a la 01:30, el 12 a las 23:30 y el 13 a las 00:30.
    foreach (['2026-11-09 12:00:00' => false, '2026-11-10 00:30:00' => true, '2026-11-12 22:30:00' => true, '2026-11-12 23:30:00' => false, '2026-11-13 12:00:00' => false] as $now => $expected) {
        Fam::freeze($now);
        expect(in_array($window, Fam::listedPersons($f['tenant'], $f['admin'], $f['student']), true))->toBe($expected, $now);
    }
});

test('CA-FAM-UNIT-117 RN-FAM-UNIT-84: un tercero sin fotografía no figura (su autorización no es vigente: sin_foto) y un tutor sin fotografía figura con has_photo = false', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-117');
    $sinFoto = Fam::thirdParty($f, 6, photo: false);

    $list = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
    expect(array_column($list->json('data'), 'person'))->not->toContain($sinFoto['person']);

    $authorization = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-authorizations/{$sinFoto['authorization']}")->assertOk();
    expect($authorization->json('is_effective'))->toBeFalse()->and($authorization->json('ineffective_reason'))->toBe('sin_foto');

    // Al subir la fotografía pasa a figurar; al borrarla, vuelve a no figurar.
    Fam::putPhoto($f['tenant'], $f['admin'], $sinFoto['person'])->assertOk();
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->toContain($sinFoto['person']);
    Fam::call($f['tenant'], $f['admin'], 'delete', "/pickup-persons/{$sinFoto['person']}/photo")->assertNoContent();
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($sinFoto['person']);

    // Los tutores (sin fotografía) figuran con has_photo = false y sin URL.
    $tutors = collect($list->json('data'))->filter(fn ($e) => $e['bases'][0]['basis'] === 'tutor');
    expect($tutors)->toHaveCount(2)
        ->and($tutors->pluck('has_photo')->unique()->all())->toBe([false])
        ->and($tutors->pluck('photo_url')->unique()->all())->toBe([null]);
});

test('CA-FAM-UNIT-129 RN-FAM-UNIT-13: un tutor con vínculo activo cuyo usuario se dio de baja en Core sigue figurando; un vínculo dado de baja (no revocado) no figura', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-129');
    $padre = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['other']->person_id));

    app(TenantContext::class)->runFor($f['tenant']->id, fn () => $f['other']->fresh()->delete());
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->toContain($padre->public_id);

    Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$f['lo']}")->assertNoContent();
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($padre->public_id);
});

test('funcional.md §21.9: una persona de tercero borrada en Core deja de figurar', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-borrada');
    $abuelo = Fam::thirdParty($f, 7);
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->toContain($abuelo['person']);

    app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->where('public_id', $abuelo['person'])->firstOrFail()->delete());
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($abuelo['person']);
});

test('CA-FAM-UNIT-122 RN-FAM-UNIT-83 RN-FAM-UNIT-92: una lectura con fotografía firma una URL que caduca a los N minutos y cambia en cada respuesta, y escribe UNA fila read sin nombre, documento, teléfono ni clave de objeto', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-122');
    $abuelo = Fam::thirdParty($f, 8);
    $readsBefore = app(TenantContext::class)->runFor($f['tenant']->id, fn () => AuditLog::query()->where('event', 'read')->count());

    $first = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
    $entry = collect($first->json('data'))->firstWhere('person', $abuelo['person']);

    expect($entry['has_photo'])->toBeTrue()
        ->and($entry['photo_url'])->toBeString()->not->toBe('')
        ->and($entry['photo_url_expires_at'])->toBe('2026-11-02T09:05:00Z')
        // La URL no contiene el nombre ni los datos de la persona, y la clave del objeto es un ULID.
        ->and($entry['photo_url'])->not->toContain('Abuelo')->not->toContain(Fam::dni(8))
        ->and($entry['photo_url'])->toMatch('#tenants/'.$f['tenant']->public_id.'/fam-unit/pickup-photos/[0-9A-HJKMNP-TV-Z]{26}\.jpg#')
        ->and(json_encode($first->json()))->not->toContain('object_key')->not->toContain('mime_type');

    $reads = app(TenantContext::class)->runFor($f['tenant']->id, fn () => AuditLog::query()->where('event', 'read')->orderBy('id')->get());
    $read = $reads->last();
    $dump = json_encode($read->toArray());

    // UNA fila por consulta de la lista.
    expect($reads)->toHaveCount($readsBefore + 1)
        ->and($read->auditable_type)->toBe('person')
        ->and($read->auditable_public_id)->toBe($f['student']->public_id)
        ->and($read->changes)->toBeNull()
        ->and($read->context)->toEqual(['purpose' => 'gestion', 'entries' => 3, 'photos' => 1])
        ->and($read->actor_user_id)->toBe((int) $f['admin']->id)
        ->and($dump)->not->toContain('Abuelo')->not->toContain(Fam::dni(8))->not->toContain('600000000')->not->toContain('pickup-photos')->not->toContain('.jpg');

    // La URL cambia en cada respuesta (la caducidad va dentro de la firma).
    Fam::tick();
    $second = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
    expect(collect($second->json('data'))->firstWhere('person', $abuelo['person'])['photo_url'])->not->toBe($entry['photo_url']);

    // Y la caducidad configurable.
    config(['fam_unit.pickup.photo.signed_url_ttl_minutes' => 2]);
    $short = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
    expect(collect($short->json('data'))->firstWhere('person', $abuelo['person'])['photo_url_expires_at'])->toBe('2026-11-02T09:02:02Z');
});

test('RN-FAM-UNIT-90: el documento sale enmascarado (solo los 4 últimos caracteres) y el teléfono de Core, en la lista', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-90');
    $abuelo = Fam::thirdParty($f, 12345678);

    $entry = collect(Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk()->json('data'))->firstWhere('person', $abuelo['person']);

    expect($entry['document'])->toBe(['type' => 'dni', 'masked' => '•••••'.substr(Fam::dni(12345678), -4)])
        ->and($entry['contact_phone'])->toBe('600000000')
        ->and(json_encode($entry))->not->toContain(Fam::dni(12345678));

    expect(PickupListReader::mask('12345678Z'))->toBe('•••••678Z')
        ->and(PickupListReader::mask('AB12'))->toBe('••••')
        ->and(PickupListReader::mask('X1234'))->toBe('•1234')
        ->and(PickupListReader::mask(''))->toBeNull()
        ->and(PickupListReader::mask(null))->toBeNull();
});

test('CA-FAM-UNIT-125 funcional.md §21.8 punto 4: con un alumno o una persona inexistentes, de otro centro, borrados, o con fam_unit descontratado, la interfaz da lista vacía e isAuthorized = false', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $a = Fam::pickupFamily('pl-125a');
    $b = Fam::pickupFamily('pl-125b');
    $abuelo = Fam::thirdParty($a, 9);
    $abueloId = app(TenantContext::class)->runFor($a['tenant']->id, fn () => Person::query()->where('public_id', $abuelo['person'])->firstOrFail()->id);

    $inA = fn (Closure $callback) => pickupList($a['tenant'], $callback);

    expect($inA(fn ($l) => $l->isAuthorized($abueloId, $a['student']->id)))->toBeTrue()
        // Alumno inexistente, y alumno de otro centro.
        ->and($inA(fn ($l) => $l->isAuthorized($abueloId, 999999999)))->toBeFalse()
        ->and($inA(fn ($l) => $l->isAuthorized($abueloId, $b['student']->id)))->toBeFalse()
        ->and($inA(fn ($l) => $l->entriesFor(999999999, 'puerta')))->toBe([])
        ->and($inA(fn ($l) => $l->entriesFor($b['student']->id, 'puerta')))->toBe([])
        // Persona inexistente o de otro centro.
        ->and($inA(fn ($l) => $l->isAuthorized(999999999, $a['student']->id)))->toBeFalse()
        ->and($inA(fn ($l) => $l->isAuthorized($b['student']->id, $a['student']->id)))->toBeFalse()
        ->and($inA(fn ($l) => $l->authorizedAmong($a['student']->id, [$abueloId, 999999999, $b['student']->id])))->toBe([$abueloId])
        ->and($inA(fn ($l) => $l->authorizedAmong($a['student']->id, [])))->toBe([]);

    // Con el módulo descontratado, fallo en cerrado.
    Fam::disableModule($a['tenant']);
    expect($inA(fn ($l) => $l->isAuthorized($abueloId, $a['student']->id)))->toBeFalse()
        ->and($inA(fn ($l) => $l->entriesFor($a['student']->id, 'puerta')))->toBe([])
        ->and($inA(fn ($l) => $l->authorizedAmong($a['student']->id, [$abueloId])))->toBe([])
        ->and($inA(fn ($l) => $l->studentsMatching('Alumno', 10)))->toBe([]);

    // Una finalidad desconocida es un error de programación, no un dato.
    Fam::setup('pl-125c');
    expect(fn () => $inA(fn ($l) => $l->entriesFor($a['student']->id, 'otra')))->toThrow(InvalidArgumentException::class);
});

test('ADR-068 §5.5: entriesFor devuelve las entradas del contrato — id de persona, bases, relación, caducidad, foto firmada, teléfono y documento enmascarado — y las audita con la finalidad pedida', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-contrato');
    $abuelo = Fam::thirdParty($f, 10, overrides: ['valid_until' => '2026-12-31']);

    $entries = pickupList($f['tenant'], fn ($l) => $l->entriesFor($f['student']->id, AuthorizedPickupList::PURPOSE_DOOR));
    $mine = collect($entries)->first(fn ($e) => $e->personPublicId === $abuelo['person']);

    expect($entries)->toHaveCount(3)
        ->and($mine->bases[0]->basis)->toBe(PickupBasis::THIRD_PARTY)
        ->and($mine->relationship())->toBe('abuelo')
        ->and($mine->validUntil())->toBe('2026-12-31')
        ->and($mine->hasPhoto)->toBeTrue()
        ->and($mine->photoUrl)->toBeString()
        ->and($mine->contactPhone)->toBe('600000000')
        ->and($mine->documentMasked)->toBe('•••••'.substr(Fam::dni(10), -4))
        ->and($mine->personId)->toBeInt();

    $read = app(TenantContext::class)->runFor($f['tenant']->id, fn () => AuditLog::query()->where('event', 'read')->latest('id')->first());
    expect($read->context)->toEqual(['purpose' => 'puerta', 'entries' => 3, 'photos' => 1]);

    // isAuthorized y authorizedAmong no se auditan (no devuelven datos personales).
    $before = app(TenantContext::class)->runFor($f['tenant']->id, fn () => AuditLog::query()->where('event', 'read')->count());
    pickupList($f['tenant'], function ($l) use ($mine, $f): void {
        $l->isAuthorized($mine->personId, $f['student']->id);
        $l->authorizedAmong($f['student']->id, [$mine->personId]);
    });
    expect(app(TenantContext::class)->runFor($f['tenant']->id, fn () => AuditLog::query()->where('event', 'read')->count()))->toBe($before);
});

test('CA-068-04 ADR-068 §5.4: si recordRead lanza dentro de entriesFor, la respuesta es un error y no contiene ninguna URL firmada — sin rastro no hay datos', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pl-068-04');
    Fam::thirdParty($f, 11);

    // La escritura de la fila `read` es lo único que se rompe (AuditRecorder es final: se rompe el modelo, no el registrador).
    // El oyente solo actúa mientras la bandera está activa, para no afectar a otros tests del proceso.
    $GLOBALS['pickup_audit_down'] = false;
    AuditLog::creating(function (AuditLog $log): void {
        if (($GLOBALS['pickup_audit_down'] ?? false) && $log->event === 'read') {
            throw new RuntimeException('auditoría caída');
        }
    });
    $GLOBALS['pickup_audit_down'] = true;

    $response = Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}");

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(500)
        ->and($response->getContent())->not->toContain('photo_url')->not->toContain('tenants/')->not->toContain('signature');

    try {
        // La interfaz pública tampoco entrega nada.
        expect(fn () => pickupList($f['tenant'], fn ($l) => $l->entriesFor($f['student']->id, 'puerta')))->toThrow(RuntimeException::class, 'auditoría caída');
    } finally {
        $GLOBALS['pickup_audit_down'] = false;
    }

    // Y restablecida la auditoría, vuelve a funcionar.
    Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
});

test('OPEN-068-06 ADR-068 §5.5: studentsMatching busca por nombre entre las personas con pertenencia viva a una unidad o algún vínculo vivo como alumno, con el código de su unidad', function (): void {
    $f = Fam::pickupFamily('pl-students', withText: false);
    $linkedOnly = Fam::person($f['tenant'], 'Alumna', 'SoloVinculo');
    Fam::link($f['tenant'], $f['admin'], $f['gp'], $linkedOnly)->assertCreated();
    Fam::person($f['tenant'], 'Alumno', 'SinNada');
    $unitCode = Fam::call($f['tenant'], $f['admin'], 'get', "/family-units/{$f['unit']}")->assertOk()->json('code');

    $found = pickupList($f['tenant'], fn ($l) => $l->studentsMatching('alum', 10));

    expect(array_map(fn ($m) => $m->displayName, $found))->toBe(['Alumna SoloVinculo', 'Alumno Recogida'])
        ->and(array_map(fn ($m) => $m->familyUnitCode, $found))->toBe([null, $unitCode])
        ->and($found[1]->studentPublicId)->toBe($f['student']->public_id)
        ->and($found[1]->studentPersonId)->toBe((int) $f['student']->id)
        ->and(pickupList($f['tenant'], fn ($l) => $l->studentsMatching('Alum', 1)))->toHaveCount(1)
        ->and(pickupList($f['tenant'], fn ($l) => $l->studentsMatching('SinNada', 10)))->toBe([]);
});
