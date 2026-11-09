<?php

use App\Models\User;
use App\Modules\Alum\Domain\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AlumTestHelpers as Alum;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-ALUM-001 (1.15-a T2), docs/modulos/REQ-ALUM/api.md §1, funcional.md §4 y §11.1: alta, listado, ficha, corrección,
// baja y restauración de alumnos por la API. CA-ALUM-001 a -009, -019, -032; RN-ALUM-01 a -10, -27; INV-002, INV-003,
// INV-010, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ALUM-001 RN-ALUM-03: el alta con nombre, fecha de nacimiento y unidad familiar viva crea persona, faceta y pertenencia con sus registros created, y devuelve la ficha sin documento completo', function (): void {
    [$tenant, $admin] = Alum::setup('alum-001');
    $unit = Fam::unitId($tenant, $admin, 'U1');

    $response = Alum::create($tenant, $admin, ['family_name_2' => 'Segundo', 'document_type' => 'dni', 'document_number' => Alum::dni(12345678), 'family_unit' => $unit])->assertCreated();

    $number = Alum::dni(12345678);

    expect($response->json())->toHaveKeys(['public_id', 'person', 'display_name', 'given_name', 'family_name_1', 'family_name_2', 'birth_date', 'locale', 'document_type', 'document_number', 'created_at', 'updated_at'])
        ->and($response->json('given_name'))->toBe('Lucía')
        ->and($response->json('birth_date'))->toBe('2015-04-12')
        ->and($response->json('document_type'))->toBe('dni')
        // Nunca el documento completo: solo los cuatro últimos caracteres.
        ->and($response->json('document_number'))->toBe(str_repeat('•', 5).substr($number, -4))
        ->and($response->json('locale'))->not->toBeNull()
        ->and($response->headers->get('Location'))->toContain('/api/v1/students/'.$response->json('public_id'));

    $membership = Fam::call($tenant, $admin, 'get', "/family-units/{$unit}/students")->assertOk()->json('data');

    expect(array_column($membership, 'student_person'))->toBe([$response->json('person.public_id')])
        // Tres `created` (persona, faceta, pertenencia); el `updated` extra de la persona es la fecha de nacimiento
        // (decisión del 2026-11-09).
        ->and(Alum::auditCount($tenant, 'person', 'created'))->toBeGreaterThanOrEqual(1)
        ->and(Alum::auditCount($tenant, Alum::TYPE_STUDENT, 'created'))->toBe(1)
        ->and(Alum::auditCount($tenant, 'family_unit_student', 'created'))->toBe(1)
        ->and(Alum::audit($tenant, 'person', 'updated'))->not->toBe([]);

    Alum::call($tenant, $admin, 'get', "/students/{$response->json('public_id')}")->assertOk()->assertJsonPath('document_number', $response->json('document_number'));
});

test('CA-ALUM-002 RN-ALUM-03: el alta con una unidad familiar inexistente o de otro centro es 422 family_unit_not_found y no crea ni persona, ni faceta, ni pertenencia', function (): void {
    [$tenant, $admin] = Alum::setup('alum-002');
    [$other, $otherAdmin] = Alum::setup('alum-002b');
    $foreign = Fam::unitId($other, $otherAdmin, 'AJENA');

    $people = Alum::count($tenant, 'people');

    foreach (['01JZZZZZZZZZZZZZZZZZZZZZZZ', $foreign, 'no-es-un-ulid'] as $unit) {
        $response = Alum::create($tenant, $admin, ['family_unit' => $unit])->assertUnprocessable();

        expect(Alum::errorCode($response, 'family_unit'))->toBe('alum.validation.family_unit_not_found');
    }

    expect(Alum::count($tenant, 'people'))->toBe($people)
        ->and(Alum::count($tenant, 'students'))->toBe(0)
        ->and(Alum::count($tenant, 'family_unit_students'))->toBe(0);
});

test('CA-ALUM-003 RN-ALUM-01 RN-ALUM-09: dar de alta dos veces el mismo documento es person_exists (precedente sobre person_already_student, que no se alcanza desde el alta) y deja una sola faceta viva', function (): void {
    [$tenant, $admin] = Alum::setup('alum-003');
    $document = ['document_type' => 'dni', 'document_number' => Alum::dni(11111111)];

    Alum::create($tenant, $admin, $document)->assertCreated();
    $second = Alum::create($tenant, $admin, $document + ['given_name' => 'Otra'])->assertUnprocessable();

    expect(Alum::errorCode($second, 'document_number'))->toBe('alum.validation.person_exists')
        ->and(Alum::count($tenant, 'students'))->toBe(1);

    // El índice único parcial de T1 (StudentSchemaTest) es la última barrera de RN-ALUM-01.
    $student = app(TenantContext::class)->runFor($tenant->id, fn () => Student::query()->firstOrFail());

    expect(fn () => app(TenantContext::class)->runFor($tenant->id, fn () => DB::transaction(fn () => (new Student(['person_id' => $student->person_id]))->save())))
        ->toThrow(QueryException::class);
});

test('CA-ALUM-004a CA-ALUM-005 RN-ALUM-05 RN-ALUM-06: la baja es 204 y deja viva la persona y su pertenencia; restaurar es 200; con otra faceta viva o la persona borrada, 409 cannot_restore', function (): void {
    [$tenant, $admin] = Alum::setup('alum-004');
    $unit = Fam::unitId($tenant, $admin, 'U1');
    $student = Alum::student($tenant, $admin, ['family_unit' => $unit]);
    $id = $student['public_id'];

    Alum::call($tenant, $admin, 'delete', "/students/{$id}")->assertNoContent();

    expect(Alum::row($tenant, 'students', $id)->deleted_at)->not->toBeNull();
    Alum::call($tenant, $admin, 'get', "/students/{$id}")->assertNotFound();
    // La persona y la pertenencia siguen vivas.
    expect(app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('people')->where('public_id', $student['person']['public_id'])->whereNull('deleted_at')->count()))->toBe(1)
        ->and(count(Fam::call($tenant, $admin, 'get', "/family-units/{$unit}/students")->json('data')))->toBe(1);

    // Dar de baja lo ya dado de baja es 404; restaurar lo vivo también.
    Alum::call($tenant, $admin, 'delete', "/students/{$id}")->assertNotFound();
    Alum::call($tenant, $admin, 'post', "/students/{$id}/restore")->assertOk()->assertJsonPath('public_id', $id);
    Alum::call($tenant, $admin, 'post', "/students/{$id}/restore")->assertNotFound();
    Alum::call($tenant, $admin, 'get', "/students/{$id}")->assertOk();

    // RN-ALUM-06: otra faceta viva de la misma persona.
    Alum::call($tenant, $admin, 'delete', "/students/{$id}")->assertNoContent();
    app(TenantContext::class)->runFor($tenant->id, fn () => Student::factory()->create(['person_id' => Alum::personId($tenant, $student['person']['public_id'])]));
    $conflict = Alum::call($tenant, $admin, 'post', "/students/{$id}/restore")->assertConflict();

    expect($conflict->json('detail'))->not->toBeEmpty()
        ->and(Alum::row($tenant, 'students', $id)->deleted_at)->not->toBeNull();

    // RN-ALUM-06: la persona ya no existe.
    $second = Alum::student($tenant, $admin, ['given_name' => 'Borrada']);
    Alum::call($tenant, $admin, 'delete', "/students/{$second['public_id']}")->assertNoContent();
    app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('people')->where('public_id', $second['person']['public_id'])->update(['deleted_at' => now()]));
    Alum::call($tenant, $admin, 'post', "/students/{$second['public_id']}/restore")->assertConflict();
});

test('RN-ALUM-06 RN-ALUM-01: una carrera que crea otra faceta viva justo antes de restaurar choca con el índice único y es 409 cannot_restore, no 500', function (): void {
    [$tenant, $admin] = Alum::setup('alum-004b');
    $student = Alum::student($tenant, $admin);
    $id = $student['public_id'];
    $personId = Alum::personId($tenant, $student['person']['public_id']);

    Alum::call($tenant, $admin, 'delete', "/students/{$id}")->assertNoContent();

    // La comprobación previa pasa (aún no hay otra faceta); la carrera ocurre entre ella y el UPDATE de la restauración.
    $armed = true;
    Student::restoring(function () use (&$armed, $tenant, $personId): void {
        if ($armed) {
            $armed = false;
            app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('students')->insert([
                'tenant_id' => $tenant->id, 'public_id' => (string) Str::ulid(), 'person_id' => $personId,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    });

    $conflict = Alum::call($tenant, $admin, 'post', "/students/{$id}/restore")->assertConflict();

    expect(Alum::errorCode($conflict, 'student'))->toBe('alum.conflict.cannot_restore')
        ->and(Alum::row($tenant, 'students', $id)->deleted_at)->not->toBeNull();
});

test('CA-ALUM-006 RN-ALUM-08: una fecha de nacimiento futura, mal formada o ausente es 422 y no se crea nada', function (): void {
    [$tenant, $admin] = Alum::setup('alum-006');

    foreach (['2999-01-01', '2015-13-45', '12/04/2015', '2015-4-1', 20150412, ''] as $birth) {
        $response = Alum::create($tenant, $admin, ['birth_date' => $birth])->assertUnprocessable();

        expect(Alum::errorCode($response, 'birth_date'))->toBe('alum.validation.birth_date_invalid', var_export($birth, true));
    }

    $body = Alum::body();
    unset($body['birth_date']);
    $missing = Alum::call($tenant, $admin, 'post', '/students', $body, ['Idempotency-Key' => (string) Str::ulid()])->assertUnprocessable();

    expect(Alum::errorCode($missing, 'birth_date'))->toBe('alum.validation.required')
        ->and(Alum::count($tenant, 'students'))->toBe(0);

    // Hoy sí cabe.
    Alum::create($tenant, $admin, ['birth_date' => now()->toDateString()])->assertCreated();
});

test('RN-ALUM-08 INV-010: nombre y apellido obligatorios, textos con forma válida y documento y idioma validados por Core', function (): void {
    [$tenant, $admin] = Alum::setup('alum-010');

    expect(Alum::errorCode(Alum::call($tenant, $admin, 'post', '/students', ['birth_date' => '2015-01-01'], ['Idempotency-Key' => (string) Str::ulid()])->assertUnprocessable(), 'given_name'))->toBe('alum.validation.required');
    expect(Alum::errorCode(Alum::create($tenant, $admin, ['given_name' => '   '])->assertUnprocessable(), 'given_name'))->toBe('alum.validation.text_invalid');
    expect(Alum::errorCode(Alum::create($tenant, $admin, ['family_name_1' => ['x']])->assertUnprocessable(), 'family_name_1'))->toBe('alum.validation.text_invalid');
    // Documento: el catálogo y el dígito de control los valida Core.
    Alum::create($tenant, $admin, ['document_type' => 'dni', 'document_number' => '12345678A'])->assertUnprocessable();
    Alum::create($tenant, $admin, ['document_type' => 'dni'])->assertUnprocessable();
    // Idioma: entre los activos del centro.
    Alum::create($tenant, $admin, ['locale' => 'xx'])->assertUnprocessable();

    expect(Alum::count($tenant, 'students'))->toBe(0);
});

test('CA-ALUM-007 RN-ALUM-02 RN-ALUM-07: PATCH del nombre de un alumno sin usuario lo corrige Core, que audita el cambio; alum no guarda el nombre', function (): void {
    [$tenant, $admin] = Alum::setup('alum-007');
    $student = Alum::student($tenant, $admin);
    $before = Alum::auditCount($tenant, 'person', 'updated');

    $response = Alum::call($tenant, $admin, 'patch', "/students/{$student['public_id']}", ['given_name' => 'Marta', 'family_name_2' => 'Nueva', 'birth_date' => '2014-02-03'])->assertOk();

    expect($response->json('given_name'))->toBe('Marta')
        ->and($response->json('family_name_2'))->toBe('Nueva')
        ->and($response->json('birth_date'))->toBe('2014-02-03')
        ->and($response->json('display_name'))->toContain('Marta')
        ->and(Alum::auditCount($tenant, 'person', 'updated'))->toBeGreaterThan($before)
        ->and(Schema::hasColumn('students', 'given_name'))->toBeFalse();

    // Borrar el segundo apellido.
    Alum::call($tenant, $admin, 'patch', "/students/{$student['public_id']}", ['family_name_2' => null])->assertOk()->assertJsonPath('family_name_2', null);
    // Un alumno inexistente es 404 antes que 422.
    Alum::call($tenant, $admin, 'patch', '/students/01JZZZZZZZZZZZZZZZZZZZZZZZ', ['given_name' => ''])->assertNotFound();
});

test('CA-ALUM-008 RN-ALUM-10: PATCH de nombre y fecha de nacimiento de un alumno con usuario es 200 por Core; correo, contraseña o estado del usuario son 422 y nada cambia', function (): void {
    [$tenant, $admin] = Alum::setup('alum-008');
    $student = Alum::student($tenant, $admin);

    // Le damos cuenta a la persona del alumno: el usuario pasa a apuntar a ella.
    $account = Alum::userWith($tenant, $admin, 'cuenta', ['alumno.leer']);
    $personId = Alum::personId($tenant, $student['person']['public_id']);
    app(TenantContext::class)->runFor($tenant->id, function () use ($account, $personId): void {
        $user = User::query()->findOrFail($account->id);
        $user->person_id = $personId;
        $user->save();
    });
    $email = $account->email;

    $ok = Alum::call($tenant, $admin, 'patch', "/students/{$student['public_id']}", ['given_name' => 'Con', 'birth_date' => '2013-05-06'])->assertOk();

    expect($ok->json('given_name'))->toBe('Con')->and($ok->json('birth_date'))->toBe('2013-05-06');

    foreach (['email' => 'otro@example.com', 'password' => 'Otra-Clave-123!', 'status' => 'inactivo'] as $field => $value) {
        $bad = Alum::call($tenant, $admin, 'patch', "/students/{$student['public_id']}", ['given_name' => 'Cambiado', $field => $value])->assertUnprocessable();

        expect(Alum::errorCode($bad, $field))->toBe('alum.validation.field_not_editable');
    }

    $after = Alum::call($tenant, $admin, 'get', "/students/{$student['public_id']}")->assertOk();

    expect($after->json('given_name'))->toBe('Con')
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => User::query()->findOrFail($account->id)->email))->toBe($email);

    // `family_unit` no se edita aquí (RN-ALUM-04) y un cuerpo vacío no cambia nada.
    expect(Alum::errorCode(Alum::call($tenant, $admin, 'patch', "/students/{$student['public_id']}", ['family_unit' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertUnprocessable(), 'family_unit'))->toBe('alum.validation.field_not_editable');
    expect(Alum::errorCode(Alum::call($tenant, $admin, 'patch', "/students/{$student['public_id']}", [])->assertUnprocessable(), 'body'))->toBe('alum.validation.no_changes');
});

test('RN-ALUM-08 INV-010: PATCH devuelve los errores de documento de Core con el mismo campo que el alta (document_number, no person.document_number)', function (): void {
    [$tenant, $admin] = Alum::setup('alum-010b');
    $other = Alum::student($tenant, $admin, ['given_name' => 'Otro', 'document_type' => 'dni', 'document_number' => Alum::dni(55555555)]);
    $student = Alum::student($tenant, $admin);

    $bad = Alum::call($tenant, $admin, 'patch', "/students/{$student['public_id']}", ['document_type' => 'dni', 'document_number' => '12345678A'])->assertUnprocessable();
    expect(Alum::errorCode($bad, 'document_number'))->toBe('core.validation.document_number_invalid')
        ->and($bad->json('errors'))->not->toHaveKey('person.document_number');

    $clash = Alum::call($tenant, $admin, 'patch', "/students/{$student['public_id']}", ['document_type' => 'dni', 'document_number' => Alum::dni(55555555)])->assertUnprocessable();
    expect(Alum::errorCode($clash, 'document_number'))->toBe('core.validation.document_duplicate')
        ->and($other['public_id'])->not->toBe($student['public_id']);

    $half = Alum::call($tenant, $admin, 'patch', "/students/{$student['public_id']}", ['document_type' => 'dni'])->assertUnprocessable();
    expect(Alum::errorCode($half, 'document_number'))->toBe('core.validation.document_incomplete');
});

test('CA-ALUM-009 RN-ALUM-09: un documento que ya tiene un alumno, un tutor o un miembro del personal es 422 person_exists con cuerpo idéntico, sin datos de la persona, y no crea nada', function (): void {
    [$tenant, $admin] = Alum::setup('alum-009');

    // Alumno.
    Alum::student($tenant, $admin, ['given_name' => 'Secreto', 'document_type' => 'dni', 'document_number' => Alum::dni(22222222)]);
    // Personal: una persona con usuario y documento.
    $staff = Alum::userWith($tenant, $admin, 'personal', ['alumno.leer']);
    app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('people')->where('id', $staff->person_id)->update(['document_type' => 'dni', 'document_number' => Alum::dni(33333333)]));
    // Tercero sin cuenta ni faceta de alumno (el tutor es una persona con usuario, como el personal: Core no distingue).
    Fam::registerPerson($tenant, $admin, ['given_name' => 'Tutora', 'family_name_1' => 'Existente', 'document_type' => 'dni', 'document_number' => Alum::dni(44444444)]);

    $people = Alum::count($tenant, 'people');
    $bodies = [];

    foreach ([22222222, 33333333, 44444444] as $number) {
        $response = Alum::create($tenant, $admin, ['given_name' => 'Intruso', 'document_type' => 'dni', 'document_number' => Alum::dni($number)])->assertUnprocessable();
        $bodies[] = Arr::except($response->json(), ['instance', 'request_id']);

        expect(Alum::errorCode($response, 'document_number'))->toBe('alum.validation.person_exists');
    }

    expect($bodies[1])->toBe($bodies[0])->and($bodies[2])->toBe($bodies[0])
        ->and(json_encode($bodies[0]))->not->toContain('Secreto')->not->toContain('Tutora')
        ->and(Alum::count($tenant, 'people'))->toBe($people)
        ->and(Alum::count($tenant, 'students'))->toBe(1);
});

test('CA-ALUM-019 RN-ALUM-27: POST /students exige Idempotency-Key ULID; el reintento con el mismo cuerpo repite la respuesta original sin segunda persona; con otro cuerpo, 409', function (): void {
    [$tenant, $admin] = Alum::setup('alum-019');
    $send = fn (array $headers, array $body) => Alum::call($tenant, $admin, 'post', '/students', Alum::body($body), $headers);

    $send([], [])->assertStatus(400)->assertJsonPath('type', 'urn:pge:error:malformed');
    $send(['Idempotency-Key' => 'no-es-ulid'], [])->assertStatus(400)->assertJsonPath('type', 'urn:pge:error:malformed');

    $key = (string) Str::ulid();
    $first = $send(['Idempotency-Key' => $key], [])->assertCreated();
    $replay = $send(['Idempotency-Key' => $key], [])->assertCreated();

    expect($replay->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($replay->json('public_id'))->toBe($first->json('public_id'))
        ->and(Alum::count($tenant, 'students'))->toBe(1);

    $send(['Idempotency-Key' => $key], ['given_name' => 'Distinto'])->assertConflict();
    expect(Alum::count($tenant, 'students'))->toBe(1);
});

test('CA-ALUM-032 OPEN-ALUM-31 RN-ALUM-04: con family_unit hace falta además familia.actualizar; sin él 403 y no se crea nada; sin family_unit, 201', function (): void {
    [$tenant, $admin] = Alum::setup('alum-032');
    $unit = Fam::unitId($tenant, $admin, 'U1');
    $clerk = Alum::userWith($tenant, $admin, 'solo_alumno', ['alumno.crear', 'alumno.leer']);

    Alum::create($tenant, $clerk, ['family_unit' => $unit])->assertForbidden();

    expect(Alum::count($tenant, 'students'))->toBe(0)
        ->and(Alum::count($tenant, 'family_unit_students'))->toBe(0);

    Alum::create($tenant, $clerk)->assertCreated();

    $both = Alum::userWith($tenant, $admin, 'ambos', ['alumno.crear', 'familia.actualizar']);
    Alum::create($tenant, $both, ['family_unit' => $unit])->assertCreated();

    expect(Alum::count($tenant, 'family_unit_students'))->toBe(1);
});

test('api.md §1 INV-002: cada endpoint exige su permiso; sin él 403 y sin sesión 401, y una escritura exige ámbito todos', function (): void {
    [$tenant, $admin] = Alum::setup('alum-perm');
    $student = Alum::student($tenant, $admin);
    $id = $student['public_id'];

    $reader = Alum::userWith($tenant, $admin, 'lector', ['alumno.leer']);
    $none = Alum::userWith($tenant, $admin, 'nada', ['familia.leer']);

    foreach ([
        ['get', '/students'], ['get', "/students/{$id}"], ['post', '/students'], ['patch', "/students/{$id}"],
        ['delete', "/students/{$id}"], ['post', "/students/{$id}/restore"],
    ] as [$method, $path]) {
        Alum::call($tenant, $none, $method, $path, [], ['Idempotency-Key' => (string) Str::ulid()])->assertForbidden();
        resetSessionState();
        test()->json(strtoupper($method), coreApiUrl($tenant->slug, $path))->assertUnauthorized();
    }

    Alum::call($tenant, $reader, 'get', '/students')->assertOk();
    Alum::call($tenant, $reader, 'post', '/students', Alum::body(), ['Idempotency-Key' => (string) Str::ulid()])->assertForbidden();
    Alum::call($tenant, $reader, 'patch', "/students/{$id}", ['given_name' => 'X'])->assertForbidden();
    Alum::call($tenant, $reader, 'delete', "/students/{$id}")->assertForbidden();
    // Un permiso de escritura con ámbito restringido no existe (applicable_scopes): ver AlumPermissionsTest.
});

test('api.md §1: el listado pagina, ordena por nombre (defecto) o created_at, busca por prefijo de nombre desde 2 caracteres y valida sus parámetros', function (): void {
    [$tenant, $admin] = Alum::setup('alum-list');
    Fam::freeze();
    Alum::student($tenant, $admin, ['given_name' => 'Zoe', 'family_name_1' => 'Alfa']);
    Fam::tick();
    Alum::student($tenant, $admin, ['given_name' => 'Ana', 'family_name_1' => 'Zeta']);
    Fam::tick();
    Alum::student($tenant, $admin, ['given_name' => 'Mario', 'family_name_1' => 'Medio']);

    $names = fn (string $query) => array_column(Alum::call($tenant, $admin, 'get', "/students{$query}")->assertOk()->json('data'), 'display_name');

    expect($names(''))->toBe(['Ana Zeta', 'Mario Medio', 'Zoe Alfa'])
        ->and($names('?sort=-name'))->toBe(['Zoe Alfa', 'Mario Medio', 'Ana Zeta'])
        ->and($names('?sort=created_at'))->toBe(['Zoe Alfa', 'Ana Zeta', 'Mario Medio'])
        ->and($names('?sort=-created_at'))->toBe(['Mario Medio', 'Ana Zeta', 'Zoe Alfa'])
        ->and($names('?q=ma'))->toBe(['Mario Medio'])
        ->and($names('?q=zeta'))->toBe(['Ana Zeta'])
        ->and($names('?q=nadie'))->toBe([]);

    $page = Alum::call($tenant, $admin, 'get', '/students?per_page=2&page=2')->assertOk();

    expect($page->json('meta'))->toBe(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
        ->and(array_column($page->json('data'), 'display_name'))->toBe(['Zoe Alfa'])
        ->and(array_keys($page->json('data.0')))->toBe(['public_id', 'person', 'display_name', 'birth_date', 'created_at', 'updated_at']);

    foreach (['?q=a', '?per_page=101', '?sort=foto', '?page=0', '?include_deleted=quizas'] as $bad) {
        Alum::call($tenant, $admin, 'get', "/students{$bad}")->assertUnprocessable();
    }
});

test('api.md §1 RN-ALUM-05: include_deleted exige alumno.eliminar (403 sin él) y con él lista también las bajas', function (): void {
    [$tenant, $admin] = Alum::setup('alum-deleted');
    $one = Alum::student($tenant, $admin, ['given_name' => 'Viva']);
    $two = Alum::student($tenant, $admin, ['given_name' => 'Baja']);
    Alum::call($tenant, $admin, 'delete', "/students/{$two['public_id']}")->assertNoContent();
    $reader = Alum::userWith($tenant, $admin, 'lector', ['alumno.leer']);

    Alum::call($tenant, $reader, 'get', '/students?include_deleted=true')->assertForbidden();
    expect(array_column(Alum::call($tenant, $reader, 'get', '/students')->json('data'), 'public_id'))->toBe([$one['public_id']]);

    $all = Alum::call($tenant, $admin, 'get', '/students?include_deleted=true')->assertOk()->json('data');

    expect(array_column($all, 'public_id'))->toEqualCanonicalizing([$one['public_id'], $two['public_id']])
        ->and(collect($all)->firstWhere('public_id', $two['public_id'])['deleted_at'])->not->toBeNull()
        ->and(collect($all)->firstWhere('public_id', $one['public_id']))->not->toHaveKey('deleted_at');
});
