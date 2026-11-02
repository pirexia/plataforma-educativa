<?php

use App\Modules\Prl\Domain\Models\StudentMovement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;
use Tests\Support\PrlTestHelpers as Prl;

// REQ-PRL-004 (1.14b-b), docs/modulos/REQ-PRL/{funcional,api,datos}.md: movimientos de alumnado en la puerta. RN-PRL-01 a -07,
// -09, -10; CA-PRL-001 a -007; ADR-068 §5.1, §5.5; INV-003, INV-010, INV-011.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-PRL-001 RN-PRL-05: un conserje con entrega_menor.crear registra la salida de una persona que figura con método foto y recibe 201 con alumno, persona, base, método, quién entrega, quién registra e instante', function (): void {
    $f = Prl::doorFamily('prl-001');
    $response = Prl::exit($f)->assertCreated();

    expect($response->json())->toMatchArray([
        'student_person' => $f['student']->public_id,
        'direction' => 'salida',
        'off_schedule' => false,
        'off_schedule_reason' => null,
        'basis' => 'tercero',
        'verification_method' => 'foto',
        'corrects' => null,
        'occurred_at' => '2026-11-02T09:00:00.000000Z',
    ])
        ->and($response->json('picked_up_by.person'))->toBe($f['abuelo']['person'])
        ->and($response->json('picked_up_by.display_name'))->toContain('Abuelo')
        // Por defecto, quien registra es quien entrega.
        ->and($response->json('delivered_by'))->toBe($f['conserje']->public_id)
        ->and($response->json('recorded_by'))->toBe($f['conserje']->public_id)
        ->and($response->headers->get('Location'))->toContain('/student-movements/'.$response->json('public_id'));

    // La evidencia guarda la base y la referencia que dijo `fam_unit` (la autorización del abuelo), nunca una copia de la lista.
    $row = Fam::row($f['tenant'], 'student_movements', $response->json('public_id'));
    $authorizationId = Fam::row($f['tenant'], 'pickup_authorizations', $f['abuelo']['authorization'])->id;

    expect($row->basis)->toBe('tercero')
        ->and($row->pickup_authorization_id)->toBe($authorizationId)
        ->and($row->guardian_student_link_id)->toBeNull()
        ->and(Prl::count($f['tenant'], 'student_movements'))->toBe(1);
});

test('RN-PRL-05: un tutor con vínculo activo figura con base tutor y la referencia al vínculo; delivered_by puede ser otro usuario vivo y activo del centro', function (): void {
    $f = Prl::doorFamily('prl-005');
    $tutor = Prl::personOf($f['tenant'], $f['other']);

    $response = Prl::exit($f, $tutor, ['verification_method' => 'documento', 'delivered_by' => $f['direccion']->public_id])->assertCreated();
    $linkId = Fam::row($f['tenant'], 'guardian_student_links', $f['lo'])->id;
    $row = Fam::row($f['tenant'], 'student_movements', $response->json('public_id'));

    expect($response->json('basis'))->toBe('tutor')
        ->and($response->json('delivered_by'))->toBe($f['direccion']->public_id)
        ->and($response->json('recorded_by'))->toBe($f['conserje']->public_id)
        ->and($row->guardian_student_link_id)->toBe($linkId)
        ->and($row->pickup_authorization_id)->toBeNull();

    // delivered_by desconocido ⇒ 422 sin escribir.
    $before = Prl::count($f['tenant'], 'student_movements');
    $bad = Prl::exit($f, $tutor, ['verification_method' => 'documento', 'delivered_by' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertStatus(422);

    expect(Prl::errorCode($bad, 'delivered_by'))->toBe('prl.validation.user_not_found')
        ->and(Prl::count($f['tenant'], 'student_movements'))->toBe($before);
});

test('RN-PRL-03 CA-PRL-002 OPEN-FAM-UNIT-34: una persona que no figura, una desconocida y una excluida por revocación reciben 422 person_not_authorized con el MISMO cuerpo y no se escribe nada', function (): void {
    $f = Prl::doorFamily('prl-002');
    $stranger = Fam::registerPerson($f['tenant'], $f['admin'], ['given_name' => 'Vecino', 'document_type' => 'dni', 'document_number' => Fam::dni(77)])->assertCreated()->json('person');
    $revoked = Prl::personOf($f['tenant'], $f['other']);

    // Revocación judicial del padre sobre este alumno (RN-FAM-UNIT-80).
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lo']}/revocation", ['reason' => 'resolucion_judicial', 'notes' => null])->assertOk();

    $bodies = [];

    foreach ([$stranger, '01JZZZZZZZZZZZZZZZZZZZZZZZ', $revoked] as $person) {
        $response = Prl::exit($f, $person, ['verification_method' => 'documento'])->assertStatus(422);
        expect(Prl::errorCode($response, 'picked_up_by'))->toBe('prl.movement.person_not_authorized');
        $body = $response->json();
        unset($body['request_id'], $body['instance'], $body['trace_id']);
        $bodies[] = $body;
    }

    expect($bodies[1])->toBe($bodies[0])->and($bodies[2])->toBe($bodies[0])
        ->and(Prl::count($f['tenant'], 'student_movements'))->toBe(0);
});

test('CA-PRL-003 RN-PRL-02: la lista se abrió con la persona vigente y se retira o revoca antes de registrar; la recomprobación dentro de la transacción responde 422 y no escribe', function (): void {
    $f = Prl::doorFamily('prl-003');

    // La lista de puerta incluye al abuelo y al padre...
    $list = Prl::call($f['tenant'], $f['conserje'], 'get', "/door/students/{$f['student']->public_id}/pickup-list")->assertOk();
    expect(collect($list->json('data'))->pluck('person')->all())->toContain($f['abuelo']['person']);

    // ... se retira la autorización del abuelo y se revoca al padre, ya con la pantalla abierta.
    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$f['abuelo']['authorization']}/withdrawal")->assertOk();
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lo']}/revocation", ['reason' => 'resolucion_judicial', 'notes' => null])->assertOk();

    foreach ([$f['abuelo']['person'], Prl::personOf($f['tenant'], $f['other'])] as $person) {
        $response = Prl::exit($f, $person, ['verification_method' => 'documento'])->assertStatus(422);
        expect(Prl::errorCode($response, 'picked_up_by'))->toBe('prl.movement.person_not_authorized');
    }

    expect(Prl::count($f['tenant'], 'student_movements'))->toBe(0);

    // Una persona que sigue figurando (la madre, principal) sí se registra: la recomprobación no es un bloqueo general.
    Prl::exit($f, Prl::personOf($f['tenant'], $f['principal']), ['verification_method' => 'documento'])->assertCreated();
});

test('CA-PRL-004 RN-PRL-04: un movimiento no se modifica ni se borra por API, por modelo ni por SQL con el rol de la aplicación; una corrección es un movimiento nuevo que referencia al anterior', function (): void {
    $f = Prl::doorFamily('prl-004');
    $original = Prl::exit($f)->assertCreated()->json('public_id');

    // Por API: no existe ruta de modificación ni de borrado.
    Prl::call($f['tenant'], $f['admin'], 'patch', "/student-movements/{$original}", ['notes' => 'x'])->assertStatus(405);
    Prl::call($f['tenant'], $f['admin'], 'delete', "/student-movements/{$original}")->assertStatus(405);

    // Por modelo y por SQL con el rol de la aplicación.
    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($original): void {
        $model = StudentMovement::query()->where('public_id', $original)->firstOrFail();
        $model->notes = 'cambio';
        expect(fn () => $model->save())->toThrow(LogicException::class)
            ->and(fn () => $model->delete())->toThrow(LogicException::class);

        foreach ([['UPDATE student_movements SET notes = ? WHERE public_id = ?', ['x', $original]], ['DELETE FROM student_movements WHERE public_id = ?', [$original]]] as [$sql, $bindings]) {
            $state = null;

            try {
                DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->statement($sql, $bindings));
            } catch (QueryException $e) {
                $state = $e->errorInfo[0] ?? null;
            }

            expect($state)->toBe('42501');
        }
    });

    // La corrección: otro movimiento (otro método), con motivo, que referencia al original; el original queda intacto.
    Fam::tick();
    $correction = Prl::exit($f, null, ['verification_method' => 'foto_y_documento', 'corrects' => $original, 'correction_reason' => 'Se verificó también el documento'])->assertCreated();

    expect($correction->json('corrects'))->toBe($original)
        ->and($correction->json('correction_reason'))->toBe('Se verificó también el documento')
        ->and($correction->json('public_id'))->not->toBe($original)
        ->and(Fam::row($f['tenant'], 'student_movements', $original)->verification_method)->toBe('foto')
        ->and(Prl::count($f['tenant'], 'student_movements'))->toBe(2);

    // Un motivo sin corrección, una corrección sin motivo, un movimiento inexistente o de otro alumno ⇒ 422.
    expect(Prl::errorCode(Prl::exit($f, null, ['corrects' => $original])->assertStatus(422), 'correction_reason'))->toBe('prl.validation.required')
        ->and(Prl::errorCode(Prl::exit($f, null, ['correction_reason' => 'x'])->assertStatus(422), 'corrects'))->toBe('prl.validation.required')
        ->and(Prl::errorCode(Prl::exit($f, null, ['corrects' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'correction_reason' => 'x'])->assertStatus(422), 'corrects'))->toBe('prl.validation.reference_not_found');

    $other = Fam::person($f['tenant'], 'Otro', 'Alumno');
    Fam::addStudent($f['tenant'], $f['admin'], $f['unit'], $other)->assertCreated();
    $foreign = Prl::call($f['tenant'], $f['conserje'], 'post', '/student-movements', [
        'student_person' => $other->public_id, 'direction' => 'entrada', 'corrects' => $original, 'correction_reason' => 'x',
    ])->assertStatus(422);
    expect(Prl::errorCode($foreign, 'corrects'))->toBe('prl.validation.reference_not_found');
});

test('CA-PRL-005 RN-PRL-09 INV-011: dos POST con la misma Idempotency-Key y el mismo cuerpo dejan una sola fila y devuelven el mismo movimiento; con otro cuerpo es 409 idempotency_mismatch; la clave es por usuario', function (): void {
    $f = Prl::doorFamily('prl-005i');
    $key = ['Idempotency-Key' => 'tableta-1-toque-1'];

    $first = Prl::exit($f, null, [], null, $key)->assertCreated();
    Fam::tick();
    $second = Prl::exit($f, null, [], null, $key)->assertCreated();

    expect($second->json())->toBe($first->json())
        ->and($first->headers->get('Idempotency-Replayed'))->toBeNull()
        ->and($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and(Prl::count($f['tenant'], 'student_movements'))->toBe(1);

    // Misma clave con otro cuerpo.
    $mismatch = Prl::exit($f, null, ['verification_method' => 'foto_y_documento'], null, $key)->assertStatus(409);
    expect(Prl::errorCode($mismatch, 'idempotency_key'))->toBe('prl.idempotency_mismatch')
        ->and(Prl::count($f['tenant'], 'student_movements'))->toBe(1);

    // Otro usuario con la misma clave no choca (la clave es de quien registra); sin clave, dos toques son dos movimientos.
    Prl::exit($f, null, [], $f['admin'], $key)->assertCreated();
    Prl::exit($f)->assertCreated();
    Prl::exit($f)->assertCreated();
    expect(Prl::count($f['tenant'], 'student_movements'))->toBe(4);

    // Una repetición devuelve el resultado original aunque la persona haya dejado de figurar entre medias.
    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$f['abuelo']['authorization']}/withdrawal")->assertOk();
    expect(Prl::exit($f, null, [], null, $key)->assertCreated()->json('public_id'))->toBe($first->json('public_id'))
        ->and(Prl::count($f['tenant'], 'student_movements'))->toBe(4);

    // Clave no admisible ⇒ 422.
    expect(Prl::errorCode(Prl::exit($f, null, [], null, ['Idempotency-Key' => str_repeat('a', 129)])->assertStatus(422), 'idempotency_key'))->toBe('prl.validation.idempotency_key_invalid');
});

test('CA-PRL-006 RN-PRL-06: un tutor sin fotografía solo se verifica por documento; foto y foto_y_documento son 422 verification_requires_document', function (): void {
    $f = Prl::doorFamily('prl-006');
    $tutor = Prl::personOf($f['tenant'], $f['principal']);

    foreach (['foto', 'foto_y_documento'] as $method) {
        $response = Prl::exit($f, $tutor, ['verification_method' => $method])->assertStatus(422);
        expect(Prl::errorCode($response, 'verification_method'))->toBe('prl.movement.verification_requires_document');
    }

    expect(Prl::count($f['tenant'], 'student_movements'))->toBe(0);

    Prl::exit($f, $tutor, ['verification_method' => 'documento'])->assertCreated();

    // Con fotografía (tras subírsela), el tutor ya admite foto.
    Fam::putPhoto($f['tenant'], $f['admin'], $tutor)->assertOk();
    Prl::exit($f, $tutor, ['verification_method' => 'foto'])->assertCreated();

    // Un tercero con fotografía admite cualquier método.
    Prl::exit($f, null, ['verification_method' => 'foto_y_documento'])->assertCreated();
});

test('CA-PRL-007 RN-PRL-07: un movimiento fuera de horario sin motivo es 422 reason_required; con motivo se registra con su responsable; el motivo «otro» exige nota', function (): void {
    $f = Prl::doorFamily('prl-007');
    $body = ['student_person' => $f['student']->public_id, 'direction' => 'entrada', 'off_schedule' => true];

    expect(Prl::errorCode(Prl::call($f['tenant'], $f['conserje'], 'post', '/student-movements', $body)->assertStatus(422), 'off_schedule_reason'))->toBe('prl.movement.reason_required')
        ->and(Prl::errorCode(Prl::call($f['tenant'], $f['conserje'], 'post', '/student-movements', $body + ['off_schedule_reason' => 'otro'])->assertStatus(422), 'notes'))->toBe('prl.movement.reason_required')
        ->and(Prl::count($f['tenant'], 'student_movements'))->toBe(0);

    // Entrada fuera de horario por cita médica, con responsable (delivered_by = quien la autoriza).
    $entry = Prl::call($f['tenant'], $f['conserje'], 'post', '/student-movements', $body + ['off_schedule_reason' => 'medico', 'delivered_by' => $f['direccion']->public_id])->assertCreated();

    expect($entry->json())->toMatchArray(['direction' => 'entrada', 'off_schedule' => true, 'off_schedule_reason' => 'medico', 'picked_up_by' => null, 'basis' => null, 'verification_method' => null])
        ->and($entry->json('delivered_by'))->toBe($f['direccion']->public_id);

    // Salida fuera de horario: aplica el flujo de entrega completo (21.5.5) y lleva motivo y nota.
    $exit = Prl::exit($f, null, ['off_schedule' => true, 'off_schedule_reason' => 'otro', 'notes' => 'Recogida anticipada por viaje'])->assertCreated();
    expect($exit->json())->toMatchArray(['off_schedule' => true, 'off_schedule_reason' => 'otro', 'notes' => 'Recogida anticipada por viaje', 'basis' => 'tercero']);

    // Un motivo sin fuera de horario no tiene sentido.
    expect(Prl::errorCode(Prl::exit($f, null, ['off_schedule_reason' => 'medico'])->assertStatus(422), 'off_schedule_reason'))->toBe('prl.validation.off_schedule_reason_unexpected');
});

test('api.md §8.4 INV-010: la forma del cuerpo se valida con 422 y no escribe nada — salida sin persona o método, entrada con persona, vocabularios, referencias, booleano y longitud', function (): void {
    $f = Prl::doorFamily('prl-forma');
    $abuelo = $f['abuelo']['person'];
    $student = $f['student']->public_id;

    $cases = [
        [['student_person' => null], 'student_person', 'prl.validation.required'],
        [['student_person' => 'no-ulid'], 'student_person', 'prl.validation.reference_invalid'],
        [['student_person' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'], 'student_person', 'prl.validation.student_not_found'],
        [['direction' => null], 'direction', 'prl.validation.required'],
        [['direction' => 'traslado'], 'direction', 'prl.validation.enum_invalid'],
        [['picked_up_by' => null], 'picked_up_by', 'prl.validation.required'],
        [['picked_up_by' => 'no-ulid'], 'picked_up_by', 'prl.validation.reference_invalid'],
        [['verification_method' => null], 'verification_method', 'prl.validation.required'],
        [['verification_method' => 'huella'], 'verification_method', 'prl.validation.enum_invalid'],
        [['delivered_by' => 'no-ulid'], 'delivered_by', 'prl.validation.reference_invalid'],
        [['off_schedule' => 'si'], 'off_schedule', 'prl.validation.boolean_invalid'],
        [['off_schedule' => true, 'off_schedule_reason' => 'vacaciones'], 'off_schedule_reason', 'prl.validation.enum_invalid'],
        [['notes' => str_repeat('x', 501)], 'notes', 'prl.validation.text_invalid'],
        [['direction' => 'entrada'], 'picked_up_by', 'prl.validation.entry_without_pickup'],
    ];

    foreach ($cases as [$override, $field, $code]) {
        $body = $override + ['student_person' => $student, 'direction' => 'salida', 'picked_up_by' => $abuelo, 'verification_method' => 'foto'];
        $response = Prl::call($f['tenant'], $f['conserje'], 'post', '/student-movements', $body)->assertStatus(422);

        expect(Prl::errorCode($response, $field))->toBe($code, json_encode($override));
    }

    expect(Prl::count($f['tenant'], 'student_movements'))->toBe(0);
});

test('RN-PRL-10 INV-003: registrar un movimiento se audita con política Selective — códigos y marcas sí; personas, usuarios, notas y claves de idempotencia, nunca', function (): void {
    $f = Prl::doorFamily('prl-audit');
    $id = Prl::exit($f, null, ['off_schedule' => true, 'off_schedule_reason' => 'otro', 'notes' => 'Nota privada del conserje'], null, ['Idempotency-Key' => 'clave-secreta-123'])->assertCreated()->json('public_id');

    $rows = Fam::audit($f['tenant'], 'student_movement');
    expect($rows)->toHaveCount(1)->and($rows[0]->event)->toBe('created')->and($rows[0]->auditable_public_id)->toBe($id);

    $json = json_encode($rows[0]->changes);

    expect($json)->toContain('tercero')->toContain('foto')->toContain('salida')
        ->and($json)->not->toContain('Nota privada del conserje')
        ->and($json)->not->toContain('clave-secreta-123');
});

test('api.md §8.4 §8.5: GET /student-movements es un cursor (occurred_at DESC, id DESC) con filtros student_person, date, direction y off_schedule; el cursor de otra consulta es 422; el detalle responde 404 si no existe', function (): void {
    $f = Prl::doorFamily('prl-lista');
    $ids = [];

    for ($i = 0; $i < 3; $i++) {
        Fam::tick();
        $ids[] = Prl::exit($f)->assertCreated()->json('public_id');
    }

    Fam::tick();
    $entry = Prl::call($f['tenant'], $f['conserje'], 'post', '/student-movements', ['student_person' => $f['student']->public_id, 'direction' => 'entrada', 'off_schedule' => true, 'off_schedule_reason' => 'retraso'])->assertCreated()->json('public_id');

    $all = Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements')->assertOk();
    expect(array_column($all->json('data'), 'public_id'))->toBe([$entry, ...array_reverse($ids)])
        ->and($all->json('meta.has_more'))->toBeFalse();

    // Cursor: páginas de 2.
    $page1 = Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements?limit=2')->assertOk();
    $page2 = Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements?limit=2&cursor='.urlencode($page1->json('meta.next_cursor')))->assertOk();

    expect($page1->json('meta.has_more'))->toBeTrue()
        ->and(array_merge(array_column($page1->json('data'), 'public_id'), array_column($page2->json('data'), 'public_id')))->toBe([$entry, ...array_reverse($ids)])
        ->and($page2->json('meta.has_more'))->toBeFalse();

    // Filtros.
    $filter = fn (string $query): array => array_column(Prl::call($f['tenant'], $f['direccion'], 'get', "/student-movements?{$query}")->assertOk()->json('data'), 'public_id');

    expect($filter('direction=entrada'))->toBe([$entry])
        ->and($filter('off_schedule=true'))->toBe([$entry])
        ->and($filter('off_schedule=false'))->toHaveCount(3)
        ->and($filter("student_person={$f['student']->public_id}"))->toHaveCount(4)
        ->and($filter('student_person=01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBe([])
        ->and($filter('date=2026-11-02'))->toHaveCount(4)
        ->and($filter('date=2026-11-03'))->toBe([]);

    // El cursor de otra consulta (otros filtros) es 422; un valor inventado también.
    Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements?direction=entrada&cursor='.urlencode($page1->json('meta.next_cursor')))->assertStatus(422);
    Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements?cursor=basura')->assertStatus(422);
    Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements?direction=salto')->assertStatus(422);
    Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements?limit=500')->assertStatus(422);

    // Detalle.
    expect(Prl::call($f['tenant'], $f['direccion'], 'get', "/student-movements/{$ids[0]}")->assertOk()->json('public_id'))->toBe($ids[0]);
    Prl::call($f['tenant'], $f['direccion'], 'get', '/student-movements/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

test('ADR-068 §5.1 RN-PRL-11 CA-FAM-UNIT-125: sin la lista no hay entrega — con fam_unit descontratado la salida con persona que recoge es 422 person_not_authorized y no se escribe', function (): void {
    $f = Prl::doorFamily('prl-sin-lista');
    Fam::disableModule($f['tenant']);

    // `fam_unit` descontratado: la interfaz responde «no figura» (fallo en cerrado), aunque `prl` siga contratado.
    $response = Prl::exit($f)->assertStatus(422);

    expect(Prl::errorCode($response, 'picked_up_by'))->toBe('prl.movement.person_not_authorized')
        ->and(Prl::count($f['tenant'], 'student_movements'))->toBe(0);
});
