<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-001 (1.14-a), docs/modulos/REQ-FAM-UNIT/api.md §2.1 y §2.2, funcional.md §3.1, §5.1 y §15 (D-1):
// unidades familiares y tutores. CA-FAM-UNIT-001 a -009, INV-003 (auditoría), INV-004 (borrado lógico), INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-FAM-UNIT-001 RN-FAM-UNIT-01 INV-003: crear una unidad con un código libre responde 201 con Location, public_id ULID y la forma de api.md §1.1, y queda en audit_logs', function (): void {
    [$tenant, $admin] = Fam::setup('fu-001');

    $response = Fam::unit($tenant, $admin, ['code' => 'FAM-ANA'])->assertCreated();

    expect($response->json('public_id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($response->headers->get('Location'))->toEndWith('/api/v1/family-units/'.$response->json('public_id'))
        ->and($response->json())->toMatchArray(['code' => 'FAM-ANA', 'principal_guardian' => null, 'guardians_count' => 0, 'students_count' => 0])
        ->and(array_keys($response->json()))->toEqualCanonicalizing(['public_id', 'code', 'principal_guardian', 'guardians_count', 'students_count', 'created_at', 'updated_at'])
        ->and(array_keys($response->json()))->not->toContain('id', 'tenant_id', 'created_by');

    $audit = Fam::audit($tenant, 'family_unit', 'created');

    expect($audit)->toHaveCount(1)
        ->and($audit[0]->auditable_public_id)->toBe($response->json('public_id'));

    // CA-FAM-UNIT-080: `code` se redacta como identificador (ADR-035), nunca se registra su valor.
    expect(json_encode($audit[0]->changes))->not->toContain('FAM-ANA');
});

test('RN-FAM-UNIT-01 funcional.md §15 D-1: sin código el sistema genera UF- más seis cifras, secuencial por centro y sin reutilizar números; con código ajeno al formato no interfiere', function (): void {
    [$tenant, $admin] = Fam::setup('fu-gen');
    [$other, $otherAdmin] = Fam::setup('fu-gen-b');

    $first = Fam::unit($tenant, $admin)->assertCreated()->json('code');
    Fam::unit($tenant, $admin, ['code' => 'A-LIBRE'])->assertCreated();
    $second = Fam::unit($tenant, $admin)->assertCreated();

    expect($first)->toBe('UF-000001')
        ->and($second->json('code'))->toBe('UF-000002');

    // Dar de baja la última no libera su número (se cuenta también lo borrado).
    Fam::call($tenant, $admin, 'delete', '/family-units/'.$second->json('public_id'))->assertNoContent();
    expect(Fam::unit($tenant, $admin)->assertCreated()->json('code'))->toBe('UF-000003');

    // La secuencia es por centro.
    expect(Fam::unit($other, $otherAdmin)->assertCreated()->json('code'))->toBe('UF-000001');

    // null equivale a ausente.
    expect(Fam::unit($tenant, $admin, ['code' => null])->assertCreated()->json('code'))->toBe('UF-000004');
});

test('CA-FAM-UNIT-002 RN-FAM-UNIT-01: un código vivo repetido es 422 code_taken en create y en PATCH, y vuelve a estar libre cuando la primera se da de baja', function (): void {
    [$tenant, $admin] = Fam::setup('fu-002');

    $first = Fam::unitId($tenant, $admin, 'X');

    expect(Fam::errorCode(Fam::unit($tenant, $admin, ['code' => 'X'])->assertStatus(422), 'code'))->toBe('fam_unit.family_unit.code_taken');

    $second = Fam::unitId($tenant, $admin, 'Y');
    expect(Fam::errorCode(Fam::call($tenant, $admin, 'patch', "/family-units/{$second}", ['code' => 'X'])->assertStatus(422), 'code'))->toBe('fam_unit.family_unit.code_taken');
    // Su propio código no es conflicto.
    Fam::call($tenant, $admin, 'patch', "/family-units/{$second}", ['code' => 'Y'])->assertOk();

    Fam::call($tenant, $admin, 'delete', "/family-units/{$first}")->assertNoContent();
    Fam::unit($tenant, $admin, ['code' => 'X'])->assertCreated();

    // Restaurar la borrada ahora choca con el código vivo (RN-FAM-UNIT-04: se reaplica la validación del alta).
    expect(Fam::errorCode(Fam::call($tenant, $admin, 'post', "/family-units/{$first}/restore")->assertStatus(422), 'code'))->toBe('fam_unit.family_unit.code_taken');
});

test('RN-FAM-UNIT-04 INV-004: la baja es lógica, include_deleted (solo con familia.eliminar) la lista con deleted_at, y restaurar con el código libre la devuelve', function (): void {
    [$tenant, $admin] = Fam::setup('fu-004');
    $id = Fam::unitId($tenant, $admin, 'BAJA');

    Fam::call($tenant, $admin, 'delete', "/family-units/{$id}")->assertNoContent();
    Fam::call($tenant, $admin, 'get', "/family-units/{$id}")->assertNotFound();

    expect(Fam::call($tenant, $admin, 'get', '/family-units')->assertOk()->json('data'))->toBe([]);

    $listed = Fam::call($tenant, $admin, 'get', '/family-units?include_deleted=true')->assertOk()->json('data');
    expect($listed)->toHaveCount(1)->and($listed[0]['deleted_at'])->not->toBeNull();

    // Quien no tiene familia.eliminar no puede pedir los borrados.
    $reader = Fam::userWith($tenant, $admin, 'lector', ['familia.leer' => 'todos']);
    Fam::call($tenant, $reader, 'get', '/family-units?include_deleted=true')->assertForbidden();

    Fam::call($tenant, $admin, 'post', "/family-units/{$id}/restore")->assertOk()->assertJsonPath('code', 'BAJA');
    Fam::call($tenant, $admin, 'get', "/family-units/{$id}")->assertOk();

    expect(Fam::audit($tenant, 'family_unit', 'deleted'))->toHaveCount(1)
        ->and(Fam::audit($tenant, 'family_unit', 'restored'))->toHaveCount(1);
});

test('RN-FAM-UNIT-01 datos.md §1.1: un código vacío, solo espacios, de más de 40 caracteres, con caracteres de control o que no es texto es 422 code_invalid', function (): void {
    [$tenant, $admin] = Fam::setup('fu-code');

    foreach (['', '   ', str_repeat('A', 41), "A\x07B", 5, ['x']] as $code) {
        expect(Fam::errorCode(Fam::unit($tenant, $admin, ['code' => $code])->assertStatus(422), 'code'))->toBe('fam_unit.family_unit.code_invalid');
    }

    Fam::unit($tenant, $admin, ['code' => str_repeat('A', 40)])->assertCreated();
    // El código se recorta.
    expect(Fam::unit($tenant, $admin, ['code' => '  B  '])->assertCreated()->json('code'))->toBe('B');
});

test('api.md §3: GET /family-units pagina, filtra por prefijo q y has_principal, ordena por code o created_at y rechaza sort ajeno, per_page > 100 o un q con caracteres de control', function (): void {
    [$tenant, $admin] = Fam::setup('fu-list');

    foreach (['B-2', 'A-1', 'A-2'] as $code) {
        Fam::unitId($tenant, $admin, $code);
    }

    $codes = fn (string $query) => array_column(Fam::call($tenant, $admin, 'get', '/family-units'.$query)->assertOk()->json('data'), 'code');

    expect($codes(''))->toBe(['A-1', 'A-2', 'B-2'])
        ->and($codes('?sort=-code'))->toBe(['B-2', 'A-2', 'A-1'])
        ->and($codes('?sort=created_at'))->toBe(['B-2', 'A-1', 'A-2'])
        ->and($codes('?q=A-'))->toBe(['A-1', 'A-2'])
        ->and($codes('?q=%25'))->toBe([])
        ->and($codes('?has_principal=true'))->toBe([])
        ->and($codes('?has_principal=false'))->toBe(['A-1', 'A-2', 'B-2'])
        ->and(Fam::call($tenant, $admin, 'get', '/family-units?per_page=2')->assertOk()->json('meta.total'))->toBe(3);

    Fam::call($tenant, $admin, 'get', '/family-units?sort=name')->assertStatus(422);
    Fam::call($tenant, $admin, 'get', '/family-units?per_page=101')->assertStatus(422);
    Fam::call($tenant, $admin, 'get', '/family-units?has_principal=1')->assertStatus(422);
});

test('CA-FAM-UNIT-003 RN-FAM-UNIT-10: crear el tutor de un usuario pendiente responde 201 y no crea persona, usuario, invitación ni asignación de rol', function (): void {
    [$tenant, $admin] = Fam::setup('fu-003');
    $unit = Fam::unitId($tenant, $admin);

    resetSessionState();
    test()->actingAs($admin)->postJson(Fam::url($tenant, '/users'), [
        'email' => 'madre@example.com',
        'person' => ['given_name' => 'Marta', 'family_name_1' => 'Ejemplo'],
        'send_invitation' => false,
    ])->assertCreated();

    $user = app(TenantContext::class)->runFor($tenant->id, fn () => User::query()->where('email', 'madre@example.com')->firstOrFail());
    expect($user->status->value)->toBe('pendiente');

    $count = fn (string $table): int => app(TenantContext::class)->runFor($tenant->id, fn (): int => DB::table($table)->count());
    $before = [$count('people'), $count('users'), $count('user_invitations'), $count('role_user')];

    $response = Fam::guardian($tenant, $admin, $user, $unit)->assertCreated();

    expect($response->json())->toMatchArray(['family_unit' => $unit, 'user' => $user->public_id, 'display_name' => 'Marta Ejemplo', 'is_principal' => false])
        ->and(array_keys($response->json()))->toEqualCanonicalizing(['public_id', 'family_unit', 'user', 'display_name', 'is_principal', 'created_at', 'updated_at'])
        ->and([$count('people'), $count('users'), $count('user_invitations'), $count('role_user')])->toBe($before);
});

test('RN-FAM-UNIT-10: un usuario inactivo, borrado o inexistente no puede ser tutor (user_not_eligible / user_not_found); un id mal formado o ausente es 422', function (): void {
    [$tenant, $admin] = Fam::setup('fu-010');
    $unit = Fam::unitId($tenant, $admin);

    $inactive = Fam::userWith($tenant, $admin, 'inactivo', ['familia.leer' => 'todos']);
    app(TenantContext::class)->runFor($tenant->id, function () use ($inactive): void {
        $inactive->status = UserStatus::Inactivo;
        $inactive->save();
    });
    expect(Fam::errorCode(Fam::guardian($tenant, $admin, $inactive, $unit)->assertStatus(422), 'user'))->toBe('fam_unit.guardian.user_not_eligible');

    $gone = Fam::userWith($tenant, $admin, 'borrado', ['familia.leer' => 'todos']);
    app(TenantContext::class)->runFor($tenant->id, fn () => $gone->delete());
    expect(Fam::errorCode(Fam::guardian($tenant, $admin, $gone, $unit)->assertStatus(422), 'user'))->toBe('fam_unit.validation.user_not_found');

    $missing = Fam::call($tenant, $admin, 'post', '/guardians', ['user' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'family_unit' => $unit])->assertStatus(422);
    expect(Fam::errorCode($missing, 'user'))->toBe('fam_unit.validation.user_not_found');

    expect(Fam::errorCode(Fam::call($tenant, $admin, 'post', '/guardians', ['user' => 'no-es-ulid', 'family_unit' => $unit])->assertStatus(422), 'user'))->toBe('fam_unit.validation.reference_invalid')
        ->and(Fam::errorCode(Fam::call($tenant, $admin, 'post', '/guardians', ['family_unit' => $unit])->assertStatus(422), 'user'))->toBe('fam_unit.validation.required');

    $ok = Fam::userWith($tenant, $admin, 'ok', ['familia.leer' => 'todos']);
    expect(Fam::errorCode(Fam::call($tenant, $admin, 'post', '/guardians', ['user' => $ok->public_id, 'family_unit' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertStatus(422), 'family_unit'))->toBe('fam_unit.validation.family_unit_not_found');
});

test('CA-FAM-UNIT-004 RN-FAM-UNIT-11: una persona que ya es tutora no puede serlo otra vez, ni en la misma unidad ni en otra; sí puede tras darla de baja', function (): void {
    [$tenant, $admin] = Fam::setup('fu-004b');
    $unitA = Fam::unitId($tenant, $admin, 'A');
    $unitB = Fam::unitId($tenant, $admin, 'B');
    $user = Fam::userWith($tenant, $admin, 'madre', ['familia.leer' => 'todos']);

    $guardian = Fam::guardian($tenant, $admin, $user, $unitA)->assertCreated()->json('public_id');

    foreach ([$unitA, $unitB] as $unit) {
        expect(Fam::errorCode(Fam::guardian($tenant, $admin, $user, $unit)->assertStatus(422), 'user'))->toBe('fam_unit.guardian.already_exists');
    }

    Fam::call($tenant, $admin, 'delete', "/guardians/{$guardian}")->assertOk();
    Fam::guardian($tenant, $admin, $user, $unitB)->assertCreated();
});

test('CA-FAM-UNIT-005 RN-FAM-UNIT-02: designar principal a un tutor de otra unidad es 422 principal_not_member; a uno de la unidad, 200; null lo quita; un tutor inexistente, 422', function (): void {
    [$tenant, $admin] = Fam::setup('fu-005');
    $unitA = Fam::unitId($tenant, $admin, 'A');
    $unitB = Fam::unitId($tenant, $admin, 'B');
    [, $guardianA] = Fam::familyUser($tenant, $admin, 'a', $unitA);
    [, $guardianB] = Fam::familyUser($tenant, $admin, 'b', $unitB);

    $bad = Fam::call($tenant, $admin, 'patch', "/family-units/{$unitA}", ['principal_guardian' => $guardianB])->assertStatus(422);
    expect(Fam::errorCode($bad, 'principal_guardian'))->toBe('fam_unit.family_unit.principal_not_member');

    expect(Fam::errorCode(Fam::call($tenant, $admin, 'patch', "/family-units/{$unitA}", ['principal_guardian' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertStatus(422), 'principal_guardian'))->toBe('fam_unit.validation.principal_guardian_not_found')
        ->and(Fam::errorCode(Fam::call($tenant, $admin, 'patch', "/family-units/{$unitA}", ['principal_guardian' => 'zzz'])->assertStatus(422), 'principal_guardian'))->toBe('fam_unit.validation.reference_invalid');

    Fam::call($tenant, $admin, 'patch', "/family-units/{$unitA}", ['principal_guardian' => $guardianA])->assertOk()->assertJsonPath('principal_guardian', $guardianA);
    Fam::call($tenant, $admin, 'get', "/guardians/{$guardianA}")->assertOk()->assertJsonPath('is_principal', true);
    Fam::call($tenant, $admin, 'get', '/family-units?has_principal=true')->assertOk()->assertJsonCount(1, 'data');
    Fam::call($tenant, $admin, 'patch', "/family-units/{$unitA}", ['principal_guardian' => null])->assertOk()->assertJsonPath('principal_guardian', null);
});

test('CA-FAM-UNIT-006 RN-FAM-UNIT-03: una unidad con un alumno o un tutor vivos no se da de baja (409 has_members); vacía, sí', function (): void {
    [$tenant, $admin] = Fam::setup('fu-006');
    $withStudent = Fam::unitId($tenant, $admin, 'S');
    $withGuardian = Fam::unitId($tenant, $admin, 'G');
    Fam::addStudent($tenant, $admin, $withStudent, Fam::person($tenant))->assertCreated();
    Fam::familyUser($tenant, $admin, 'tutor', $withGuardian);

    foreach ([$withStudent, $withGuardian] as $unit) {
        $response = Fam::call($tenant, $admin, 'delete', "/family-units/{$unit}")->assertStatus(409);
        expect($response->json('errors.family_unit.0.code'))->toBe('fam_unit.family_unit.has_members');
    }

    Fam::call($tenant, $admin, 'delete', '/family-units/'.Fam::unitId($tenant, $admin, 'V'))->assertNoContent();
});

test('CA-FAM-UNIT-007 RN-FAM-UNIT-12: dar de baja al tutor principal con dos vínculos da de baja los vínculos y deja la unidad sin principal, y la respuesta lo dice', function (): void {
    [$tenant, $admin] = Fam::setup('fu-007');
    $unit = Fam::unitId($tenant, $admin);
    [, $guardian] = Fam::familyUser($tenant, $admin, 'papa', $unit);
    $other = Fam::familyUser($tenant, $admin, 'otro', $unit)[1];
    $students = [Fam::person($tenant, 'Uno'), Fam::person($tenant, 'Dos')];

    $links = [];
    foreach ($students as $student) {
        $links[] = Fam::link($tenant, $admin, $guardian, $student)->assertCreated()->json('public_id');
    }
    $kept = Fam::link($tenant, $admin, $other, $students[0])->assertCreated()->json('public_id');
    Fam::call($tenant, $admin, 'patch', "/family-units/{$unit}", ['principal_guardian' => $guardian])->assertOk();

    Fam::call($tenant, $admin, 'delete', "/guardians/{$guardian}")->assertOk()->assertExactJson(['removed_links' => 2, 'principal_cleared' => true]);

    Fam::call($tenant, $admin, 'get', "/family-units/{$unit}")->assertOk()->assertJsonPath('principal_guardian', null);
    Fam::call($tenant, $admin, 'get', "/guardians/{$guardian}")->assertNotFound();

    foreach ($links as $link) {
        Fam::call($tenant, $admin, 'get', "/guardian-links/{$link}")->assertNotFound();
    }
    // El vínculo de otro tutor con el mismo alumno no se toca.
    Fam::call($tenant, $admin, 'get', "/guardian-links/{$kept}")->assertOk();

    // Un tutor que no era principal: principal_cleared = false.
    Fam::call($tenant, $admin, 'delete', "/guardians/{$other}")->assertOk()->assertExactJson(['removed_links' => 1, 'principal_cleared' => false]);

    expect(Fam::audit($tenant, 'guardian_student_link', 'deleted'))->toHaveCount(3)
        ->and(Fam::audit($tenant, 'guardian', 'deleted'))->toHaveCount(2);
});

test('CA-FAM-UNIT-008: crear un tutor con un usuario de otro centro da el mismo 422 user_not_found que con un public_id inexistente', function (): void {
    [$tenantA, $adminA] = Fam::setup('fu-008a');
    [$tenantB, $adminB] = Fam::setup('fu-008b');
    $unit = Fam::unitId($tenantA, $adminA);
    $foreign = Fam::userWith($tenantB, $adminB, 'ajeno', ['familia.leer' => 'todos']);

    $withForeign = Fam::guardian($tenantA, $adminA, $foreign, $unit)->assertStatus(422);
    $withMissing = Fam::call($tenantA, $adminA, 'post', '/guardians', ['user' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'family_unit' => $unit])->assertStatus(422);

    expect($withForeign->json('errors'))->toBe($withMissing->json('errors'))
        ->and(Fam::errorCode($withForeign, 'user'))->toBe('fam_unit.validation.user_not_found');
});

test('CA-FAM-UNIT-009 RN-FAM-UNIT-14: PATCH /guardians con family_unit (la misma u otra) es 422 not_editable, no cambia nada ni audita; sin family_unit responde 200 sin cambios', function (): void {
    [$tenant, $admin] = Fam::setup('fu-009');
    $unitA = Fam::unitId($tenant, $admin, 'A');
    $unitB = Fam::unitId($tenant, $admin, 'B');
    [, $guardian] = Fam::familyUser($tenant, $admin, 'tutor', $unitA);
    Fam::call($tenant, $admin, 'patch', "/family-units/{$unitA}", ['principal_guardian' => $guardian])->assertOk();

    $before = count(Fam::audit($tenant, 'guardian')) + count(Fam::audit($tenant, 'family_unit'));

    foreach ([$unitA, $unitB, null, 'basura'] as $target) {
        $response = Fam::call($tenant, $admin, 'patch', "/guardians/{$guardian}", ['family_unit' => $target])->assertStatus(422);
        expect(Fam::errorCode($response, 'family_unit'))->toBe('fam_unit.guardian.not_editable');
    }

    Fam::call($tenant, $admin, 'get', "/guardians/{$guardian}")->assertOk()->assertJsonPath('family_unit', $unitA)->assertJsonPath('is_principal', true);
    Fam::call($tenant, $admin, 'patch', "/guardians/{$guardian}", [])->assertOk()->assertJsonPath('family_unit', $unitA);
    Fam::call($tenant, $admin, 'patch', '/guardians/01JZZZZZZZZZZZZZZZZZZZZZZZ', ['family_unit' => $unitA])->assertNotFound();

    expect(count(Fam::audit($tenant, 'guardian')) + count(Fam::audit($tenant, 'family_unit')))->toBe($before);
});

test('api.md §2.2 y §3: GET /guardians filtra por family_unit y user, ordena por created_at, y un public_id mal formado en el filtro es 422', function (): void {
    [$tenant, $admin] = Fam::setup('fu-gl');
    $unitA = Fam::unitId($tenant, $admin, 'A');
    $unitB = Fam::unitId($tenant, $admin, 'B');
    [$userA] = Fam::familyUser($tenant, $admin, 'a', $unitA);
    Fam::familyUser($tenant, $admin, 'b', $unitB);

    expect(Fam::call($tenant, $admin, 'get', '/guardians')->assertOk()->json('meta.total'))->toBe(2)
        ->and(Fam::call($tenant, $admin, 'get', "/guardians?family_unit={$unitA}")->assertOk()->json('meta.total'))->toBe(1)
        ->and(Fam::call($tenant, $admin, 'get', "/guardians?family_unit={$unitA},{$unitB}")->assertOk()->json('meta.total'))->toBe(2)
        ->and(Fam::call($tenant, $admin, 'get', "/guardians?user={$userA->public_id}")->assertOk()->json('data.0.display_name'))->toBe('Persona Prueba')
        ->and(Fam::call($tenant, $admin, 'get', '/guardians?user=01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertOk()->json('data'))->toBe([]);

    Fam::call($tenant, $admin, 'get', '/guardians?family_unit=xx')->assertStatus(422);
    Fam::call($tenant, $admin, 'get', '/guardians?sort=-created_at')->assertOk();
    Fam::call($tenant, $admin, 'get', '/guardians?sort=person')->assertStatus(422);
});

test('OPEN-FAM-UNIT-16: el tutor cuyo usuario se dio de baja en Core sigue en la unidad con display_name vacío (la faceta no se borra sola)', function (): void {
    [$tenant, $admin] = Fam::setup('fu-016');
    $unit = Fam::unitId($tenant, $admin);
    [$user, $guardian] = Fam::familyUser($tenant, $admin, 'baja', $unit);

    app(TenantContext::class)->runFor($tenant->id, fn () => $user->delete());

    Fam::call($tenant, $admin, 'get', "/guardians/{$guardian}")->assertOk()
        ->assertJsonPath('display_name', '');
});

test('INV-003 CA-FAM-UNIT-080: designar principal y quitarlo deja entradas updated de family_unit con principal_guardian_id y sin el código', function (): void {
    [$tenant, $admin] = Fam::setup('fu-080');
    $unit = Fam::unitId($tenant, $admin, 'SECRETO-123');
    [, $guardian] = Fam::familyUser($tenant, $admin, 'tutor', $unit);

    Fam::call($tenant, $admin, 'patch', "/family-units/{$unit}", ['principal_guardian' => $guardian])->assertOk();

    $updated = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->where('auditable_type', 'family_unit')->where('event', 'updated')->get());

    expect($updated)->toHaveCount(1)
        ->and($updated[0]->changes)->toHaveKey('principal_guardian_id')
        ->and(json_encode($updated[0]->changes))->not->toContain('SECRETO-123');
});
