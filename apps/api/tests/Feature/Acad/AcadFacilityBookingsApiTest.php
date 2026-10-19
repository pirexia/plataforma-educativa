<?php

use App\Models\AuditLog;
use App\Models\PermissionRole;
use App\Models\Role;
use App\Modules\Core\Application\ProvisionTenantDefaults;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-002 (1.12-b), docs/modulos/REQ-ACAD/api.md §12, funcional.md §18.4.6
// (RN-ACAD-140 a -143), §18.9: reservas de aulas y recursos. CA-ACAD-138
// (alta y conflictos), CA-ACAD-139 (cancelar con ámbito propios o todos),
// CA-ACAD-140 (curso cerrado), CA-ACAD-131/-132/-133 (acceso, aislamiento y
// siembra de `reservas`), CA-ACAD-116 (espacio con reservas). INV-001, INV-002,
// INV-003, INV-010, INV-015. El año de los tests va del 2026-09-01 al 2027-06-30.
// El 2026-11-12 es jueves; el 2026-11-16, lunes.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Escenario con un recurso (carro de portátiles) además del aula A21 del escenario base.
 *
 * @return array<string, mixed>
 */
function bkScenario(string $slug): array
{
    $s = Tt::scenario($slug);
    $s['resource'] = Tt::postFacility($s['tenant'], $s['admin'], ['facility_type' => 'recurso', 'code' => 'CARRO', 'name' => 'Carro de portátiles'])->assertCreated()->json('public_id');
    $s['yearId'] = $s['year']->public_id;

    return $s;
}

/** Código del primer error de un campo de una respuesta 422. */
function bkCode($response, string $field): ?string
{
    return Tt::errorCode($response->assertStatus(422), $field);
}

test('CA-ACAD-138 RN-ACAD-140 INV-003: reservar un recurso es 201 con Location, el espacio, la fecha, las horas HH:MM y el reservante, y deja una entrada created; el detalle y el listado lo devuelven', function (): void {
    $s = bkScenario('bk-138a');
    $t = $s['tenant'];
    $a = $s['admin'];

    $r = Tt::postBooking($t, $a, $s['yearId'], $s['resource'])->assertCreated();

    expect($r->json('public_id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($r->headers->get('Location'))->toEndWith('/api/v1/facility-bookings/'.$r->json('public_id'))
        ->and($r->json('academic_year'))->toBe($s['yearId'])
        ->and($r->json('facility'))->toBe(['public_id' => $s['resource'], 'facility_type' => 'recurso', 'code' => 'CARRO', 'name' => 'Carro de portátiles', 'deleted' => false])
        ->and($r->json('booked_on'))->toBe('2026-11-12')
        ->and($r->json('starts_at'))->toBe('10:00')
        ->and($r->json('ends_at'))->toBe('11:00')
        ->and($r->json('booked_by'))->toBe(['public_id' => $a->public_id, 'name' => $r->json('booked_by.name')])
        ->and($r->json('booked_by.name'))->not->toBe('')
        ->and(array_keys($r->json()))->not->toContain('id', 'tenant_id', 'academic_year_id', 'facility_id', 'created_by', 'deleted_at');

    app(TenantContext::class)->runFor($t->id, fn () => expect(AuditLog::query()->where('auditable_type', 'facility_booking')->where('event', 'created')->count())->toBe(1));

    expect(Tt::send($t, $a, 'GET', '/facility-bookings/'.$r->json('public_id'))->assertOk()->json())->toBe($r->json())
        ->and(Tt::send($t, $a, 'GET', '/facility-bookings')->assertOk()->json('data.0.public_id'))->toBe($r->json('public_id'));

    // Un aula también es reservable (sin sesiones, sin choque).
    Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['starts_at' => '09:00', 'ends_at' => '09:30'])->assertCreated();
});

test('CA-ACAD-138 RN-ACAD-141: otra reserva del mismo espacio, fecha e intervalo solapado es 422 booking_conflict (kind booking) y no escribe; contiguas, otra fecha, otro espacio o la otra cancelada no chocan', function (): void {
    $s = bkScenario('bk-138b');
    $t = $s['tenant'];
    $a = $s['admin'];

    $first = Tt::postBooking($t, $a, $s['yearId'], $s['resource'])->assertCreated()->json('public_id');

    foreach ([['10:30', '11:30'], ['09:00', '10:01'], ['10:15', '10:45'], ['09:00', '12:00']] as [$from, $to]) {
        $r = Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['starts_at' => $from, 'ends_at' => $to])->assertStatus(422);

        expect(Tt::errorCode($r, 'booked_on'))->toBe('acad.validation.booking_conflict', "{$from}-{$to}")
            ->and($r->json('errors.booked_on.0.params.conflicts'))->toBe([['kind' => 'booking', 'booking' => $first]], "{$from}-{$to}");
    }

    expect(Tt::count($t, 'facility_bookings'))->toBe(1);

    // Semiabierto: 11:00-12:00 y 09:00-10:00 siguen a 10:00-11:00 sin solapar; otra fecha y otro espacio, tampoco.
    Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['starts_at' => '11:00', 'ends_at' => '12:00'])->assertCreated();
    Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['starts_at' => '09:00', 'ends_at' => '10:00'])->assertCreated();
    Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['booked_on' => '2026-11-13'])->assertCreated();
    Tt::postBooking($t, $a, $s['yearId'], $s['facility'])->assertCreated();

    // Cancelada la primera, su hueco queda libre.
    Tt::send($t, $a, 'DELETE', "/facility-bookings/{$first}")->assertNoContent();
    Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['starts_at' => '10:15', 'ends_at' => '10:45'])->assertCreated();
});

test('CA-ACAD-138 RN-ACAD-141: reservar un aula a la hora de una sesión del marco vigente ese día es 422 booking_conflict (kind session); otro día de la semana, otra hora o un recurso no chocan', function (): void {
    $s = bkScenario('bk-138c');
    $t = $s['tenant'];
    $a = $s['admin'];
    // Sesión del lunes 08:30-09:25 en el aula A21.
    $session = Tt::postSession($t, $a, $s['slots'][0], Tt::postAssignment($t, $a, $s['t1'], $s['api']['group'], $s['api']['subject'])->json('public_id'), $s['facility'])->assertCreated()->json('public_id');

    $r = Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['booked_on' => '2026-11-16', 'starts_at' => '09:00', 'ends_at' => '10:00'])->assertStatus(422);
    expect(Tt::errorCode($r, 'booked_on'))->toBe('acad.validation.booking_conflict')
        ->and($r->json('errors.booked_on.0.params.conflicts'))->toBe([['kind' => 'session', 'session' => $session]])
        ->and(Tt::count($t, 'facility_bookings'))->toBe(0);

    // El martes (otro weekday), tras la sesión (09:25 es el fin, semiabierto) y el recurso a esa hora: libres.
    Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['booked_on' => '2026-11-17', 'starts_at' => '08:30', 'ends_at' => '09:25'])->assertCreated();
    Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['booked_on' => '2026-11-16', 'starts_at' => '09:25', 'ends_at' => '10:00'])->assertCreated();
    Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['booked_on' => '2026-11-16', 'starts_at' => '08:30', 'ends_at' => '09:25'])->assertCreated();

    // RN-ACAD-143: una sesión creada después de una reserva no se valida contra reservas (la del martes 17, 08:30-09:25).
    $other = Tt::postAssignment($t, $a, $s['t2'], $s['api']['group'], $s['api']['subject'], $s['api']['subgroup'])->assertCreated()->json('public_id');
    Tt::postSession($t, $a, $s['slots'][1], $other, $s['facility'])->assertCreated();
});

test('CA-ACAD-138 RN-ACAD-141 RN-ACAD-124: la sesión que cuenta es la del marco vigente ese día: durante un especial de la etapa, el ordinario no ocupa el aula y sí las sesiones del especial', function (): void {
    $s = bkScenario('bk-138d');
    $t = $s['tenant'];
    $a = $s['admin'];
    Tt::postSession($t, $a, $s['slots'][0], Tt::postAssignment($t, $a, $s['t1'], $s['api']['group'], $s['api']['subject'])->json('public_id'), $s['facility'])->assertCreated();

    // Jornada especial de ESO del 7 al 11 de diciembre, con una sesión del lunes 10:00-10:55 en el mismo aula.
    $special = Tt::postFrame($t, $a, Tt::frameBody($s['api']['level'], [
        'frame_type' => 'especial', 'name_translations' => ['es-ES' => 'Jornada intensiva'],
        'valid_from' => '2026-12-07', 'valid_to' => '2026-12-11',
        'slots' => [['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '10:55']],
    ]))->assertCreated()->json();
    $groupB = Tt::send($t, $a, 'POST', '/groups', ['course' => $s['api']['course'], 'code' => 'B', 'name' => 'Grupo B'])->assertCreated()->json('public_id');
    Tt::send($t, $a, 'PUT', "/groups/{$groupB}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    $inSpecial = Tt::postSession($t, $a, $special['slots'][0]['public_id'], Tt::postAssignment($t, $a, $s['t2'], $groupB, $s['api']['subject'])->json('public_id'), $s['facility'])->assertCreated()->json('public_id');

    $conflict = fn (string $date, string $from, string $to) => Tt::errorCode(Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['booked_on' => $date, 'starts_at' => $from, 'ends_at' => $to])->assertStatus(422), 'booked_on');

    // Fuera de la jornada (lunes 30 nov) manda el ordinario; dentro (lunes 7 dic), el especial.
    expect($conflict('2026-11-30', '08:30', '09:00'))->toBe('acad.validation.booking_conflict')
        ->and(Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['booked_on' => '2026-12-07', 'starts_at' => '08:30', 'ends_at' => '09:00'])->assertCreated()->json('public_id'))->not->toBeNull()
        ->and($conflict('2026-12-07', '10:30', '11:00'))->toBe('acad.validation.booking_conflict');

    $r = Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['booked_on' => '2026-12-07', 'starts_at' => '10:30', 'ends_at' => '11:00'])->assertStatus(422);
    expect($r->json('errors.booked_on.0.params.conflicts'))->toBe([['kind' => 'session', 'session' => $inSpecial]]);

    // Y la sesión del especial no ocupa el aula fuera de su vigencia (lunes 30 nov a las 10:30).
    Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['booked_on' => '2026-11-30', 'starts_at' => '10:30', 'ends_at' => '11:00'])->assertCreated();
});

test('CA-ACAD-138 RN-ACAD-140: una fecha fuera del curso es 422 booking_outside_academic_year con las fechas del curso; los extremos del curso son válidos', function (): void {
    $s = bkScenario('bk-138e');
    $t = $s['tenant'];
    $a = $s['admin'];

    foreach (['2026-08-31', '2027-07-01', '2030-01-01'] as $date) {
        $r = Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['booked_on' => $date])->assertStatus(422);

        expect(Tt::errorCode($r, 'booked_on'))->toBe('acad.validation.booking_outside_academic_year', $date)
            ->and($r->json('errors.booked_on.0.params'))->toBe(['academic_year_starts_on' => '2026-09-01', 'academic_year_ends_on' => '2027-06-30']);
    }

    Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['booked_on' => '2026-09-01'])->assertCreated();
    Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['booked_on' => '2027-06-30'])->assertCreated();
    expect(Tt::count($t, 'facility_bookings'))->toBe(2);
});

test('RN-ACAD-140 INV-010: validación del cuerpo: curso, espacio, fecha y horas, cada una con su código; sin tocar la base', function (): void {
    $s = bkScenario('bk-val');
    $t = $s['tenant'];
    $a = $s['admin'];
    $body = Tt::bookingBody($s['yearId'], $s['resource']);
    $post = fn (array $override) => Tt::send($t, $a, 'POST', '/facility-bookings', $override + $body);

    expect(bkCode($post(['academic_year' => null]), 'academic_year'))->toBe('acad.validation.academic_year_required')
        ->and(bkCode($post(['academic_year' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']), 'academic_year'))->toBe('acad.validation.academic_year_not_found')
        ->and(bkCode($post(['facility' => null]), 'facility'))->toBe('acad.validation.facility_not_found')
        ->and(bkCode($post(['facility' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']), 'facility'))->toBe('acad.validation.facility_not_found')
        ->and(bkCode($post(['booked_on' => '2026-02-31']), 'booked_on'))->toBe('acad.validation.date_invalid')
        ->and(bkCode($post(['booked_on' => null]), 'booked_on'))->toBe('acad.validation.date_invalid')
        ->and(bkCode($post(['starts_at' => '8:5']), 'starts_at'))->toBe('acad.validation.time_invalid')
        ->and(bkCode($post(['ends_at' => '24:00']), 'ends_at'))->toBe('acad.validation.time_invalid')
        ->and(bkCode($post(['starts_at' => '11:00', 'ends_at' => '10:00']), 'ends_at'))->toBe('acad.validation.slot_ends_before_start')
        ->and(bkCode($post(['starts_at' => '10:00', 'ends_at' => '10:00']), 'ends_at'))->toBe('acad.validation.slot_ends_before_start')
        ->and(Tt::count($t, 'facility_bookings'))->toBe(0);

    // Un espacio borrado no se puede reservar.
    Tt::send($t, $a, 'DELETE', "/facilities/{$s['resource']}")->assertNoContent();
    expect(bkCode($post([]), 'facility'))->toBe('acad.validation.facility_not_found');

    // Sin PATCH ni PUT: la ruta no existe para esos verbos.
    $booking = Tt::postBooking($t, $a, $s['yearId'], $s['facility'])->assertCreated()->json('public_id');
    Tt::send($t, $a, 'PATCH', "/facility-bookings/{$booking}", ['starts_at' => '12:00'])->assertStatus(405);
});

test('CA-ACAD-138 api.md §12.4: el listado filtra por espacio y por fechas inclusivas, ordena por fecha y hora, pagina y ve vacía una consulta de un espacio inexistente', function (): void {
    $s = bkScenario('bk-list');
    $t = $s['tenant'];
    $a = $s['admin'];
    $make = fn (string $facility, string $date, string $from, string $to): string => Tt::postBooking($t, $a, $s['yearId'], $facility, ['booked_on' => $date, 'starts_at' => $from, 'ends_at' => $to])->assertCreated()->json('public_id');

    $late = $make($s['resource'], '2026-11-13', '09:00', '10:00');
    $second = $make($s['resource'], '2026-11-12', '12:00', '13:00');
    $first = $make($s['resource'], '2026-11-12', '08:00', '09:00');
    $room = $make($s['facility'], '2026-11-12', '08:00', '09:00');
    $ids = fn (string $query) => array_column(Tt::send($t, $a, 'GET', '/facility-bookings'.$query)->assertOk()->json('data'), 'public_id');

    expect($ids(''))->toBe([$first, $room, $second, $late])
        ->and($ids('?facility='.$s['resource']))->toBe([$first, $second, $late])
        ->and($ids('?facility='.$s['resource'].','.$s['facility'].'&booked_from=2026-11-13'))->toBe([$late])
        ->and($ids('?booked_to=2026-11-12'))->toBe([$first, $room, $second])
        ->and($ids('?booked_from=2026-11-12&booked_to=2026-11-12&facility='.$s['facility']))->toBe([$room])
        ->and($ids('?facility=01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBe([])
        ->and($ids('?academic_year='.$s['yearId']))->toBe([$first, $room, $second, $late])
        ->and(Tt::send($t, $a, 'GET', '/facility-bookings?per_page=2&page=2')->assertOk()->json('meta.total'))->toBe(4);

    Tt::send($t, $a, 'GET', '/facility-bookings?booked_from=12-11-2026')->assertStatus(422);
    Tt::send($t, $a, 'GET', '/facility-bookings?per_page=101')->assertStatus(422);
});

test('CA-ACAD-139 RN-ACAD-142 OPEN-ACAD-42: con reservas.eliminar de ámbito propios, cancelar una reserva propia es 204 y una ajena 404 (igual que una inexistente); con ámbito todos, ambas 204; la ajena sigue visible', function (): void {
    $s = bkScenario('bk-139');
    $t = $s['tenant'];
    $a = $s['admin'];
    $grants = ['reservas.leer' => 'todos', 'reservas.crear' => 'todos', 'reservas.eliminar' => 'propios'];
    $ana = Api::userWithScopes($t, $a, 'ana139', $grants);
    $beto = Api::userWithScopes($t, $a, 'beto139', $grants);

    $mine = Tt::postBooking($t, $ana, $s['yearId'], $s['resource'])->assertCreated()->json('public_id');
    $his = Tt::postBooking($t, $beto, $s['yearId'], $s['resource'], ['starts_at' => '12:00', 'ends_at' => '13:00'])->assertCreated();
    expect($his->json('booked_by.public_id'))->toBe($beto->public_id);
    $his = $his->json('public_id');

    // Ajena: 404 como una inexistente; la ve (leer es todos) y sigue ahí.
    $foreign = Tt::send($t, $ana, 'DELETE', "/facility-bookings/{$his}")->assertNotFound();
    $missing = Tt::send($t, $ana, 'DELETE', '/facility-bookings/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    expect($foreign->json('title'))->toBe($missing->json('title'))
        ->and($foreign->json('detail'))->toBe($missing->json('detail'))
        ->and($foreign->json('type'))->toBe($missing->json('type'))
        ->and(Tt::send($t, $ana, 'GET', "/facility-bookings/{$his}")->assertOk()->json('booked_by.public_id'))->toBe($beto->public_id)
        ->and(Tt::count($t, 'facility_bookings'))->toBe(2);

    Tt::send($t, $ana, 'DELETE', "/facility-bookings/{$mine}")->assertNoContent();
    expect(Tt::count($t, 'facility_bookings'))->toBe(1);
    app(TenantContext::class)->runFor($t->id, fn () => expect(AuditLog::query()->where('auditable_type', 'facility_booking')->where('event', 'deleted')->count())->toBe(1));

    // Con ámbito todos (el administrador), la ajena también.
    Tt::send($t, $a, 'DELETE', "/facility-bookings/{$his}")->assertNoContent();
    expect(Tt::count($t, 'facility_bookings'))->toBe(0);
});

test('CA-ACAD-139 permisos.md §10.2 CA-ACAD-133: reservas.eliminar admite propios y todos; leer y crear solo todos; grupo, clase y departamento en cualquiera es 422 y no guarda el rol', function (): void {
    [$tenant, $admin] = provisionCoreTenant('bk-139b');
    contractModule($tenant, 'acad');
    $role = fn (string $code, string $scope) => (function () use ($tenant, $admin, $code, $scope) {
        resetSessionState();

        return test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => 'rol_'.str_replace('.', '_', $code).'_'.$scope, 'name' => "R {$code} {$scope}",
            'permissions' => [['code' => $code, 'effect' => 'allow', 'scope' => $scope]],
        ]);
    })();

    $role('reservas.eliminar', 'propios')->assertCreated();
    $role('reservas.eliminar', 'todos')->assertCreated();

    foreach (['reservas.leer', 'reservas.crear'] as $code) {
        $role($code, 'propios')->assertStatus(422);
        $role($code, 'todos')->assertCreated();
    }

    foreach (['reservas.leer', 'reservas.crear', 'reservas.eliminar'] as $code) {
        foreach (['grupo', 'clase', 'departamento'] as $scope) {
            $role($code, $scope)->assertStatus(422);
        }
    }

    app(TenantContext::class)->runFor($tenant->id, fn () => expect(Role::query()->where('code', 'like', 'rol_reservas_%')->count())->toBe(4));
});

test('CA-ACAD-140 RN-ACAD-90 CA-ACAD-128 CA-ACAD-129: con el curso cerrado, crear o cancelar una reserva es 409 academic-year-closed y nada cambia; leerlas sin curso_historico.leer es 404, con él, los datos', function (): void {
    $s = bkScenario('bk-140');
    $t = $s['tenant'];
    $a = $s['admin'];
    $booking = Tt::postBooking($t, $a, $s['yearId'], $s['resource'])->assertCreated()->json('public_id');
    $reader = Api::userWith($t, $a, 'lector140', ['reservas.leer']);
    $historian = Api::userWith($t, $a, 'historico140', ['reservas.leer', 'curso_historico.leer']);

    CursoTestHelpers::setStatus($t, $s['year'], AcademicYearStatus::Cerrado);

    foreach ([
        Tt::postBooking($t, $a, $s['yearId'], $s['resource'], ['starts_at' => '12:00', 'ends_at' => '13:00']),
        Tt::send($t, $a, 'DELETE', "/facility-bookings/{$booking}"),
    ] as $response) {
        $response->assertStatus(409);
        expect($response->json('type'))->toBe('urn:pge:error:academic-year-closed');
    }

    expect(Tt::count($t, 'facility_bookings'))->toBe(1);

    Tt::send($t, $reader, 'GET', "/facility-bookings?academic_year={$s['yearId']}")->assertNotFound();
    Tt::send($t, $reader, 'GET', "/facility-bookings/{$booking}")->assertNotFound();
    // Sin curso_historico.leer, el curso del cuerpo es indistinguible de uno inexistente (S-3).
    $creator = Api::userWith($t, $a, 'creador140', ['reservas.crear']);
    expect(bkCode(Tt::postBooking($t, $creator, $s['yearId'], $s['resource']), 'academic_year'))->toBe('acad.validation.academic_year_not_found');

    expect(Tt::send($t, $historian, 'GET', "/facility-bookings?academic_year={$s['yearId']}")->assertOk()->json('data.0.public_id'))->toBe($booking)
        ->and(Tt::send($t, $historian, 'GET', "/facility-bookings/{$booking}")->assertOk()->json('public_id'))->toBe($booking);
});

test('CA-ACAD-116 RN-ACAD-102: un espacio con reservas vivas en un curso activo no se borra (409 has_dependents con facility_bookings); con reservas solo en un curso cerrado sí, y la reserva se sigue leyendo con facility.deleted true', function (): void {
    $s = bkScenario('bk-116');
    $t = $s['tenant'];
    $a = $s['admin'];
    $booking = Tt::postBooking($t, $a, $s['yearId'], $s['resource'])->assertCreated()->json('public_id');

    $r = Tt::send($t, $a, 'DELETE', "/facilities/{$s['resource']}")->assertStatus(409);
    expect($r->json('errors.dependents.0.params.dependents'))->toBe(['facility_bookings']);

    // Con sesiones y reservas a la vez, los dos dependientes, en ese orden.
    Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['booked_on' => '2026-11-17'])->assertCreated();
    Tt::postSession($t, $a, $s['slots'][0], Tt::postAssignment($t, $a, $s['t1'], $s['api']['group'], $s['api']['subject'])->json('public_id'), $s['facility'])->assertCreated();
    expect(Tt::send($t, $a, 'DELETE', "/facilities/{$s['facility']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))->toBe(['timetable_sessions', 'facility_bookings']);

    // Cancelada la reserva, el recurso se borra.
    Tt::send($t, $a, 'DELETE', "/facility-bookings/{$booking}")->assertNoContent();
    Tt::send($t, $a, 'DELETE', "/facilities/{$s['resource']}")->assertNoContent();

    // Con el curso cerrado, una reserva viva no bloquea el borrado del recurso.
    $other = Tt::postFacility($t, $a, ['facility_type' => 'recurso', 'code' => 'PROY', 'name' => 'Proyector'])->assertCreated()->json('public_id');
    $kept = Tt::postBooking($t, $a, $s['yearId'], $other)->assertCreated()->json('public_id');
    CursoTestHelpers::setStatus($t, $s['year'], AcademicYearStatus::Cerrado);
    Tt::send($t, $a, 'DELETE', "/facilities/{$other}")->assertNoContent();

    expect(Tt::send($t, $a, 'GET', "/facility-bookings/{$kept}")->assertOk()->json('facility'))->toBe(['public_id' => $other, 'facility_type' => 'recurso', 'code' => 'PROY', 'name' => 'Proyector', 'deleted' => true]);
});

test('CA-ACAD-132 INV-001: con dos centros equivalentes, ningún endpoint de reservas lee, cuenta ni cancela las del otro; un public_id ajeno en ruta es 404 y en cuerpo (curso, espacio), 422 de su campo', function (): void {
    $a = bkScenario('bk-iso-a');
    $b = bkScenario('bk-iso-b');
    $bookingA = Tt::postBooking($a['tenant'], $a['admin'], $a['yearId'], $a['resource'])->assertCreated()->json('public_id');
    $bookingB = Tt::postBooking($b['tenant'], $b['admin'], $b['yearId'], $b['resource'])->assertCreated()->json('public_id');

    Tt::send($b['tenant'], $b['admin'], 'GET', "/facility-bookings/{$bookingA}")->assertNotFound();
    Tt::send($b['tenant'], $b['admin'], 'DELETE', "/facility-bookings/{$bookingA}")->assertNotFound();

    $list = Tt::send($b['tenant'], $b['admin'], 'GET', '/facility-bookings')->assertOk();
    expect(array_column($list->json('data'), 'public_id'))->toBe([$bookingB])
        ->and($list->json('meta.total'))->toBe(1)
        ->and(array_column(Tt::send($b['tenant'], $b['admin'], 'GET', '/facility-bookings?facility='.$a['resource'])->assertOk()->json('data'), 'public_id'))->toBe([]);

    Tt::send($b['tenant'], $b['admin'], 'GET', "/facility-bookings?academic_year={$a['yearId']}")->assertNotFound();

    // En cuerpo: el espacio y el curso del otro centro, y un conflicto no se filtra entre centros.
    expect(bkCode(Tt::postBooking($b['tenant'], $b['admin'], $b['yearId'], $a['resource']), 'facility'))->toBe('acad.validation.facility_not_found')
        ->and(bkCode(Tt::postBooking($b['tenant'], $b['admin'], $a['yearId'], $b['resource']), 'academic_year'))->toBe('acad.validation.academic_year_not_found');
    Tt::postBooking($b['tenant'], $b['admin'], $b['yearId'], $b['resource'], ['starts_at' => '12:00', 'ends_at' => '13:00'])->assertCreated();

    expect(Tt::count($a['tenant'], 'facility_bookings'))->toBe(1)->and(Tt::count($b['tenant'], 'facility_bookings'))->toBe(2);
});

test('CA-ACAD-131 INV-002: los cuatro endpoints de reservas responden 401 sin sesión y 403 sin su permiso; un permiso de horarios, espacios o estructura no basta; con acad descontratado, 403 module-disabled', function (): void {
    $s = bkScenario('bk-131');
    $t = $s['tenant'];
    $a = $s['admin'];
    $booking = Tt::postBooking($t, $a, $s['yearId'], $s['resource'])->assertCreated()->json('public_id');
    $body = Tt::bookingBody($s['yearId'], $s['resource'], ['starts_at' => '12:00', 'ends_at' => '13:00']);
    $endpoints = [
        ['GET', '/facility-bookings', [], 'leer'],
        ['POST', '/facility-bookings', $body, 'crear'],
        ['GET', "/facility-bookings/{$booking}", [], 'leer'],
        ['DELETE', "/facility-bookings/{$booking}", [], 'eliminar'],
    ];
    $users = [];

    foreach (['leer', 'crear', 'eliminar'] as $only) {
        $users[$only] = Api::userWith($t, $a, "solo_{$only}", ["reservas.{$only}"]);
    }

    $others = Api::userWith($t, $a, 'ajeno131', ['horarios.leer', 'horarios.crear', 'horarios.actualizar', 'horarios.eliminar', 'espacios.leer', 'espacios.crear', 'estructura_academica.leer', 'asignacion_docente.leer']);

    foreach ($endpoints as [$method, $path, $payload, $needs]) {
        $url = Api::url($t, $path);

        resetSessionState();
        test()->json($method, $url, $payload)->assertUnauthorized();

        foreach (array_diff(array_keys($users), [$needs]) as $lacking) {
            resetSessionState();
            test()->actingAs($users[$lacking])->json($method, $url, $payload)->assertForbidden();
        }

        resetSessionState();
        test()->actingAs($others)->json($method, $url, $payload)->assertForbidden();
    }

    // Sin acad contratado, 403 module-disabled antes del permiso, también para el administrador.
    [$tenant2, $admin2] = provisionCoreTenant('bk-131b');
    $id = '01JA0000000000000000000001';

    foreach ([['GET', '/facility-bookings'], ['POST', '/facility-bookings'], ['GET', "/facility-bookings/{$id}"], ['DELETE', "/facility-bookings/{$id}"]] as [$method, $path]) {
        resetSessionState();
        $response = test()->actingAs($admin2)->json($method, Api::url($tenant2, $path), [])->assertForbidden();

        expect($response->json('type'))->toBe('urn:pge:error:module-disabled', "{$method} {$path}");
    }
});

/** @return list<string> los permisos de `reservas` de un rol predefinido con su ámbito, ordenados */
function bkGrantsOf(string $roleCode): array
{
    $role = Role::where('code', $roleCode)->firstOrFail();

    return PermissionRole::where('role_id', $role->id)->where('permission_code', 'like', 'reservas.%')
        ->get()->map(fn ($g): string => "{$g->permission_code}:{$g->scope}")->sort()->values()->all();
}

test('CA-ACAD-133 permisos.md §10.3 OPEN-ACAD-42 = X: tenant:provision-defaults siembra reservas exactamente como la tabla (administrador y direccion los tres; secretaria y orientador leer; docente y tutor_grupo leer, crear y eliminar con propios; el resto, nada)', function (): void {
    [$tenant] = provisionCoreTenant('bk-133');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $expected = [
            'administrador_centro' => ['reservas.crear:todos', 'reservas.eliminar:todos', 'reservas.leer:todos'],
            'direccion' => ['reservas.crear:todos', 'reservas.eliminar:todos', 'reservas.leer:todos'],
            'secretaria' => ['reservas.leer:todos'],
            'orientador' => ['reservas.leer:todos'],
            'docente' => ['reservas.crear:todos', 'reservas.eliminar:propios', 'reservas.leer:todos'],
            'tutor_grupo' => ['reservas.crear:todos', 'reservas.eliminar:propios', 'reservas.leer:todos'],
        ];

        foreach ($expected as $role => $grants) {
            expect(bkGrantsOf($role))->toBe($grants, $role);
        }

        $others = Role::where('is_system', true)->whereNotIn('code', array_keys($expected))->pluck('code');

        expect($others)->not->toBeEmpty();

        foreach ($others as $code) {
            expect(bkGrantsOf($code))->toBe([], $code);
        }
    });

    expect(ProvisionTenantDefaults::STRUCTURE_PERMISSION_PROPIOS_GRANTS)->toBe(['docente' => ['reservas.eliminar'], 'tutor_grupo' => ['reservas.eliminar']]);
});

test('CA-ACAD-133 operacion.md §3: acad:grant-structure-permissions concede reservas a un centro existente (con propios), es idempotente y no toca una concesión previa con otro ámbito', function (): void {
    [$tenant] = provisionCoreTenant('bk-133b');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        PermissionRole::query()->where('permission_code', 'like', 'reservas.%')->get()->each->delete();
    });

    test()->artisan('acad:grant-structure-permissions')->assertSuccessful();
    $first = app(TenantContext::class)->runFor($tenant->id, fn () => [bkGrantsOf('docente'), bkGrantsOf('direccion'), bkGrantsOf('secretaria'), PermissionRole::count()]);
    test()->artisan('acad:grant-structure-permissions')->assertSuccessful();
    $second = app(TenantContext::class)->runFor($tenant->id, fn () => [bkGrantsOf('docente'), bkGrantsOf('direccion'), bkGrantsOf('secretaria'), PermissionRole::count()]);

    expect($second)->toBe($first)
        ->and($first[0])->toBe(['reservas.crear:todos', 'reservas.eliminar:propios', 'reservas.leer:todos'])
        ->and($first[1])->toBe(['reservas.crear:todos', 'reservas.eliminar:todos', 'reservas.leer:todos'])
        ->and($first[2])->toBe(['reservas.leer:todos']);

    // Un centro que ya había ampliado a `docente` con eliminar de ámbito todos conserva su decisión.
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $grant = PermissionRole::query()->where('permission_code', 'reservas.eliminar')->where('role_id', Role::where('code', 'docente')->value('id'))->firstOrFail();
        $grant->scope = 'todos';
        $grant->save();
    });
    test()->artisan('acad:grant-structure-permissions')->assertSuccessful();

    app(TenantContext::class)->runFor($tenant->id, fn () => expect(bkGrantsOf('docente'))->toBe(['reservas.crear:todos', 'reservas.eliminar:todos', 'reservas.leer:todos']));
});

test('REQ-ACAD-002 api.md §1: el esquema FacilityBooking de OpenAPI describe exactamente las claves que la API devuelve', function (): void {
    $s = bkScenario('bk-openapi');
    $schemas = Yaml::parseFile(base_path('openapi/components.yaml'))['components']['schemas'];
    $body = Tt::postBooking($s['tenant'], $s['admin'], $s['yearId'], $s['resource'])->assertCreated()->json();

    expect(array_keys($body))->toEqualCanonicalizing(array_keys($schemas['FacilityBooking']['properties']))
        ->and($schemas['FacilityBooking']['required'])->toEqualCanonicalizing(array_keys($body))
        ->and(array_keys($body['facility']))->toEqualCanonicalizing(array_keys($schemas['FacilityBooking']['properties']['facility']['properties']));
});

test('CA-ACAD-138 RN-ACAD-141 (M1): quien tiene reservas.crear sin reservas.leer ni horarios.leer recibe booking_conflict solo con el tipo de cada conflicto, sin identificadores; con los permisos de lectura, con ellos', function (): void {
    $s = bkScenario('bk-m1');
    $t = $s['tenant'];
    $a = $s['admin'];
    $session = Tt::postSession($t, $a, $s['slots'][0], Tt::postAssignment($t, $a, $s['t1'], $s['api']['group'], $s['api']['subject'])->json('public_id'), $s['facility'])->assertCreated()->json('public_id');
    $booking = Tt::postBooking($t, $a, $s['yearId'], $s['facility'], ['booked_on' => '2026-11-16', 'starts_at' => '10:00', 'ends_at' => '11:00'])->assertCreated()->json('public_id');
    $blind = Api::userWith($t, $a, 'ciego_m1', ['reservas.crear']);
    $partial = Api::userWith($t, $a, 'parcial_m1', ['reservas.crear', 'reservas.leer']);
    $body = ['booked_on' => '2026-11-16', 'starts_at' => '08:30', 'ends_at' => '10:30'];
    $conflicts = fn ($user) => Tt::postBooking($t, $user, $s['yearId'], $s['facility'], $body)->assertStatus(422)->json('errors.booked_on.0.params.conflicts');

    expect($conflicts($blind))->toBe([['kind' => 'booking'], ['kind' => 'session']])
        ->and($conflicts($partial))->toBe([['kind' => 'booking', 'booking' => $booking], ['kind' => 'session']])
        ->and($conflicts($a))->toBe([['kind' => 'booking', 'booking' => $booking], ['kind' => 'session', 'session' => $session]]);
});
