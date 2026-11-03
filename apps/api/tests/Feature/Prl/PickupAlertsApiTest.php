<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FamUnitTestHelpers as Fam;
use Tests\Support\PrlTestHelpers as Prl;

// REQ-PRL-004 (1.14b-b), docs/modulos/REQ-PRL/api.md §8.4: alertas por persona no autorizada. RN-PRL-08, RN-PRL-09, RN-PRL-10;
// CA-PRL-008; OPEN-FAM-UNIT-33, OPEN-FAM-UNIT-40; INV-003, INV-010.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Abre una alerta como conserje. */
function openAlert(array $f, array $overrides = [], array $headers = [], ?object $actor = null): TestResponse
{
    return Prl::call($f['tenant'], $actor ?? $f['conserje'], 'post', '/pickup-alerts', $overrides + [
        'student_person' => $f['student']->public_id,
        'presenter_description' => 'Dice llamarse Carlos P., exhibe DNI 12345678Z',
    ], $headers);
}

test('RN-PRL-08 OPEN-FAM-UNIT-33 OPEN-FAM-UNIT-40: abrir una alerta devuelve 201 con el protocolo del producto y los teléfonos del tutor principal y del contacto de emergencia (de Core, por fam_unit), y no se guardan en la alerta', function (): void {
    $f = Prl::doorFamily('prla-001');
    // Teléfonos: la madre (principal) y el padre (contacto de emergencia).
    Fam::call($f['tenant'], $f['admin'], 'patch', "/guardian-links/{$f['lo']}", ['is_emergency_contact' => true])->assertOk();
    $phones = fn (string $user, string $phone) => app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::table('people')->whereIn('id', DB::table('users')->where('public_id', $user)->select('person_id'))->update(['contact_phone' => $phone]));
    $phones($f['principal']->public_id, '600111111');
    $phones($f['other']->public_id, '600222222');

    $response = openAlert($f)->assertCreated();

    expect($response->json())->toMatchArray([
        'student_person' => $f['student']->public_id,
        'kind' => 'persona_no_autorizada',
        'presenter_description' => 'Dice llamarse Carlos P., exhibe DNI 12345678Z',
        'status' => 'abierta',
        'raised_by' => $f['conserje']->public_id,
        'principal_contacted_at' => null,
        'resolution' => null,
        'resolved_by' => null,
        'resolved_at' => null,
    ])
        ->and($response->json('raised_at'))->toBe('2026-11-02T09:00:00.000000Z')
        ->and($response->json('contacts'))->toBe(['principal_phone' => '600111111', 'emergency_phone' => '600222222'])
        ->and($response->json('protocol'))->toBe(__('prl.protocol.minimal'))
        ->and($response->json('protocol'))->toContain('No entregues')
        ->and($response->headers->get('Location'))->toContain('/pickup-alerts/'.$response->json('public_id'));

    // Los teléfonos no se guardan: la fila no tiene columnas de contacto y el listado no los lleva.
    $columns = array_column(DB::connection('pgsql_owner')->select("SELECT column_name FROM information_schema.columns WHERE table_name = 'pickup_alerts'"), 'column_name');
    expect(collect($columns)->filter(fn ($c) => str_contains($c, 'phone') || str_contains($c, 'contact_phone'))->all())->toBe([])
        ->and(Prl::call($f['tenant'], $f['direccion'], 'get', '/pickup-alerts')->assertOk()->json('data.0'))->not->toHaveKey('contacts')->not->toHaveKey('protocol');

    // El detalle abierto sí los lleva; un principal revocado para el alumno no aparece (la puerta no revela la revocación).
    expect(Prl::call($f['tenant'], $f['direccion'], 'get', '/pickup-alerts/'.$response->json('public_id'))->assertOk()->json('contacts.principal_phone'))->toBe('600111111');
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lp']}/revocation", ['reason' => 'resolucion_judicial', 'notes' => null])->assertOk();
    $after = Prl::call($f['tenant'], $f['direccion'], 'get', '/pickup-alerts/'.$response->json('public_id'))->assertOk();
    expect($after->json('contacts.principal_phone'))->toBeNull()
        ->and(json_encode($after->json()))->not->toContain('revoc');
});

test('CA-PRL-008 RN-PRL-08: una alerta abierta se resuelve con no_entregado y la hora de la llamada al principal; queda cerrada, auditada y no reabrible por PATCH (409)', function (): void {
    $f = Prl::doorFamily('prla-008');
    $id = openAlert($f)->assertCreated()->json('public_id');
    Fam::tick();

    // Primero la llamada (sigue abierta) y luego el cierre con notas.
    $called = Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$id}", ['principal_contacted_at' => '2026-11-02T09:00:02Z'])->assertOk();
    expect($called->json('status'))->toBe('abierta')->and($called->json('principal_contacted_at'))->toBe('2026-11-02T09:00:02.000000Z')
        ->and($called->json())->toHaveKey('protocol');

    Fam::tick();
    $closed = Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$id}", ['resolution' => 'no_entregado', 'resolution_notes' => 'El alumno se quedó en dirección hasta que llegó su madre'])->assertOk();

    expect($closed->json())->toMatchArray(['status' => 'cerrada', 'resolution' => 'no_entregado', 'resolved_by' => $f['direccion']->public_id, 'principal_contacted_at' => '2026-11-02T09:00:02.000000Z'])
        ->and($closed->json('resolved_at'))->toBe('2026-11-02T09:00:04.000000Z')
        ->and($closed->json())->not->toHaveKey('protocol');

    // No se reabre ni se modifica: 409 prl.alert.closed (el estado, antes que el contenido).
    foreach ([['resolution' => 'otro'], ['principal_contacted_at' => '2026-11-02T09:00:03Z']] as $body) {
        expect(Prl::errorCode(Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$id}", $body)->assertStatus(409), 'status'))->toBe('prl.alert.closed');
    }

    // Reabrirla con `status` es un campo no editable (422), y sigue cerrada.
    Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$id}", ['status' => 'abierta'])->assertStatus(422);
    expect(Prl::call($f['tenant'], $f['direccion'], 'get', "/pickup-alerts/{$id}")->assertOk()->json('status'))->toBe('cerrada');

    // Auditoría Selective: alta y resolución, sin la descripción de quien se presenta ni las notas.
    // (Las lecturas con teléfonos, `read`, tienen su propio test: `PrlReadAuditTest`.)
    $logs = array_values(array_filter(Fam::audit($f['tenant'], 'pickup_alert'), fn ($l) => $l->event !== 'read'));
    expect(array_column($logs, 'event'))->toBe(['created', 'updated', 'updated']);
    $json = json_encode(array_map(fn ($l) => $l->changes, $logs));
    expect($json)->toContain('no_entregado')->not->toContain('Carlos P.')->not->toContain('El alumno se quedó')->not->toContain('12345678Z');

    // No hay DELETE (evidencia).
    Prl::call($f['tenant'], $f['admin'], 'delete', "/pickup-alerts/{$id}")->assertStatus(405);
});

test('api.md §8.4 INV-010: la forma del cuerpo de la alerta se valida — alumno obligatorio y existente (404), descripción de 1 a 500, vocabularios, PATCH vacío o con campos no editables, notas sin resolución, hora de llamada fuera de rango', function (): void {
    $f = Prl::doorFamily('prla-forma');

    foreach ([
        [['student_person' => null], 'student_person', 'prl.validation.required'],
        [['student_person' => 'no-ulid'], 'student_person', 'prl.validation.reference_invalid'],
        [['presenter_description' => null], 'presenter_description', 'prl.validation.required'],
        [['presenter_description' => '   '], 'presenter_description', 'prl.validation.required'],
        [['presenter_description' => str_repeat('x', 501)], 'presenter_description', 'prl.validation.text_invalid'],
        [['kind' => 'alumno_no_recogido'], 'kind', 'prl.validation.enum_invalid'],
    ] as [$override, $field, $code]) {
        expect(Prl::errorCode(openAlert($f, $override)->assertStatus(422), $field))->toBe($code, json_encode($override));
    }

    openAlert($f, ['student_person' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertNotFound();
    expect(Prl::count($f['tenant'], 'pickup_alerts'))->toBe(0);

    $id = openAlert($f)->assertCreated()->json('public_id');
    $patch = fn (array $body) => Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$id}", $body)->assertStatus(422);

    expect(Prl::errorCode($patch([]), 'body'))->toBe('prl.validation.empty_body')
        ->and(Prl::errorCode($patch(['resolution' => 'fugado']), 'resolution'))->toBe('prl.validation.enum_invalid')
        ->and(Prl::errorCode($patch(['resolution_notes' => 'x']), 'resolution_notes'))->toBe('prl.validation.notes_without_resolution')
        ->and(Prl::errorCode($patch(['resolution' => 'otro', 'resolution_notes' => str_repeat('x', 1001)]), 'resolution_notes'))->toBe('prl.validation.text_invalid')
        ->and(Prl::errorCode($patch(['status' => 'cerrada']), 'status'))->toBe('prl.validation.field_not_editable')
        ->and(Prl::errorCode($patch(['student_person' => $f['student']->public_id]), 'student_person'))->toBe('prl.validation.field_not_editable')
        ->and(Prl::errorCode($patch(['principal_contacted_at' => 'ayer']), 'principal_contacted_at'))->toBe('prl.validation.datetime_invalid')
        ->and(Prl::errorCode($patch(['principal_contacted_at' => '2026-11-02T09:00:10Z']), 'principal_contacted_at'))->toBe('prl.validation.contact_time_out_of_range')
        ->and(Prl::errorCode($patch(['principal_contacted_at' => '2026-11-02T08:00:00Z']), 'principal_contacted_at'))->toBe('prl.validation.contact_time_out_of_range');

    // Nada de lo anterior cambió la alerta.
    expect(Prl::call($f['tenant'], $f['direccion'], 'get', "/pickup-alerts/{$id}")->assertOk()->json('status'))->toBe('abierta');
    Prl::call($f['tenant'], $f['direccion'], 'patch', '/pickup-alerts/01JZZZZZZZZZZZZZZZZZZZZZZZ', ['resolution' => 'otro'])->assertNotFound();
});

test('RN-PRL-09 INV-011: dos POST de alerta con la misma Idempotency-Key y el mismo cuerpo dejan una sola fila y devuelven la misma alerta; con otro cuerpo, 409 idempotency_mismatch', function (): void {
    $f = Prl::doorFamily('prla-idem');
    $key = ['Idempotency-Key' => 'alerta-tableta-7'];

    $first = openAlert($f, [], $key)->assertCreated();
    Fam::tick();
    $second = openAlert($f, [], $key)->assertCreated();

    expect($second->json('public_id'))->toBe($first->json('public_id'))
        ->and($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and(Prl::count($f['tenant'], 'pickup_alerts'))->toBe(1);

    $mismatch = openAlert($f, ['presenter_description' => 'Otra persona distinta'], $key)->assertStatus(409);
    expect(Prl::errorCode($mismatch, 'idempotency_key'))->toBe('prl.idempotency_mismatch')
        ->and(Prl::count($f['tenant'], 'pickup_alerts'))->toBe(1);

    openAlert($f)->assertCreated();
    expect(Prl::count($f['tenant'], 'pickup_alerts'))->toBe(2);
});

test('api.md §8.4 §8.5: GET /pickup-alerts pagina por raised_at desc con filtros status, date y student_person', function (): void {
    $f = Prl::doorFamily('prla-lista');
    $first = openAlert($f)->assertCreated()->json('public_id');
    Fam::tick();
    $second = openAlert($f, ['presenter_description' => 'Segunda persona'])->assertCreated()->json('public_id');
    Fam::tick();
    Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$first}", ['resolution' => 'otro', 'resolution_notes' => 'Falsa alarma'])->assertOk();

    $ids = fn (string $query): array => array_column(Prl::call($f['tenant'], $f['direccion'], 'get', "/pickup-alerts{$query}")->assertOk()->json('data'), 'public_id');

    expect($ids(''))->toBe([$second, $first])
        ->and($ids('?status=abierta'))->toBe([$second])
        ->and($ids('?status=cerrada'))->toBe([$first])
        ->and($ids('?date=2026-11-02'))->toBe([$second, $first])
        ->and($ids('?date=2026-11-01'))->toBe([])
        ->and($ids("?student_person={$f['student']->public_id}"))->toBe([$second, $first])
        ->and($ids('?student_person=01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBe([])
        ->and(Prl::call($f['tenant'], $f['direccion'], 'get', '/pickup-alerts?per_page=1')->assertOk()->json('meta.total'))->toBe(2);

    Prl::call($f['tenant'], $f['direccion'], 'get', '/pickup-alerts?status=otro')->assertStatus(422);
    Prl::call($f['tenant'], $f['direccion'], 'get', '/pickup-alerts?date=02-11-2026')->assertStatus(422);
});

test('REQ-PRL-004 api.md §8.4 INV-007: la alerta (listado y detalle) devuelve el nombre visible del alumno, de quien la abrió y de quien la cerró, junto a los public_id, y el listado no hace una consulta por fila', function (): void {
    $f = Prl::doorFamily('prla-nombres');
    $ids = [];
    foreach (['Primera', 'Segunda', 'Tercera'] as $i => $text) {
        $ids[] = openAlert($f, ['presenter_description' => $text])->assertCreated()->json('public_id');
        Fam::tick();
    }
    $closed = Prl::call($f['tenant'], $f['direccion'], 'patch', "/pickup-alerts/{$ids[0]}", ['resolution' => 'otro'])->assertOk();

    $closed->assertJsonPath('student_person', $f['student']->public_id)
        ->assertJsonPath('raised_by', $f['conserje']->public_id)
        ->assertJsonPath('resolved_by', $f['direccion']->public_id);
    expect($closed->json('student_display_name'))->toContain('Alumno Recogida')
        ->and($closed->json('raised_by_display_name'))->not->toBe('')
        ->and($closed->json('resolved_by_display_name'))->not->toBe('')
        ->and(array_keys($closed->json()))->not->toContain('birth_date', 'document_number', 'phone', 'email');

    $detail = Prl::call($f['tenant'], $f['direccion'], 'get', "/pickup-alerts/{$ids[1]}")->assertOk();
    expect($detail->json('student_display_name'))->toBe($closed->json('student_display_name'))
        ->and($detail->json('raised_by_display_name'))->toBe($closed->json('raised_by_display_name'))
        ->and($detail->json('resolved_by'))->toBeNull()
        ->and($detail->json('resolved_by_display_name'))->toBeNull();

    $count = static function (int $expectedRows) use ($f): int {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $rows = Prl::call($f['tenant'], $f['direccion'], 'get', '/pickup-alerts')->assertOk()->json('data');
        $n = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        expect($rows)->toHaveCount($expectedRows);
        foreach ($rows as $row) {
            expect($row['student_display_name'])->not->toBe('')->and($row['raised_by_display_name'])->not->toBe('');
        }

        return $n;
    };

    $with3 = $count(3);
    openAlert($f, ['presenter_description' => 'Cuarta'])->assertCreated();
    openAlert($f, ['presenter_description' => 'Quinta'])->assertCreated();

    expect($count(5))->toBe($with3);
});
