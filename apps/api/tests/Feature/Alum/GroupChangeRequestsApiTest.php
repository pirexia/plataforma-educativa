<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\AlumEnrollmentHelpers as En;
use Tests\Support\AlumTestHelpers as Alum;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-ALUM-003 (1.15-c T2a), docs/modulos/REQ-ALUM/api.md §3, funcional.md §4.4 y §5.3: solicitudes de cambio de grupo por la
// API. CA-ALUM-018c, -037 a -039, -050 a -055; RN-ALUM-40 a -46; INV-001, INV-002, INV-003, INV-010, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Centro con dos alumnos matriculados en el grupo A (0 y 1) y uno en el B (2), una secretaria (solicita y cancela) y una
 * dirección (aprueba y rechaza).
 *
 * @return array<string, mixed>
 */
function gcrScenario(string $slug): array
{
    $s = En::scenario($slug);

    foreach ([[0, $s['api']['group']], [1, $s['api']['group']], [2, $s['groupB']]] as [$student, $group]) {
        $s['enrollments'][$student] = Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', En::body($s, $student, ['group' => $group]))
            ->assertCreated()->json('public_id');
    }

    $s['sec'] = Alum::userWith($s['tenant'], $s['admin'], 'secretaria', ['matricula.leer', 'matricula.actualizar']);
    $s['dir'] = Alum::userWith($s['tenant'], $s['admin'], 'direccion', ['matricula.leer', 'matricula.aprobar']);

    return $s;
}

/** `POST /group-change-requests` (por defecto la matrícula 0 hacia el grupo B, como secretaria). */
function gcrRequest(array $s, ?array $body = null, ?User $as = null): TestResponse
{
    return Alum::call($s['tenant'], $as ?? $s['sec'], 'post', '/group-change-requests', $body ?? [
        'enrollment' => $s['enrollments'][0], 'to_group' => $s['groupB'], 'reason' => 'Cambio pedido por la familia',
    ]);
}

function gcrCreated(array $s, ?array $body = null): string
{
    return gcrRequest($s, $body)->assertCreated()->json('public_id');
}

function gcrAct(array $s, string $id, string $action, User $as, array $body = []): TestResponse
{
    return Alum::call($s['tenant'], $as, 'post', "/group-change-requests/{$id}/{$action}", $body);
}

function gcrGroupOf(array $s, string $enrollment): ?int
{
    return Alum::row($s['tenant'], 'enrollments', $enrollment)->group_id;
}

function gcrGroupId(array $s, string $group): int
{
    return (int) Alum::row($s['tenant'], 'groups', $group)->id;
}

/** @return list<string> */
function gcrIds(TestResponse $r): array
{
    return array_column($r->assertOk()->json('data'), 'public_id');
}

function gcrList(array $s, User $as, string $query = ''): TestResponse
{
    return Alum::call($s['tenant'], $as, 'get', "/group-change-requests?academic_year={$s['year']->public_id}".$query);
}

test('CA-ALUM-050 RN-ALUM-40 RN-ALUM-43 INV-003: secretaría solicita, dirección aprueba; la matrícula queda en el grupo destino, la solicitud aprobada con quién y cuándo, y ambas escrituras auditadas', function (): void {
    $s = gcrScenario('gcr-050');

    $created = gcrRequest($s)->assertCreated();

    expect($created->json())->toHaveKeys(['public_id', 'enrollment', 'academic_year', 'from_group', 'to_group', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'created_at', 'updated_at'])
        ->and($created->json('status'))->toBe('pendiente')
        ->and($created->json('enrollment.public_id'))->toBe($s['enrollments'][0])
        ->and($created->json('enrollment.student.display_name'))->toBe('Alumno Uno')
        ->and($created->json('from_group.public_id'))->toBe($s['api']['group'])
        ->and($created->json('to_group.public_id'))->toBe($s['groupB'])
        ->and($created->json('requested_by.public_id'))->toBe($s['sec']->public_id)
        ->and($created->json('decided_by'))->toBeNull()
        ->and($created->headers->get('Location'))->toContain('/api/v1/group-change-requests/'.$created->json('public_id'))
        ->and(gcrGroupOf($s, $s['enrollments'][0]))->toBe(gcrGroupId($s, $s['api']['group']))
        ->and(Alum::auditCount($s['tenant'], 'alum_group_change_request', 'created'))->toBe(1);

    $id = $created->json('public_id');
    $updatedBefore = Alum::auditCount($s['tenant'], 'alum_enrollment', 'updated');
    $approved = gcrAct($s, $id, 'approval', $s['dir'])->assertOk();

    expect($approved->json('status'))->toBe('aprobada')
        ->and($approved->json('decided_by.public_id'))->toBe($s['dir']->public_id)
        ->and($approved->json('decided_at'))->not->toBeNull()
        ->and(gcrGroupOf($s, $s['enrollments'][0]))->toBe(gcrGroupId($s, $s['groupB']))
        ->and(Alum::auditCount($s['tenant'], 'alum_enrollment', 'updated'))->toBe($updatedBefore + 1)
        ->and(Alum::auditCount($s['tenant'], 'alum_group_change_request', 'updated'))->toBe(1)
        ->and(Alum::call($s['tenant'], $s['dir'], 'get', "/enrollments/{$s['enrollments'][0]}")->assertOk()->json('group.public_id'))->toBe($s['groupB']);
});

test('CA-ALUM-051 RN-ALUM-41: una segunda solicitud para una matrícula con otra pendiente es 422 request_already_pending; resuelta la primera, se admite otra', function (): void {
    $s = gcrScenario('gcr-051');
    $first = gcrCreated($s);

    $again = gcrRequest($s)->assertUnprocessable();

    expect(Alum::errorCode($again, 'enrollment'))->toBe('alum.validation.request_already_pending')
        ->and(Alum::count($s['tenant'], 'group_change_requests'))->toBe(1);

    gcrAct($s, $first, 'cancellation', $s['sec'])->assertOk();
    gcrRequest($s)->assertCreated();

    expect(Alum::count($s['tenant'], 'group_change_requests'))->toBe(2);
});

test('CA-ALUM-052 RN-ALUM-44: el solicitante no puede aprobar su propia solicitud (403 self_approval) y no cambia nada; sí rechazarla y cancelarla', function (): void {
    $s = gcrScenario('gcr-052');
    $both = Alum::userWith($s['tenant'], $s['admin'], 'ambos', ['matricula.leer', 'matricula.actualizar', 'matricula.aprobar']);
    $id = gcrRequest($s, null, $both)->assertCreated()->json('public_id');

    $denied = gcrAct($s, $id, 'approval', $both)->assertForbidden();

    expect($denied->json('detail'))->toBe(__('alum.forbidden.self_approval'))
        ->and(Alum::row($s['tenant'], 'group_change_requests', $id)->status)->toBe('pendiente')
        ->and(gcrGroupOf($s, $s['enrollments'][0]))->toBe(gcrGroupId($s, $s['api']['group']));

    gcrAct($s, $id, 'rejection', $both, ['decision_note' => 'La retiro yo'])->assertOk()->assertJsonPath('status', 'rechazada');

    $second = gcrRequest($s, null, $both)->assertCreated()->json('public_id');
    gcrAct($s, $second, 'cancellation', $both)->assertOk()->assertJsonPath('status', 'cancelada');
});

test('CA-ALUM-053 RN-ALUM-43: matrícula dada de baja tras solicitar; al aprobar, 409 enrollment_changed y nada cambia; anulada, también, y entonces aún se puede rechazar', function (): void {
    $s = gcrScenario('gcr-053');
    $id = gcrCreated($s);
    $other = gcrCreated($s, ['enrollment' => $s['enrollments'][1], 'to_group' => $s['groupB'], 'reason' => 'Otro cambio']);

    Alum::call($s['tenant'], $s['admin'], 'post', "/enrollments/{$s['enrollments'][0]}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'traslado'])->assertOk();
    $r = gcrAct($s, $id, 'approval', $s['dir'])->assertConflict();

    expect(Alum::errorCode($r, 'enrollment'))->toBe('alum.conflict.enrollment_changed')
        ->and(Alum::row($s['tenant'], 'group_change_requests', $id)->status)->toBe('pendiente')
        ->and(gcrGroupOf($s, $s['enrollments'][0]))->toBe(gcrGroupId($s, $s['api']['group']));

    Alum::call($s['tenant'], $s['admin'], 'delete', "/enrollments/{$s['enrollments'][1]}")->assertNoContent();

    expect(Alum::errorCode(gcrAct($s, $other, 'approval', $s['dir'])->assertConflict(), 'enrollment'))->toBe('alum.conflict.enrollment_changed');
    gcrAct($s, $other, 'rejection', $s['dir'], ['decision_note' => 'Matrícula anulada'])->assertOk()->assertJsonPath('status', 'rechazada');
});

test('CA-ALUM-054 RN-ALUM-40: destino de otro curso de etapa, el mismo grupo, inexistente, matrícula sin grupo o en baja son 422 con su código y no crean nada', function (): void {
    $s = gcrScenario('gcr-054');
    $code = fn (TestResponse $r, string $field): ?string => Alum::errorCode($r->assertUnprocessable(), $field);
    $body = fn (array $o) => $o + ['enrollment' => $s['enrollments'][0], 'to_group' => $s['groupB'], 'reason' => 'Motivo'];

    expect($code(gcrRequest($s, $body(['to_group' => $s['group2']])), 'to_group'))->toBe('alum.validation.group_not_in_course')
        ->and($code(gcrRequest($s, $body(['to_group' => $s['api']['group']])), 'to_group'))->toBe('alum.validation.group_not_in_course')
        ->and($code(gcrRequest($s, $body(['to_group' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])), 'to_group'))->toBe('alum.validation.parent_not_found');

    $noGroup = Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', En::body($s, 0, ['student' => Alum::student($s['tenant'], $s['admin'], ['given_name' => 'Sin', 'family_name_1' => 'Grupo'])['public_id'], 'group' => null]))->assertCreated()->json('public_id');

    expect($code(gcrRequest($s, $body(['enrollment' => $noGroup])), 'enrollment'))->toBe('alum.validation.enrollment_without_group');

    Alum::call($s['tenant'], $s['admin'], 'post', "/enrollments/{$s['enrollments'][1]}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'otro'])->assertOk();

    expect($code(gcrRequest($s, $body(['enrollment' => $s['enrollments'][1]])), 'enrollment'))->toBe('alum.validation.enrollment_not_active')
        ->and(Alum::count($s['tenant'], 'group_change_requests'))->toBe(0)
        ->and(gcrRequest($s, $body(['enrollment' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound())->not->toBeNull();
});

test('INV-010 RN-ALUM-45: el motivo es obligatorio y de 1 a 500 caracteres; la nota es obligatoria al rechazar y opcional al aprobar y cancelar, siempre de 1 a 500', function (): void {
    $s = gcrScenario('gcr-045');
    $body = fn (array $o) => $o + ['enrollment' => $s['enrollments'][0], 'to_group' => $s['groupB']];

    expect(Alum::errorCode(gcrRequest($s, $body([]))->assertUnprocessable(), 'reason'))->toBe('alum.validation.required')
        ->and(Alum::errorCode(gcrRequest($s, $body(['reason' => '   ']))->assertUnprocessable(), 'reason'))->toBe('alum.validation.reason_invalid')
        ->and(Alum::errorCode(gcrRequest($s, $body(['reason' => str_repeat('x', 501)]))->assertUnprocessable(), 'reason'))->toBe('alum.validation.reason_invalid')
        ->and(Alum::errorCode(gcrRequest($s, $body(['reason' => ['a']]))->assertUnprocessable(), 'reason'))->toBe('alum.validation.reason_invalid')
        ->and(Alum::errorCode(gcrRequest($s, ['to_group' => $s['groupB'], 'reason' => 'x'])->assertUnprocessable(), 'enrollment'))->toBe('alum.validation.required');

    $id = gcrRequest($s, $body(['reason' => str_repeat('ñ', 500)]))->assertCreated()->json('public_id');

    expect(Alum::errorCode(gcrAct($s, $id, 'rejection', $s['dir'])->assertUnprocessable(), 'decision_note'))->toBe('alum.validation.decision_note_required')
        ->and(Alum::errorCode(gcrAct($s, $id, 'rejection', $s['dir'], ['decision_note' => ''])->assertUnprocessable(), 'decision_note'))->toBe('alum.validation.decision_note_invalid')
        ->and(Alum::errorCode(gcrAct($s, $id, 'approval', $s['dir'], ['decision_note' => str_repeat('x', 501)])->assertUnprocessable(), 'decision_note'))->toBe('alum.validation.decision_note_invalid')
        ->and(Alum::errorCode(gcrAct($s, $id, 'cancellation', $s['sec'], ['decision_note' => 5])->assertUnprocessable(), 'decision_note'))->toBe('alum.validation.decision_note_invalid')
        ->and(Alum::row($s['tenant'], 'group_change_requests', $id)->status)->toBe('pendiente');

    gcrAct($s, $id, 'approval', $s['dir'])->assertOk()->assertJsonPath('decision_note', null);
});

test('CA-ALUM-037 RN-ALUM-42: el solicitante cancela su solicitud (200, cancelada, decided_by = él); otro titular de matricula.aprobar rechaza con nota y queda su autoría', function (): void {
    $s = gcrScenario('gcr-037');
    $id = gcrCreated($s);

    $cancelled = gcrAct($s, $id, 'cancellation', $s['sec'], ['decision_note' => 'Ya no hace falta'])->assertOk();

    expect($cancelled->json('status'))->toBe('cancelada')
        ->and($cancelled->json('decided_by.public_id'))->toBe($s['sec']->public_id)
        ->and($cancelled->json('decided_at'))->not->toBeNull()
        ->and(gcrGroupOf($s, $s['enrollments'][0]))->toBe(gcrGroupId($s, $s['api']['group']));

    $second = gcrCreated($s);
    $rejected = gcrAct($s, $second, 'rejection', $s['dir'], ['decision_note' => 'No procede'])->assertOk();

    expect($rejected->json('status'))->toBe('rechazada')
        ->and($rejected->json('decided_by.public_id'))->toBe($s['dir']->public_id)
        ->and($rejected->json('decision_note'))->toBe('No procede')
        ->and(gcrGroupOf($s, $s['enrollments'][0]))->toBe(gcrGroupId($s, $s['api']['group']));
});

test('CA-ALUM-038 RN-ALUM-42: una solicitud aprobada, rechazada o cancelada no admite aprobar, rechazar ni cancelar (409 request_closed) y no cambia nada', function (): void {
    $s = gcrScenario('gcr-038');
    $approved = gcrCreated($s);
    gcrAct($s, $approved, 'approval', $s['dir'])->assertOk();
    $rejected = gcrCreated($s, ['enrollment' => $s['enrollments'][1], 'to_group' => $s['groupB'], 'reason' => 'x']);
    gcrAct($s, $rejected, 'rejection', $s['dir'], ['decision_note' => 'no'])->assertOk();
    $cancelled = gcrCreated($s, ['enrollment' => $s['enrollments'][2], 'to_group' => $s['api']['group'], 'reason' => 'x']);
    gcrAct($s, $cancelled, 'cancellation', $s['sec'])->assertOk();

    foreach ([$approved => 'aprobada', $rejected => 'rechazada', $cancelled => 'cancelada'] as $id => $status) {
        foreach ([['approval', $s['dir'], []], ['rejection', $s['dir'], ['decision_note' => 'otra vez']], ['cancellation', $s['sec'], []]] as [$action, $who, $body]) {
            $r = gcrAct($s, $id, $action, $who, $body)->assertConflict();

            expect(Alum::errorCode($r, 'request'))->toBe('alum.conflict.request_closed')
                ->and(Alum::row($s['tenant'], 'group_change_requests', $id)->status)->toBe($status);
        }
    }

    expect(Alum::auditCount($s['tenant'], 'alum_group_change_request', 'updated'))->toBe(3);
});

test('CA-ALUM-055 RN-ALUM-45 ADR-035: el motivo y la nota de decisión nunca aparecen en audit_logs.changes', function (): void {
    $s = gcrScenario('gcr-055');
    $id = gcrCreated($s, ['enrollment' => $s['enrollments'][0], 'to_group' => $s['groupB'], 'reason' => 'MOTIVO-SECRETO-CONFIDENCIAL']);
    gcrAct($s, $id, 'rejection', $s['dir'], ['decision_note' => 'NOTA-SECRETA-CONFIDENCIAL'])->assertOk();

    $entries = Alum::audit($s['tenant'], 'alum_group_change_request');
    $dump = json_encode(array_map(static fn ($e): array => $e->toArray(), $entries));

    expect($entries)->toHaveCount(2)
        ->and($dump)->not->toContain('MOTIVO-SECRETO-CONFIDENCIAL')
        ->and($dump)->not->toContain('NOTA-SECRETA-CONFIDENCIAL')
        ->and($dump)->toContain('pendiente')->toContain('rechazada');
});

test('RN-ALUM-23 CA-ALUM-025: solicitar, aprobar, rechazar y cancelar en un curso cerrado es 409 academic-year-closed y no cambia nada', function (): void {
    $s = gcrScenario('gcr-closed');
    $id = gcrCreated($s);
    $other = gcrCreated($s, ['enrollment' => $s['enrollments'][1], 'to_group' => $s['groupB'], 'reason' => 'x']);
    En::closeYear($s, $s['year']);

    // Quien puede leer el curso cerrado (`curso_historico.leer`, aquí el administrador) recibe 409; quien no, 404 (#598, `RN-ALUM-23`).
    foreach ([
        gcrRequest($s, ['enrollment' => $s['enrollments'][2], 'to_group' => $s['api']['group'], 'reason' => 'x'], $s['admin']),
        gcrAct($s, $id, 'approval', $s['admin']),
        gcrAct($s, $id, 'rejection', $s['admin'], ['decision_note' => 'n']),
        gcrAct($s, $other, 'cancellation', $s['admin']),
    ] as $r) {
        $r->assertConflict()->assertJsonPath('type', 'urn:pge:error:academic-year-closed');
    }

    gcrAct($s, $id, 'approval', $s['dir'])->assertNotFound();
    gcrAct($s, $other, 'cancellation', $s['sec'])->assertNotFound();

    expect(Alum::row($s['tenant'], 'group_change_requests', $id)->status)->toBe('pendiente')
        ->and(Alum::count($s['tenant'], 'group_change_requests'))->toBe(2)
        ->and(gcrGroupOf($s, $s['enrollments'][0]))->toBe(gcrGroupId($s, $s['api']['group']));
});

test('INV-002 api.md §3: cada endpoint exige su permiso (403 sin él, 401 sin sesión) y la autorización de aprobar no concede solicitar ni cancelar', function (): void {
    $s = gcrScenario('gcr-perm');
    $id = gcrCreated($s);
    $reader = Alum::userWith($s['tenant'], $s['admin'], 'lector', ['matricula.leer']);

    gcrRequest($s, null, $reader)->assertForbidden();
    gcrRequest($s, null, $s['dir'])->assertForbidden();
    gcrAct($s, $id, 'approval', $s['sec'])->assertForbidden();
    gcrAct($s, $id, 'rejection', $s['sec'], ['decision_note' => 'n'])->assertForbidden();
    gcrAct($s, $id, 'cancellation', $s['dir'])->assertForbidden();
    Alum::call($s['tenant'], $s['sec'], 'get', '/group-change-requests?academic_year='.$s['year']->public_id)->assertOk();
    $none = Alum::userWith($s['tenant'], $s['admin'], 'nada', ['alumno.leer']);
    gcrList($s, $none)->assertForbidden();
    Alum::call($s['tenant'], $none, 'get', "/group-change-requests/{$id}")->assertForbidden();

    resetSessionState();
    test()->getJson(coreApiUrl($s['tenant']->slug, '/group-change-requests'))->assertUnauthorized();
    test()->postJson(coreApiUrl($s['tenant']->slug, "/group-change-requests/{$id}/approval"))->assertUnauthorized();

    expect(Alum::row($s['tenant'], 'group_change_requests', $id)->status)->toBe('pendiente');
});

test('api.md §3: el listado exige academic_year (422 sin él, 404 si no existe) y filtra por status y group; el detalle devuelve la solicitud y un id inexistente es 404', function (): void {
    $s = gcrScenario('gcr-list');
    $a = gcrCreated($s);
    $b = gcrCreated($s, ['enrollment' => $s['enrollments'][2], 'to_group' => $s['api']['group'], 'reason' => 'x']);
    gcrAct($s, $b, 'cancellation', $s['sec'])->assertOk();

    expect(Alum::errorCode(Alum::call($s['tenant'], $s['dir'], 'get', '/group-change-requests')->assertUnprocessable(), 'academic_year'))->toBe('alum.validation.required');
    Alum::call($s['tenant'], $s['dir'], 'get', '/group-change-requests?academic_year=01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    Alum::call($s['tenant'], $s['dir'], 'get', "/group-change-requests?academic_year={$s['year']->public_id}&status=otra")->assertUnprocessable();

    expect(gcrIds(gcrList($s, $s['dir'])))->toBe([$b, $a])
        ->and(gcrIds(gcrList($s, $s['dir'], '&status=pendiente')))->toBe([$a])
        ->and(gcrIds(gcrList($s, $s['dir'], '&status=cancelada')))->toBe([$b])
        ->and(gcrIds(gcrList($s, $s['dir'], "&group={$s['groupB']}")))->toBe([$b, $a])
        ->and(gcrIds(gcrList($s, $s['dir'], "&group={$s['group2']}")))->toBe([])
        ->and(gcrIds(gcrList($s, $s['dir'], '&group=01JZZZZZZZZZZZZZZZZZZZZZZZ')))->toBe([])
        ->and(Alum::call($s['tenant'], $s['dir'], 'get', "/group-change-requests/{$a}")->assertOk()->json('public_id'))->toBe($a);

    Alum::call($s['tenant'], $s['dir'], 'get', '/group-change-requests/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

test('CA-ALUM-039 RN-ALUM-46 RN-ALUM-64: un docente de G1 con matricula.leer de ámbito grupo solo ve las solicitudes de matrículas de G1 y el detalle de una de G2 es 404; un tutor con unidad_familiar recibe lista vacía y 404', function (): void {
    $s = gcrScenario('gcr-039');
    $s['teacher'] = Alum::userWithScopes($s['tenant'], $s['admin'], 'docente', ['matricula.leer' => 'grupo']);
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$s['api']['group']}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    $assignment = Tt::postAssignment($s['tenant'], $s['admin'], $s['teacher'], $s['api']['group'], $s['api']['subject'])->assertCreated()->json('public_id');

    $inG1 = gcrCreated($s);
    $inG2 = gcrCreated($s, ['enrollment' => $s['enrollments'][2], 'to_group' => $s['api']['group'], 'reason' => 'x']);

    expect(gcrIds(gcrList($s, $s['teacher'])))->toBe([$inG1])
        ->and(gcrIds(gcrList($s, $s['admin'])))->toEqualCanonicalizing([$inG1, $inG2])
        ->and(gcrIds(gcrList($s, $s['teacher'], "&group={$s['groupB']}")))->toBe([$inG1])
        ->and(Alum::call($s['tenant'], $s['teacher'], 'get', "/group-change-requests/{$inG1}")->assertOk()->json('public_id'))->toBe($inG1);

    $r2 = Alum::call($s['tenant'], $s['teacher'], 'get', "/group-change-requests/{$inG2}")->assertNotFound();
    $missing = Alum::call($s['tenant'], $s['teacher'], 'get', '/group-change-requests/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();

    expect($r2->json('detail'))->toBe($missing->json('detail'));

    // Retirar la asignación: en la petición siguiente, nada (RN-ALUM-63).
    Tt::send($s['tenant'], $s['admin'], 'DELETE', "/teaching-assignments/{$assignment}")->assertNoContent();
    expect(gcrIds(gcrList($s, $s['teacher'])))->toBe([]);
    Alum::call($s['tenant'], $s['teacher'], 'get', "/group-change-requests/{$inG1}")->assertNotFound();

    // `unidad_familiar`: los alumnos y su hijo existen y el vínculo es activo, pero las solicitudes son un trámite interno.
    $unit = Fam::unitId($s['tenant'], $s['admin'], 'U1');
    $person = Alum::person($s['tenant'], $s['students'][0]['person']['public_id']);
    Fam::addStudent($s['tenant'], $s['admin'], $unit, $person)->assertCreated();
    $tutor = Alum::userWithScopes($s['tenant'], $s['admin'], 'tutor', ['matricula.leer' => 'unidad_familiar', 'familia.leer' => 'unidad_familiar', 'vinculo_tutor.leer' => 'unidad_familiar']);
    $guardian = Fam::guardian($s['tenant'], $s['admin'], $tutor, $unit)->assertCreated()->json('public_id');
    Fam::link($s['tenant'], $s['admin'], $guardian, $person)->assertCreated();

    // Control: sí ve la matrícula de su hijo; las solicitudes, ninguna.
    Alum::call($s['tenant'], $tutor, 'get', "/enrollments/{$s['enrollments'][0]}")->assertOk();

    expect(gcrIds(gcrList($s, $tutor)))->toBe([]);
    Alum::call($s['tenant'], $tutor, 'get', "/group-change-requests/{$inG1}")->assertNotFound();
});

test('CA-ALUM-018c INV-001 RN-ALUM-24: con dos centros equivalentes, ningún endpoint de solicitudes devuelve, modifica ni cuenta las del otro, ni por public_id ajeno (404)', function (): void {
    $a = gcrScenario('gcr-018c-a');
    $b = gcrScenario('gcr-018c-b');
    $idA = gcrCreated($a);
    $idB = gcrCreated($b);

    // Cada centro solo ve la suya.
    expect(gcrIds(gcrList($a, $a['admin'])))->toBe([$idA])
        ->and(gcrIds(gcrList($b, $b['admin'])))->toBe([$idB]);

    // Un id ajeno es 404 en detalle y en las tres transiciones (la solicitud de B no cambia), también con el año de B.
    Alum::call($a['tenant'], $a['admin'], 'get', "/group-change-requests/{$idB}")->assertNotFound();
    gcrAct($a, $idB, 'approval', $a['admin'])->assertNotFound();
    gcrAct($a, $idB, 'rejection', $a['admin'], ['decision_note' => 'n'])->assertNotFound();
    gcrAct($a, $idB, 'cancellation', $a['admin'])->assertNotFound();
    Alum::call($a['tenant'], $a['admin'], 'get', "/group-change-requests?academic_year={$b['year']->public_id}")->assertNotFound();

    // Solicitar con la matrícula o el grupo de otro centro tampoco crea nada ni revela su existencia.
    gcrRequest($a, ['enrollment' => $b['enrollments'][1], 'to_group' => $a['groupB'], 'reason' => 'x'], $a['admin'])->assertNotFound();
    expect(Alum::errorCode(gcrRequest($a, ['enrollment' => $a['enrollments'][1], 'to_group' => $b['groupB'], 'reason' => 'x'], $a['admin'])->assertUnprocessable(), 'to_group'))->toBe('alum.validation.parent_not_found');

    expect(Alum::row($b['tenant'], 'group_change_requests', $idB)->status)->toBe('pendiente')
        ->and(Alum::count($a['tenant'], 'group_change_requests'))->toBe(1)
        ->and(Alum::count($b['tenant'], 'group_change_requests'))->toBe(1)
        ->and(gcrGroupOf($b, $b['enrollments'][0]))->toBe(gcrGroupId($b, $b['api']['group']));
});
