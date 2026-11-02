<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\FamUnit\Domain\AuthorizedPickupList;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-005 (1.14b-a), docs/modulos/REQ-FAM-UNIT/api.md §8.1, §8.3, funcional.md §21.5, §21.6, ADR-068: autorizaciones
// de tercero y puntuales. CA-FAM-UNIT-118, -119, -120, -123, -124, -131, RN-FAM-UNIT-82, -85, -86, -87, -88, -91, OPEN-FAM-UNIT-35,
// INV-001, INV-002, INV-003, INV-010, INV-015.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Crea una autorización con el cuerpo válido de la familia y `$overrides`. */
function authorize(array $f, string $person, array $overrides = [], ?object $actor = null): TestResponse
{
    return Fam::call($f['tenant'], $actor ?? $f['admin'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $person, $overrides));
}

test('api.md §8.3 CA-FAM-UNIT-119 RN-FAM-UNIT-85: crear una autorización registra tutor que autoriza, versión del texto, canal, quién la registra y el instante, y responde 201 con Location y la forma de api.md §8.1', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-crea');
    $person = Fam::registerPerson($f['tenant'], $f['admin'], ['document_type' => 'dni', 'document_number' => Fam::dni(1)])->assertCreated()->json('person');

    $created = authorize($f, $person, ['valid_until' => '2027-06-30', 'authorized_at' => '2026-10-30T08:30:00Z'])->assertCreated();

    expect(array_keys($created->json()))->toBe(['public_id', 'student_person', 'person', 'relationship', 'kind', 'valid_from', 'valid_until', 'reason', 'authorized_by', 'terms_version', 'channel', 'authorized_at', 'recorded_by', 'is_effective', 'ineffective_reason', 'withdrawn_at', 'created_at'])
        ->and($created->json())->toMatchArray([
            'student_person' => $f['student']->public_id,
            'relationship' => 'abuelo', 'kind' => 'permanente', 'valid_from' => '2026-01-01', 'valid_until' => '2027-06-30', 'reason' => null,
            'authorized_by' => $f['gp'], 'channel' => 'presencial', 'authorized_at' => '2026-10-30T08:30:00.000000Z',
            'is_effective' => false, 'ineffective_reason' => 'sin_foto', 'withdrawn_at' => null,
        ])
        ->and($created->json('person.public_id'))->toBe($person)
        ->and($created->json('terms_version'))->toBe(['public_id' => $f['text'], 'kind' => 'autorizacion_recogida', 'version_number' => 1])
        ->and($created->json('recorded_by'))->toBe(Fam::call($f['tenant'], $f['admin'], 'get', '/me')->json('public_id'))
        ->and($created->headers->get('Location'))->toContain('/api/v1/pickup-authorizations/'.$created->json('public_id'))
        ->and(json_encode($created->json()))->not->toContain('ip_address')->not->toContain('user_agent');

    $row = Fam::row($f['tenant'], 'pickup_authorizations', $created->json('public_id'));
    expect($row->ip_address)->not->toBeNull()->and($row->user_agent)->not->toBeNull()->and($row->recorded_by)->not->toBeNull();

    // Se audita.
    expect(Fam::audit($f['tenant'], 'pickup_authorization', 'created'))->toHaveCount(1);
});

test('CA-FAM-UNIT-119 RN-FAM-UNIT-85: sin versión publicada del texto autorizacion_recogida, 422 text_not_published y no se escribe nada; una versión no publicada o de otro tipo es 422 text_version_invalid; una retirada se admite', function (): void {
    $f = Fam::pickupFamily('pa-119', withText: false);
    $person = Fam::registerPerson($f['tenant'], $f['admin'])->assertCreated()->json('person');
    $count = fn () => app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_authorizations')->count());

    $draft = Fam::call($f['tenant'], $f['admin'], 'post', '/terms-versions', ['kind' => 'autorizacion_recogida', 'title_translations' => ['es-ES' => 'T'], 'body_translations' => ['es-ES' => 'C']])->assertCreated()->json('public_id');

    expect(Fam::errorCode(authorize(array_merge($f, ['text' => $draft]), $person)->assertStatus(422), 'terms_version'))->toBe('fam_unit.pickup.text_not_published')
        ->and($count())->toBe(0);

    $first = $draft;
    Fam::call($f['tenant'], $f['admin'], 'post', "/terms-versions/{$first}/publish")->assertOk();
    $f['text'] = $first;

    // Un borrador, otro tipo (imagen) o un texto inexistente no valen.
    $anotherDraft = Fam::call($f['tenant'], $f['admin'], 'post', '/terms-versions', ['kind' => 'autorizacion_recogida', 'title_translations' => ['es-ES' => 'T2'], 'body_translations' => ['es-ES' => 'C2']])->assertCreated()->json('public_id');
    $image = Fam::publishText($f['tenant'], $f['admin'], 'web');

    foreach ([$anotherDraft, $image, '01JZZZZZZZZZZZZZZZZZZZZZZZ'] as $invalid) {
        expect(Fam::errorCode(authorize($f, $person, ['terms_version' => $invalid])->assertStatus(422), 'terms_version'))->toBe('fam_unit.pickup.text_version_invalid');
    }

    expect($count())->toBe(0);

    // Publicar la segunda retira la primera; la familia firmó la primera en papel: se admite la retirada (RN-FAM-UNIT-69).
    Fam::call($f['tenant'], $f['admin'], 'post', "/terms-versions/{$anotherDraft}/publish")->assertOk();
    $created = authorize($f, $person, ['terms_version' => $first])->assertCreated();
    expect($created->json('terms_version.public_id'))->toBe($first)->and($count())->toBe(1);
});

test('CA-FAM-UNIT-118 RN-FAM-UNIT-85: si el tutor indicado no es el principal, no tiene vínculo activo con representación legal sobre el alumno, o la unidad no tiene principal, 422 principal_required y no se escribe nada', function (): void {
    $f = Fam::pickupFamily('pa-118');
    $person = Fam::registerPerson($f['tenant'], $f['admin'])->assertCreated()->json('person');
    $count = fn () => app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_authorizations')->count());

    // El otro tutor no es el principal.
    $notPrincipal = authorize($f, $person, ['authorized_by' => $f['go']])->assertStatus(422);
    expect(Fam::errorCode($notPrincipal, 'authorized_by'))->toBe('fam_unit.pickup.principal_required');

    // Un tutor inexistente.
    expect(Fam::errorCode(authorize($f, $person, ['authorized_by' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertStatus(422), 'authorized_by'))->toBe('fam_unit.pickup.principal_required');

    // El principal sin representación legal sobre ESTE alumno: se le quita la marca.
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['lp']}", ['is_legal_guardian' => false])->assertOk();
    expect(Fam::errorCode(authorize($f, $person)->assertStatus(422), 'authorized_by'))->toBe('fam_unit.pickup.principal_required');
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['lp']}", ['is_legal_guardian' => true])->assertOk();
    authorize($f, $person)->assertCreated();
    expect($count())->toBe(1);

    // Unidad sin principal: nadie puede autorizar terceros.
    Fam::call($f['tenant'], $f['admin'], 'patch', "/family-units/{$f['unit']}", ['principal_guardian' => null])->assertOk();
    $other = Fam::registerPerson($f['tenant'], $f['admin'], ['given_name' => 'Otra'])->assertCreated()->json('person');
    foreach ([$f['gp'], $f['go']] as $guardian) {
        expect(Fam::errorCode(authorize($f, $other, ['authorized_by' => $guardian])->assertStatus(422), 'authorized_by'))->toBe('fam_unit.pickup.principal_required');
    }

    // Alumno sin unidad: el mismo error.
    $loose = Fam::person($f['tenant'], 'Alumno', 'SinUnidad');
    expect(Fam::errorCode(authorize($f, $other, ['student_person' => $loose->public_id])->assertStatus(422), 'authorized_by'))->toBe('fam_unit.pickup.principal_required')
        ->and($count())->toBe(1);

    // El principal con el vínculo revocado tampoco puede (no es un vínculo activo).
    Fam::call($f['tenant'], $f['admin'], 'patch', "/family-units/{$f['unit']}", ['principal_guardian' => $f['gp']])->assertOk();
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lp']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
    $afterRevocation = Fam::call($f['tenant'], $f['admin'], 'get', "/family-units/{$f['unit']}")->json('principal_guardian');
    expect($afterRevocation)->toBeNull();
});

test('CA-FAM-UNIT-131 OPEN-FAM-UNIT-35: una puntual por teléfono con motivo es 201 y queda el canal telefono; una permanente por teléfono es 422 channel_requires_puntual sin escribir nada; un canal desconocido es 422 channel_invalid', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-131');
    $person = Fam::registerPerson($f['tenant'], $f['admin'])->assertCreated()->json('person');
    $count = fn () => app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_authorizations')->count());

    $phone = authorize($f, $person, ['kind' => 'puntual', 'valid_from' => '2026-11-02', 'channel' => 'telefono', 'reason' => 'Avería del coche; devolución de llamada hecha', 'authorized_at' => '2026-11-02T08:55:00Z'])->assertCreated();
    expect($phone->json())->toMatchArray(['channel' => 'telefono', 'kind' => 'puntual', 'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02', 'reason' => 'Avería del coche; devolución de llamada hecha']);

    $before = $count();
    $permanent = authorize($f, $person, ['channel' => 'telefono'])->assertStatus(422);
    expect(Fam::errorCode($permanent, 'channel'))->toBe('fam_unit.pickup.channel_requires_puntual')
        ->and($count())->toBe($before)
        ->and(Fam::errorCode(authorize($f, $person, ['channel' => 'portal'])->assertStatus(422), 'channel'))->toBe('fam_unit.validation.channel_invalid')
        ->and(Fam::errorCode(authorize($f, $person, ['channel' => null])->assertStatus(422), 'channel'))->toBe('fam_unit.validation.required');

    // Un usuario de conserjería con el rol por defecto (sin autorizado_recogida.crear) ⇒ 403.
    $conserje = app(TenantContext::class)->runFor($f['tenant']->id, function (): User {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', 'conserjeria_pas')->firstOrFail()->id);

        return $user;
    });
    authorize($f, $person, ['kind' => 'puntual', 'valid_from' => '2026-11-02', 'channel' => 'telefono', 'reason' => 'x'], $conserje)->assertForbidden();
    expect($count())->toBe($before);
});

test('api.md §8.3 INV-010: la forma del cuerpo — referencias, vocabularios, fechas, motivo de una puntual, longitud y fecha futura de authorized_at — se valida con 422 y no escribe nada', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-forma');
    $person = Fam::registerPerson($f['tenant'], $f['admin'])->assertCreated()->json('person');
    $count = fn () => app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('pickup_authorizations')->count());

    $cases = [
        [['student_person' => null], 'student_person', 'fam_unit.validation.required'],
        [['person' => 'no-ulid'], 'person', 'fam_unit.validation.reference_invalid'],
        [['authorized_by' => null], 'authorized_by', 'fam_unit.validation.required'],
        [['terms_version' => 'no-ulid'], 'terms_version', 'fam_unit.validation.reference_invalid'],
        [['relationship' => 'vecino'], 'relationship', 'fam_unit.validation.pickup_relationship_invalid'],
        [['relationship' => null], 'relationship', 'fam_unit.validation.required'],
        [['kind' => 'anual'], 'kind', 'fam_unit.validation.pickup_kind_invalid'],
        [['valid_from' => '02/11/2026'], 'valid_from', 'fam_unit.validation.date_invalid'],
        [['valid_from' => '2026-02-30'], 'valid_from', 'fam_unit.validation.date_invalid'],
        [['valid_from' => null], 'valid_from', 'fam_unit.validation.required'],
        [['valid_until' => '2025-12-31'], 'valid_until', 'fam_unit.validation.date_range_invalid'],
        [['kind' => 'puntual', 'valid_until' => '2026-01-02', 'reason' => 'x'], 'valid_until', 'fam_unit.validation.date_range_invalid'],
        [['kind' => 'puntual', 'reason' => null], 'reason', 'fam_unit.validation.required'],
        [['kind' => 'puntual', 'reason' => '   '], 'reason', 'fam_unit.validation.required'],
        [['reason' => str_repeat('x', 501)], 'reason', 'fam_unit.validation.reason_too_long'],
        [['authorized_at' => 'ayer'], 'authorized_at', 'fam_unit.validation.datetime_invalid'],
        [['authorized_at' => '2026-11-03T09:00:00Z'], 'authorized_at', 'fam_unit.validation.authorized_at_future'],
        [['student_person' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'], 'student_person', 'fam_unit.validation.student_not_found'],
        [['person' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'], 'person', 'fam_unit.validation.person_not_found'],
    ];

    foreach ($cases as [$overrides, $field, $code]) {
        $response = authorize($f, $person, $overrides)->assertStatus(422);
        expect(Fam::errorCode($response, $field))->toBe($code, json_encode($overrides));
    }

    // Una puntual correcta con motivo de 500 caracteres.
    authorize($f, $person, ['kind' => 'puntual', 'valid_from' => '2026-11-02', 'reason' => str_repeat('x', 500)])->assertCreated();
    expect($count())->toBe(1);
});

test('RN-FAM-UNIT-88: una autorización idéntica a una viva es 422 already_exists; las puntuales de fechas distintas conviven; tras retirar la viva, se puede crear otra', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-88');
    $person = Fam::registerPerson($f['tenant'], $f['admin'])->assertCreated()->json('person');

    $first = authorize($f, $person)->assertCreated();
    expect(Fam::errorCode(authorize($f, $person, ['relationship' => 'otro'])->assertStatus(422), 'person'))->toBe('fam_unit.pickup.already_exists');

    $day1 = authorize($f, $person, ['kind' => 'puntual', 'valid_from' => '2026-11-02', 'reason' => 'a', 'channel' => 'telefono'])->assertCreated();
    expect(Fam::errorCode(authorize($f, $person, ['kind' => 'puntual', 'valid_from' => '2026-11-02', 'reason' => 'b'])->assertStatus(422), 'person'))->toBe('fam_unit.pickup.already_exists');
    authorize($f, $person, ['kind' => 'puntual', 'valid_from' => '2026-11-03', 'reason' => 'c'])->assertCreated();

    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$first->json('public_id')}/withdrawal")->assertOk();
    authorize($f, $person)->assertCreated();

    expect($day1->json('kind'))->toBe('puntual');
});

test('CA-FAM-UNIT-120 RN-FAM-UNIT-87: retirar deja de figurar en la petición siguiente, conserva la fila con withdrawn_at/withdrawn_by, se audita y una segunda retirada es 409 invalid_transition', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-120');
    $third = Fam::thirdParty($f, 2);
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->toContain($third['person']);

    Fam::tick();
    $withdrawn = Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$third['authorization']}/withdrawal")->assertOk();

    expect($withdrawn->json('withdrawn_at'))->not->toBeNull()
        ->and($withdrawn->json('is_effective'))->toBeFalse()
        ->and($withdrawn->json('ineffective_reason'))->toBe('retirada')
        ->and(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($third['person']);

    $row = Fam::row($f['tenant'], 'pickup_authorizations', $third['authorization']);
    expect($row->withdrawn_at)->not->toBeNull()->and($row->withdrawn_by)->not->toBeNull()->and($row->deleted_at)->toBeNull();

    $second = Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$third['authorization']}/withdrawal")->assertStatus(409);
    expect(Fam::errorCode($second, 'status'))->toBe('fam_unit.pickup.invalid_transition');

    $updates = collect(Fam::audit($f['tenant'], 'pickup_authorization', 'updated'));
    expect($updates)->toHaveCount(1)->and($updates->first()->changes)->toHaveKey('withdrawn_at');

    // Sin permiso eliminar, 403; el recurso inexistente, 404.
    $updater = Fam::userWith($f['tenant'], $f['admin'], 'actualiza', ['autorizado_recogida.actualizar' => 'todos', 'autorizado_recogida.leer' => 'todos']);
    Fam::call($f['tenant'], $updater, 'post', "/pickup-authorizations/{$third['authorization']}/withdrawal")->assertForbidden();
    Fam::call($f['tenant'], $f['admin'], 'post', '/pickup-authorizations/01JZZZZZZZZZZZZZZZZZZZZZZZ/withdrawal')->assertNotFound();
});

test('api.md §8.3: PATCH cambia solo valid_until de una permanente (adelantar o quitar la caducidad), 409 withdrawn si está retirada, 422 not_editable para kind, person, student_person, authorized_by y una puntual, y se audita', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-patch');
    $third = Fam::thirdParty($f, 3, overrides: ['valid_until' => '2027-12-31']);
    $path = "/pickup-authorizations/{$third['authorization']}";

    expect(Fam::call($f['tenant'], $f['admin'], 'patch', $path, ['valid_until' => '2026-12-31'])->assertOk()->json('valid_until'))->toBe('2026-12-31')
        ->and(Fam::call($f['tenant'], $f['admin'], 'patch', $path, ['valid_until' => null])->assertOk()->json('valid_until'))->toBeNull();

    // Adelantarla a ayer la deja fuera de vigencia (fallo en cerrado), y la lista ya no la incluye.
    Fam::call($f['tenant'], $f['admin'], 'patch', $path, ['valid_until' => '2026-11-01'])->assertOk();
    expect(Fam::listedPersons($f['tenant'], $f['admin'], $f['student']))->not->toContain($third['person'])
        ->and(Fam::call($f['tenant'], $f['admin'], 'get', $path)->json('ineffective_reason'))->toBe('fuera_de_vigencia');

    // Antes del inicio de vigencia, 422; sin el campo, 422; con campos no editables, 422 not_editable.
    expect(Fam::errorCode(Fam::call($f['tenant'], $f['admin'], 'patch', $path, ['valid_until' => '2025-12-31'])->assertStatus(422), 'valid_until'))->toBe('fam_unit.validation.date_range_invalid')
        ->and(Fam::errorCode(Fam::call($f['tenant'], $f['admin'], 'patch', $path, [])->assertStatus(422), 'valid_until'))->toBe('fam_unit.validation.required')
        ->and(Fam::errorCode(Fam::call($f['tenant'], $f['admin'], 'patch', $path, ['valid_until' => 'mañana'])->assertStatus(422), 'valid_until'))->toBe('fam_unit.validation.date_invalid');

    foreach (['kind' => 'puntual', 'person' => $third['person'], 'student_person' => $f['student']->public_id, 'authorized_by' => $f['go']] as $field => $value) {
        $response = Fam::call($f['tenant'], $f['admin'], 'patch', $path, ['valid_until' => null, $field => $value])->assertStatus(422);
        expect(Fam::errorCode($response, $field))->toBe('fam_unit.pickup.not_editable', $field);
    }

    // Una puntual no se edita; una retirada tampoco (409).
    $punctual = Fam::call($f['tenant'], $f['admin'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $third['person'], ['kind' => 'puntual', 'valid_from' => '2026-11-02', 'reason' => 'x']))->assertCreated()->json('public_id');
    expect(Fam::errorCode(Fam::call($f['tenant'], $f['admin'], 'patch', "/pickup-authorizations/{$punctual}", ['valid_until' => '2026-11-02'])->assertStatus(422), 'valid_until'))->toBe('fam_unit.pickup.not_editable');

    Fam::call($f['tenant'], $f['admin'], 'post', "{$path}/withdrawal")->assertOk();
    expect(Fam::errorCode(Fam::call($f['tenant'], $f['admin'], 'patch', $path, ['valid_until' => null])->assertStatus(409), 'status'))->toBe('fam_unit.pickup.withdrawn');

    // Cambio de vigencia auditado.
    expect(collect(Fam::audit($f['tenant'], 'pickup_authorization', 'updated'))->filter(fn ($l) => isset($l->changes['valid_until']))->count())->toBe(3);
});

test('api.md §8.3: el listado filtra por alumno, persona, tipo y vigencia, ordena por valid_from y created_at, y is_effective coincide con que la persona figure en la lista', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-lista');
    $a = Fam::thirdParty($f, 4, overrides: ['valid_from' => '2026-03-01']);
    $b = Fam::thirdParty($f, 5, photo: false, overrides: ['valid_from' => '2026-05-01']);
    $c = Fam::thirdParty($f, 6, overrides: ['valid_from' => '2026-02-01', 'valid_until' => '2026-10-01']);
    $d = Fam::thirdParty($f, 7, overrides: ['kind' => 'puntual', 'valid_from' => '2026-11-02', 'reason' => 'x', 'channel' => 'telefono']);
    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$d['authorization']}/withdrawal")->assertOk();

    $all = Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-authorizations')->assertOk();
    expect($all->json('meta'))->toMatchArray(['current_page' => 1, 'per_page' => 25, 'total' => 4, 'last_page' => 1])
        // Por defecto, valid_from descendente.
        ->and(array_column($all->json('data'), 'valid_from'))->toBe(['2026-11-02', '2026-05-01', '2026-03-01', '2026-02-01']);

    $reasons = collect($all->json('data'))->mapWithKeys(fn ($row) => [$row['public_id'] => $row['ineffective_reason']]);
    expect($reasons[$a['authorization']])->toBeNull()
        ->and($reasons[$b['authorization']])->toBe('sin_foto')
        ->and($reasons[$c['authorization']])->toBe('fuera_de_vigencia')
        ->and($reasons[$d['authorization']])->toBe('retirada');

    $ids = fn (string $query) => collect(Fam::call($f['tenant'], $f['admin'], 'get', "/pickup-authorizations{$query}")->assertOk()->json('data'))->pluck('public_id')->all();
    $listed = Fam::listedPersons($f['tenant'], $f['admin'], $f['student']);

    expect($ids('?effective=true'))->toBe([$a['authorization']])
        ->and($ids('?effective=false'))->toHaveCount(3)->not->toContain($a['authorization'])
        ->and($ids('?kind=puntual'))->toBe([$d['authorization']])
        ->and($ids("?person={$a['person']},{$c['person']}&sort=valid_from"))->toBe([$c['authorization'], $a['authorization']])
        ->and($ids("?student_person={$f['student']->public_id}"))->toHaveCount(4)
        ->and($ids('?student_person=01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBe([])
        ->and($ids('?sort=-created_at'))->toBe([$d['authorization'], $c['authorization'], $b['authorization'], $a['authorization']])
        // is_effective ⇔ la persona figura en la lista calculada.
        ->and($listed)->toContain($a['person'])->not->toContain($b['person'], $c['person'], $d['person']);

    Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-authorizations?sort=nombre')->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-authorizations?kind=anual')->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-authorizations?per_page=101')->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'get', '/pickup-authorizations/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

test('api.md §8.1 RN-FAM-UNIT-90: reason solo con autorizado_recogida.leer de ámbito todos', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-reason');
    $third = Fam::thirdParty($f, 8, overrides: ['kind' => 'puntual', 'valid_from' => '2026-11-02', 'reason' => 'Cita del especialista', 'channel' => 'telefono']);
    $path = "/pickup-authorizations/{$third['authorization']}";

    expect(Fam::call($f['tenant'], $f['admin'], 'get', $path)->assertOk()->json('reason'))->toBe('Cita del especialista');

    // El tutor con unidad_familiar ve la autorización de su alumno, sin motivo.
    $family = Fam::call($f['tenant'], $f['principal'], 'get', $path)->assertOk();
    expect($family->json())->not->toHaveKey('reason')
        // En la auditoría, el motivo (texto libre) se redacta: consta que existe, nunca su contenido.
        ->and(Fam::audit($f['tenant'], 'pickup_authorization', 'created')[0]->changes['reason'])->toHaveKey('redacted')
        ->and(json_encode(Fam::audit($f['tenant'], 'pickup_authorization', 'created')[0]->changes))->not->toContain('Cita del especialista');
});

test('CA-FAM-UNIT-123 RN-FAM-UNIT-91: un tutor con ámbito unidad_familiar lee la lista de su hijo sin documento de terceros ni fotografía, y sus autorizaciones; la de otro alumno, o la de su hijo estando él revocado, es 404 (listado y detalle)', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-123');
    $third = Fam::thirdParty($f, 9);
    $stranger = Fam::pickupFamily('pa-123x');
    $strangerStudent = Fam::person($f['tenant'], 'Alumno', 'Ajeno');

    $list = Fam::call($f['tenant'], $f['principal'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
    $entries = collect($list->json('data'));
    $mine = $entries->firstWhere('person', $third['person']);
    $tutor = $entries->first(fn ($e) => $e['bases'][0]['basis'] === 'tutor');

    expect($entries)->toHaveCount(3)
        // Sin el documento del tercero ni la URL de su fotografía (OPEN-FAM-UNIT-43 abierta); el tutor sí lleva el suyo (enmascarado).
        ->and($mine['document'])->toBeNull()
        ->and($mine['photo_url'])->toBeNull()
        ->and($mine['has_photo'])->toBeTrue()
        ->and($mine['display_name'])->toBe('Abuelo Prueba')
        ->and(json_encode($list->json()))->not->toContain(Fam::dni(9))->not->toContain('tenants/');

    // La autorización del tercero, en el listado y en el detalle.
    $listing = Fam::call($f['tenant'], $f['principal'], 'get', '/pickup-authorizations')->assertOk();
    expect(array_column($listing->json('data'), 'public_id'))->toBe([$third['authorization']]);
    Fam::call($f['tenant'], $f['principal'], 'get', "/pickup-authorizations/{$third['authorization']}")->assertOk();

    // El alumno de otra familia, y un alumno inexistente: 404 en la lista; sin autorizaciones en el listado ni el detalle.
    Fam::call($f['tenant'], $f['principal'], 'get', "/pickup-lists/{$strangerStudent->public_id}")->assertNotFound();
    Fam::call($f['tenant'], $f['principal'], 'get', '/pickup-lists/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    $foreignThird = Fam::thirdParty($stranger, 10);
    Fam::call($f['tenant'], $f['principal'], 'get', "/pickup-authorizations/{$foreignThird['authorization']}")->assertNotFound();
    expect(Fam::call($f['tenant'], $f['principal'], 'get', "/pickup-authorizations?student_person={$strangerStudent->public_id}")->assertOk()->json('data'))->toBe([]);

    // El tutor revocado sobre su hijo ya no lo alcanza: 404 y nada en el listado, sin distinguirse de un inexistente.
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lp']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
    Fam::call($f['tenant'], $f['principal'], 'get', "/pickup-lists/{$f['student']->public_id}")->assertNotFound();
    Fam::call($f['tenant'], $f['principal'], 'get', "/pickup-authorizations/{$third['authorization']}")->assertNotFound();
    expect(Fam::call($f['tenant'], $f['principal'], 'get', '/pickup-authorizations')->assertOk()->json('data'))->toBe([]);

    // Y la familia no escribe (llega con 1.22).
    Fam::call($f['tenant'], $f['other'], 'post', '/pickup-authorizations', Fam::authorizationBody($f, $third['person'], ['relationship' => 'otro']))->assertForbidden();
    Fam::call($f['tenant'], $f['other'], 'post', "/pickup-authorizations/{$third['authorization']}/withdrawal")->assertForbidden();
    expect($tutor)->not->toBeNull();
});

test('INV-002 permisos.md §8: las rutas de autorizaciones y de la lista deniegan por defecto — sin permiso 403, cada permiso solo su acción, un deny de otro rol gana', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-perm');
    $third = Fam::thirdParty($f, 11);
    $path = "/pickup-authorizations/{$third['authorization']}";
    $nobody = Fam::userWith($f['tenant'], $f['admin'], 'nadie', ['familia.leer' => 'todos']);
    $reader = Fam::userWith($f['tenant'], $f['admin'], 'lector', ['autorizado_recogida.leer' => 'todos']);
    $denied = Fam::userWithRoles($f['tenant'], $f['admin'], 'denegado', [
        'allow' => ['autorizado_recogida.leer' => ['allow', 'todos']],
        'deny' => ['autorizado_recogida.leer' => ['deny', 'todos']],
    ]);

    foreach ([['get', '/pickup-authorizations'], ['post', '/pickup-authorizations'], ['get', $path], ['patch', $path], ['post', "{$path}/withdrawal"], ['get', "/pickup-lists/{$f['student']->public_id}"]] as [$method, $uri]) {
        expect(Fam::call($f['tenant'], $nobody, $method, $uri, [])->getStatusCode())->toBe(403, "nadie {$method} {$uri}")
            ->and(Fam::call($f['tenant'], $denied, $method, $uri, [])->getStatusCode())->toBe(403, "denegado {$method} {$uri}");
    }

    // Solo leer: lee, no escribe.
    Fam::call($f['tenant'], $reader, 'get', $path)->assertOk();
    Fam::call($f['tenant'], $reader, 'get', "/pickup-lists/{$f['student']->public_id}")->assertOk();
    foreach ([['post', '/pickup-authorizations'], ['patch', $path], ['post', "{$path}/withdrawal"]] as [$method, $uri]) {
        expect(Fam::call($f['tenant'], $reader, $method, $uri, [])->getStatusCode())->toBe(403, "lector {$method} {$uri}");
    }

    // Sin sesión, 401.
    resetSessionState();
    test()->getJson(Fam::url($f['tenant'], '/pickup-authorizations'))->assertUnauthorized();
});

test('INV-001 CA-FAM-UNIT-124: dos centros con familias, terceros, fotos y autorizaciones equivalentes — desde A nada de B se devuelve, modifica ni cuenta', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $a = Fam::pickupFamily('pa-iso-a');
    $b = Fam::pickupFamily('pa-iso-b');
    $thirdA = Fam::thirdParty($a, 12);
    $thirdB = Fam::thirdParty($b, 12);
    $before = app(TenantContext::class)->runFor($b['tenant']->id, fn () => [DB::table('pickup_authorizations')->count(), DB::table('pickup_photos')->count(), DB::table('people')->count()]);
    $readsB = app(TenantContext::class)->runFor($b['tenant']->id, fn () => AuditLog::query()->where('event', 'read')->count());

    // Listado y detalle.
    $listing = Fam::call($a['tenant'], $a['admin'], 'get', '/pickup-authorizations')->assertOk();
    expect(array_column($listing->json('data'), 'public_id'))->toBe([$thirdA['authorization']])
        ->and($listing->json('meta.total'))->toBe(1);
    Fam::call($a['tenant'], $a['admin'], 'get', "/pickup-authorizations/{$thirdB['authorization']}")->assertNotFound();
    Fam::call($a['tenant'], $a['admin'], 'patch', "/pickup-authorizations/{$thirdB['authorization']}", ['valid_until' => '2026-11-01'])->assertNotFound();
    Fam::call($a['tenant'], $a['admin'], 'post', "/pickup-authorizations/{$thirdB['authorization']}/withdrawal")->assertNotFound();

    // La lista de un alumno de B y la creación contra B.
    Fam::call($a['tenant'], $a['admin'], 'get', "/pickup-lists/{$b['student']->public_id}")->assertNotFound();
    expect(Fam::errorCode(authorize($a, $thirdB['person'], ['student_person' => $b['student']->public_id])->assertStatus(422), 'student_person'))->toBe('fam_unit.validation.student_not_found')
        ->and(Fam::errorCode(authorize($a, $thirdB['person'])->assertStatus(422), 'person'))->toBe('fam_unit.validation.person_not_found')
        ->and(Fam::errorCode(authorize($a, $thirdA['person'], ['authorized_by' => $b['gp']])->assertStatus(422), 'authorized_by'))->toBe('fam_unit.pickup.principal_required')
        ->and(Fam::errorCode(authorize($a, $thirdA['person'], ['relationship' => 'otro', 'kind' => 'puntual', 'valid_from' => '2026-11-02', 'reason' => 'x', 'terms_version' => $b['text']])->assertStatus(422), 'terms_version'))->toBe('fam_unit.pickup.text_version_invalid');

    // Lo de B no cambió y los dos centros listan solo lo suyo.
    expect(app(TenantContext::class)->runFor($b['tenant']->id, fn () => [DB::table('pickup_authorizations')->count(), DB::table('pickup_photos')->count(), DB::table('people')->count()]))->toBe($before)
        ->and(Fam::listedPersons($a['tenant'], $a['admin'], $a['student']))->toContain($thirdA['person'])->not->toContain($thirdB['person'])
        ->and(Fam::listedPersons($b['tenant'], $b['admin'], $b['student']))->toContain($thirdB['person'])->not->toContain($thirdA['person']);

    // Y la interfaz pública desde A tampoco ve nada de B.
    app(TenantContext::class)->runFor($a['tenant']->id, function () use ($a, $b): void {
        $pickup = app(AuthorizedPickupList::class);
        $personB = Person::query()->count();

        expect($pickup->entriesFor($b['student']->id, 'puerta'))->toBe([])
            ->and($pickup->isAuthorized($b['student']->id, $a['student']->id))->toBeFalse()
            ->and($personB)->toBeGreaterThan(0);
    });

    // El audit_log de lectura es del centro que lee: las lecturas hechas desde A no añaden filas `read` a B.
    expect(app(TenantContext::class)->runFor($b['tenant']->id, fn () => AuditLog::query()->where('event', 'read')->count()))->toBe($readsB);
});

test('RN-FAM-UNIT-36 (decisión del usuario del 2026-11-02): sin revocacion_tutor.leer, TODA causa de no vigencia — sin foto, fuera de vigencia, retirada y persona excluida — sale como no_vigente; con él, la causa concreta', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $f = Fam::pickupFamily('pa-m6');
    $sinFoto = Fam::thirdParty($f, 40, photo: false);
    $caducada = Fam::thirdParty($f, 41, overrides: ['valid_until' => '2026-10-01']);
    $retirada = Fam::thirdParty($f, 42);
    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$retirada['authorization']}/withdrawal")->assertOk();
    $vigente = Fam::thirdParty($f, 43);
    $plain = Fam::userWith($f['tenant'], $f['admin'], 'sinrevoc', ['autorizado_recogida.leer' => 'todos']);

    $reasons = fn ($user) => collect(Fam::call($f['tenant'], $user, 'get', '/pickup-authorizations')->assertOk()->json('data'))->mapWithKeys(fn ($r) => [$r['public_id'] => $r['ineffective_reason']])->all();

    expect($reasons($f['admin']))->toEqual([$sinFoto['authorization'] => 'sin_foto', $caducada['authorization'] => 'fuera_de_vigencia', $retirada['authorization'] => 'retirada', $vigente['authorization'] => null])
        ->and($reasons($plain))->toEqual([$sinFoto['authorization'] => 'no_vigente', $caducada['authorization'] => 'no_vigente', $retirada['authorization'] => 'no_vigente', $vigente['authorization'] => null]);
});
