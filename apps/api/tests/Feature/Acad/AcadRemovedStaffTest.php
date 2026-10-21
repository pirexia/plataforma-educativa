<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTimetableHelpers as Tt;

// REQ-ACAD-002 (1.12-a/1.12-b), issue #446: CA-ACAD-158 (B-3: un usuario borrado se muestra como
// `acad.staff.removed` y nunca con su nombre, en tutores, sesiones y reservas) y CA-ACAD-159 (B-1: ninguna
// salida de `acad` expone un id interno). Sobre `REQ-PRIV-006` y `ADR-004`. INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Borra (borrado lógico) a un usuario en el contexto de su centro. */
function removeUser(object $tenant, object $user): void
{
    app(TenantContext::class)->runFor($tenant->id, fn () => $user->delete());
}

/**
 * Valores enteros y claves `id` / `*_id` (salvo `public_id`) de cualquier nivel de una respuesta: las dos formas en
 * que se filtraría un id interno (como clave o como número).
 *
 * @return array{keys: list<string>, ints: list<int>}
 */
function internalIdLeaks(mixed $node, string $path = ''): array
{
    $found = ['keys' => [], 'ints' => []];

    foreach (is_array($node) ? $node : [] as $key => $value) {
        if (is_string($key) && $key !== 'public_id' && ($key === 'id' || str_ends_with($key, '_id'))) {
            $found['keys'][] = $path.'.'.$key;
        }

        if (is_int($value)) {
            $found['ints'][] = $value;
        }

        $inner = internalIdLeaks($value, $path.'.'.$key);
        $found['keys'] = [...$found['keys'], ...$inner['keys']];
        $found['ints'] = [...$found['ints'], ...$inner['ints']];
    }

    return $found;
}

test('CA-ACAD-158 RN-ACAD-92 REQ-PRIV-006 ADR-004 (#446 B-3): con el profesor borrado, tutores y sesiones muestran acad.staff.removed y nunca su nombre, y conservan su public_id', function (): void {
    $s = Tt::scenario('rm-158a');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $group = $s['api']['group'];

    Tt::send($tenant, $admin, 'PUT', "/groups/{$group}/tutors", ['tutors' => [$s['t1']->public_id]])->assertOk();
    $assignment = Tt::postAssignment($tenant, $admin, $s['t1'], $group, $s['api']['subject'])->assertCreated()->json('public_id');
    $session = Tt::postSession($tenant, $admin, $s['slots'][0], $assignment)->assertCreated()->json('public_id');

    removeUser($tenant, $s['t1']);

    $tutors = Tt::send($tenant, $admin, 'GET', "/groups/{$group}/tutors")->assertOk();
    $detail = Tt::send($tenant, $admin, 'GET', "/timetable-sessions/{$session}")->assertOk();
    $view = Tt::send($tenant, $admin, 'GET', '/timetable-sessions?academic_year='.$s['year']->public_id.'&group='.$group)->assertOk();

    expect($tutors->json('data.0.name'))->toBe('Usuario eliminado')
        ->and($tutors->json('data.0.public_id'))->toBe($s['t1']->public_id)
        ->and($detail->json('teacher.name'))->toBe('Usuario eliminado')
        ->and($detail->json('teacher.public_id'))->toBe($s['t1']->public_id)
        ->and($view->json('data.0.teacher.name'))->toBe('Usuario eliminado');

    foreach ([$tutors, $detail, $view] as $response) {
        expect($response->getContent())->not->toContain('Persona Prueba')->and($response->getContent())->not->toContain($s['t1']->email);
    }
});

test('CA-ACAD-158 RN-ACAD-140 REQ-PRIV-006 ADR-004 (#446 B-3): con el reservante borrado, la reserva muestra acad.staff.removed en booked_by y nunca su nombre', function (): void {
    $s = Tt::scenario('rm-158b');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $booker = Api::userWith($tenant, $admin, 'booker', ['reservas.leer', 'reservas.crear', 'espacios.leer']);
    $year = $s['year']->public_id;

    $booking = Tt::postBooking($tenant, $booker, $year, $s['facility'])->assertCreated();

    expect($booking->json('booked_by.name'))->toBe('Persona Prueba');

    removeUser($tenant, $booker);

    $detail = Tt::send($tenant, $admin, 'GET', '/facility-bookings/'.$booking->json('public_id'))->assertOk();
    $list = Tt::send($tenant, $admin, 'GET', '/facility-bookings')->assertOk();

    expect($detail->json('booked_by.name'))->toBe('Usuario eliminado')
        ->and($detail->json('booked_by.public_id'))->toBe($booker->public_id)
        ->and($list->json('data.0.booked_by.name'))->toBe('Usuario eliminado');

    foreach ([$detail, $list] as $response) {
        expect($response->getContent())->not->toContain('Persona Prueba')->and($response->getContent())->not->toContain($booker->email);
    }
});

test('CA-ACAD-159 ADR-029 (#446 B-1): sesiones, vista de horario y reservas no exponen ningún id interno (ni como clave ni como número)', function (): void {
    $s = Tt::scenario('rm-159');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $group = $s['api']['group'];
    $assignment = Tt::postAssignment($tenant, $admin, $s['t1'], $group, $s['api']['subject'])->assertCreated()->json('public_id');
    $session = Tt::postSession($tenant, $admin, $s['slots'][0], $assignment, $s['facility'])->assertCreated();
    $detail = Tt::send($tenant, $admin, 'GET', '/timetable-sessions/'.$session->json('public_id'))->assertOk();
    $view = Tt::send($tenant, $admin, 'GET', '/timetable-sessions?academic_year='.$s['year']->public_id.'&group='.$group)->assertOk();
    $booking = Tt::postBooking($tenant, $admin, $s['year']->public_id, $s['facility'], ['booked_on' => '2026-11-17', 'starts_at' => '15:00', 'ends_at' => '16:00'])->assertCreated();
    $bookings = Tt::send($tenant, $admin, 'GET', '/facility-bookings')->assertOk();

    // Ids internos reales de lo que las respuestas referencian.
    $internal = app(TenantContext::class)->runFor($tenant->id, fn (): array => array_map('intval', [
        $s['t1']->id, $s['admin']->id, $s['year']->id,
        ...DB::table('time_slots')->pluck('id')->all(),
        ...DB::table('teaching_assignments')->pluck('id')->all(),
        ...DB::table('timetable_sessions')->pluck('id')->all(),
        ...DB::table('facilities')->pluck('id')->all(),
        ...DB::table('facility_bookings')->pluck('id')->all(),
        ...DB::table('groups')->pluck('id')->all(),
    ]));
    $internal = array_values(array_filter(array_unique($internal), fn (int $id): bool => $id > 31));

    foreach ([$session, $detail, $view, $booking, $bookings] as $response) {
        $leaks = internalIdLeaks($response->json());

        expect($leaks['keys'])->toBe([])
            ->and(array_values(array_intersect($leaks['ints'], $internal)))->toBe([]);
    }
});
