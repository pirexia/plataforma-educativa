<?php

use App\Models\Person;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-002 (1.14-a), docs/modulos/REQ-FAM-UNIT/api.md §2.4, funcional.md §3.3 y §5.3: vínculos tutor ↔ alumno.
// CA-FAM-UNIT-020 a -025, -029 y -037 (la parte de PATCH), INV-003, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array{0: object, 1: User, 2: string, 3: string, 4: Person} centro, administrador, unidad, tutor y un alumno */
function linkScenario(string $slug): array
{
    [$tenant, $admin] = Fam::setup($slug);
    $unit = Fam::unitId($tenant, $admin);
    [, $guardian] = Fam::familyUser($tenant, $admin, 'tutor', $unit);

    return [$tenant, $admin, $unit, $guardian, Fam::person($tenant)];
}

test('CA-FAM-UNIT-020 RN-FAM-UNIT-30: crear un vínculo (madre, representación legal, lectura_escritura) responde 201 activo con la forma de api.md §1.4 y verificación rellenada', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-020');

    $response = Fam::link($tenant, $admin, $guardian, $student, ['is_legal_guardian' => true, 'access_level' => 'lectura_escritura'])->assertCreated();

    expect($response->json())->toMatchArray([
        'guardian' => $guardian,
        'student_person' => $student->public_id,
        'relationship' => 'madre',
        'is_legal_guardian' => true,
        'access_level' => 'lectura_escritura',
        'restricted_categories' => [],
        'is_emergency_contact' => false,
        'status' => 'activo',
        'revocation' => null,
    ])
        ->and($response->json('legal_guardian_verified_at'))->not->toBeNull()
        ->and($response->headers->get('Location'))->toEndWith('/api/v1/guardian-links/'.$response->json('public_id'))
        ->and(array_keys($response->json()))->toEqualCanonicalizing([
            'public_id', 'guardian', 'student_person', 'relationship', 'is_legal_guardian', 'legal_guardian_verified_at', 'access_level',
            'restricted_categories', 'is_emergency_contact', 'status', 'revocation', 'created_at', 'updated_at',
        ]);

    $row = Fam::row($tenant, 'guardian_student_links', $response->json('public_id'));
    expect($row->legal_guardian_verified_by)->toBe($admin->id);

    expect(Fam::audit($tenant, 'guardian_student_link', 'created'))->toHaveCount(1);
});

test('api.md §2.4: sin más datos que lo obligatorio, el vínculo nace con acceso lectura (el más restrictivo), sin representación legal, sin categorías restringidas ni contacto de emergencia', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-defaults');

    $response = Fam::link($tenant, $admin, $guardian, $student, ['relationship' => 'abuelo'])->assertCreated();

    expect($response->json())->toMatchArray(['relationship' => 'abuelo', 'access_level' => 'lectura', 'is_legal_guardian' => false, 'is_emergency_contact' => false, 'restricted_categories' => [], 'legal_guardian_verified_at' => null]);
});

test('CA-FAM-UNIT-021 RN-FAM-UNIT-30: un segundo vínculo vivo del mismo par es 422 already_exists; tras dar de baja el primero se puede crear de nuevo', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-021');

    $first = Fam::link($tenant, $admin, $guardian, $student)->assertCreated()->json('public_id');

    expect(Fam::errorCode(Fam::link($tenant, $admin, $guardian, $student, ['relationship' => 'padre'])->assertStatus(422), 'guardian'))->toBe('fam_unit.link.already_exists');

    Fam::call($tenant, $admin, 'delete', "/guardian-links/{$first}")->assertNoContent();
    Fam::link($tenant, $admin, $guardian, $student)->assertCreated();
});

test('CA-FAM-UNIT-022 RN-FAM-UNIT-31: un alumno con un contacto de emergencia activo no admite otro (422 emergency_contact_taken) y el índice único lo impide también en el motor', function (): void {
    [$tenant, $admin, $unit, $guardian, $student] = linkScenario('gl-022');
    [, $second] = Fam::familyUser($tenant, $admin, 'segundo', $unit);

    Fam::link($tenant, $admin, $guardian, $student, ['is_emergency_contact' => true])->assertCreated();

    expect(Fam::errorCode(Fam::link($tenant, $admin, $second, $student, ['is_emergency_contact' => true])->assertStatus(422), 'is_emergency_contact'))->toBe('fam_unit.link.emergency_contact_taken');

    // Por PATCH, igual.
    $other = Fam::link($tenant, $admin, $second, $student)->assertCreated()->json('public_id');
    expect(Fam::errorCode(Fam::call($tenant, $admin, 'patch', "/guardian-links/{$other}", ['is_emergency_contact' => true])->assertStatus(422), 'is_emergency_contact'))->toBe('fam_unit.link.emergency_contact_taken');

    // En otro alumno sí; y quitar la marca libera al primero.
    Fam::link($tenant, $admin, $second, Fam::person($tenant, 'Otro'), ['is_emergency_contact' => true])->assertCreated();

    // El índice parcial único rechaza la segunda marca activa sin pasar por el servicio.
    $row = app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('guardian_student_links')->where('public_id', $other)->first());
    $state = null;

    try {
        app(TenantContext::class)->runFor($tenant->id, fn () => DB::transaction(fn () => DB::table('guardian_student_links')->where('id', $row->id)->update(['is_emergency_contact' => true])));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('23505');
});

test('CA-FAM-UNIT-023 RN-FAM-UNIT-32: una categoría restringida, una relación o un nivel de acceso fuera del vocabulario es 422, y el CHECK del motor rechaza el valor escrito directamente', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-023');

    expect(Fam::errorCode(Fam::link($tenant, $admin, $guardian, $student, ['restricted_categories' => ['dinero']])->assertStatus(422), 'restricted_categories'))->toBe('fam_unit.validation.restricted_category_invalid')
        ->and(Fam::errorCode(Fam::link($tenant, $admin, $guardian, $student, ['restricted_categories' => 'salud'])->assertStatus(422), 'restricted_categories'))->toBe('fam_unit.validation.restricted_category_invalid')
        ->and(Fam::errorCode(Fam::link($tenant, $admin, $guardian, $student, ['relationship' => 'tio'])->assertStatus(422), 'relationship'))->toBe('fam_unit.validation.relationship_invalid')
        ->and(Fam::errorCode(Fam::link($tenant, $admin, $guardian, $student, ['relationship' => null])->assertStatus(422), 'relationship'))->toBe('fam_unit.validation.required')
        ->and(Fam::errorCode(Fam::link($tenant, $admin, $guardian, $student, ['access_level' => 'total'])->assertStatus(422), 'access_level'))->toBe('fam_unit.validation.access_level_invalid')
        ->and(Fam::errorCode(Fam::link($tenant, $admin, $guardian, $student, ['is_legal_guardian' => 'si'])->assertStatus(422), 'is_legal_guardian'))->toBe('fam_unit.validation.boolean_invalid');

    $created = Fam::link($tenant, $admin, $guardian, $student, ['restricted_categories' => ['salud', 'salud']])->assertCreated();
    expect($created->json('restricted_categories'))->toBe(['salud']);

    // Escritura directa con un valor fuera del vocabulario: el CHECK la rechaza.
    $state = null;

    try {
        app(TenantContext::class)->runFor($tenant->id, fn () => DB::transaction(fn () => DB::table('guardian_student_links')->where('public_id', $created->json('public_id'))->update(['restricted_categories' => json_encode(['dinero'])])));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('23514');
});

test('CA-FAM-UNIT-024 C2 OPEN-FAM-UNIT-02: un tutor se vincula con un alumno de otra unidad y su ámbito unidad_familiar alcanza a ese alumno', function (): void {
    [$tenant, $admin] = Fam::setup('gl-024');
    $unitA = Fam::unitId($tenant, $admin, 'A');
    $unitB = Fam::unitId($tenant, $admin, 'B');
    [$tutorUser, $guardian] = Fam::familyUser($tenant, $admin, 'cruzado', $unitA);
    $studentOfB = Fam::person($tenant, 'Hijo', 'DeB');
    Fam::addStudent($tenant, $admin, $unitB, $studentOfB)->assertCreated();

    $link = Fam::link($tenant, $admin, $guardian, $studentOfB)->assertCreated()->json('public_id');

    // El tutor (unidad A) lee el vínculo y la unidad B por el ámbito, no por pertenencia.
    Fam::call($tenant, $tutorUser, 'get', "/guardian-links/{$link}")->assertOk()->assertJsonPath('student_person', $studentOfB->public_id);
    Fam::call($tenant, $tutorUser, 'get', "/family-units/{$unitB}")->assertOk()->assertJsonPath('students_count', 1);
    expect(array_column(Fam::call($tenant, $tutorUser, 'get', '/family-units')->assertOk()->json('data'), 'public_id'))->toBe([$unitB]);
});

test('CA-FAM-UNIT-025 RN-FAM-UNIT-21: vincular a una persona como tutora de sí misma es 422 self_link', function (): void {
    [$tenant, $admin, , $guardian] = linkScenario('gl-025');

    $guardianPerson = app(TenantContext::class)->runFor($tenant->id, fn () => Person::query()->whereKey(DB::table('guardians')->where('public_id', $guardian)->value('person_id'))->firstOrFail());

    expect(Fam::errorCode(Fam::link($tenant, $admin, $guardian, $guardianPerson)->assertStatus(422), 'student_person'))->toBe('fam_unit.link.self_link');
});

test('api.md §2.4: un tutor o alumno inexistente o de otro centro es 422 guardian_not_found / student_not_found; un id mal formado o ausente, 422', function (): void {
    [$tenantA, $adminA, , $guardian, $student] = linkScenario('gl-nf-a');
    [$tenantB] = Fam::setup('gl-nf-b');
    $foreign = Fam::person($tenantB);

    expect(Fam::errorCode(Fam::link($tenantA, $adminA, $guardian, $foreign)->assertStatus(422), 'student_person'))->toBe('fam_unit.validation.student_not_found')
        ->and(Fam::errorCode(Fam::link($tenantA, $adminA, '01JZZZZZZZZZZZZZZZZZZZZZZZ', $student)->assertStatus(422), 'guardian'))->toBe('fam_unit.validation.guardian_not_found')
        ->and(Fam::errorCode(Fam::link($tenantA, $adminA, 'mal', $student)->assertStatus(422), 'guardian'))->toBe('fam_unit.validation.reference_invalid')
        ->and(Fam::errorCode(Fam::call($tenantA, $adminA, 'post', '/guardian-links', ['relationship' => 'madre'])->assertStatus(422), 'student_person'))->toBe('fam_unit.validation.required');
});

test('api.md §2.4: marcar la representación legal rellena la verificación; desmarcarla la vacía; repetir el mismo valor no la refresca', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-legal');
    $link = Fam::link($tenant, $admin, $guardian, $student)->assertCreated()->json('public_id');

    $marked = Fam::call($tenant, $admin, 'patch', "/guardian-links/{$link}", ['is_legal_guardian' => true])->assertOk();
    $verifiedAt = $marked->json('legal_guardian_verified_at');

    expect($verifiedAt)->not->toBeNull();

    $again = Fam::call($tenant, $admin, 'patch', "/guardian-links/{$link}", ['is_legal_guardian' => true, 'relationship' => 'tutor_legal'])->assertOk();
    expect($again->json('legal_guardian_verified_at'))->toBe($verifiedAt)->and($again->json('relationship'))->toBe('tutor_legal');

    $unmarked = Fam::call($tenant, $admin, 'patch', "/guardian-links/{$link}", ['is_legal_guardian' => false])->assertOk();
    expect($unmarked->json('legal_guardian_verified_at'))->toBeNull();

    $row = Fam::row($tenant, 'guardian_student_links', $link);
    expect($row->legal_guardian_verified_by)->toBeNull();
});

test('CA-FAM-UNIT-037 api.md §2.4: PATCH no admite status, revocation, guardian ni student_person (422 not_editable) y no cambia nada', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-037');
    $link = Fam::link($tenant, $admin, $guardian, $student)->assertCreated()->json('public_id');

    foreach (['status' => 'revocado', 'revocation' => ['reason' => 'resolucion_judicial'], 'guardian' => $guardian, 'student_person' => $student->public_id] as $field => $value) {
        $response = Fam::call($tenant, $admin, 'patch', "/guardian-links/{$link}", [$field => $value])->assertStatus(422);
        expect(Fam::errorCode($response, $field))->toBe('fam_unit.link.not_editable');
    }

    Fam::call($tenant, $admin, 'get', "/guardian-links/{$link}")->assertOk()->assertJsonPath('status', 'activo');
    expect(Fam::audit($tenant, 'guardian_student_link', 'updated'))->toBe([]);
});

test('RN-FAM-UNIT-04 INV-003: el PATCH de atributos y la baja lógica del vínculo quedan auditados; el detalle de un borrado es 404', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-patch');
    $link = Fam::link($tenant, $admin, $guardian, $student)->assertCreated()->json('public_id');

    Fam::call($tenant, $admin, 'patch', "/guardian-links/{$link}", ['access_level' => 'lectura_escritura', 'restricted_categories' => ['salud']])
        ->assertOk()->assertJsonPath('access_level', 'lectura_escritura')->assertJsonPath('restricted_categories', ['salud']);

    $updated = Fam::audit($tenant, 'guardian_student_link', 'updated');
    expect($updated)->toHaveCount(1)->and(array_keys($updated[0]->changes))->toContain('access_level', 'restricted_categories');

    Fam::call($tenant, $admin, 'delete', "/guardian-links/{$link}")->assertNoContent();
    Fam::call($tenant, $admin, 'get', "/guardian-links/{$link}")->assertNotFound();
    Fam::call($tenant, $admin, 'delete', "/guardian-links/{$link}")->assertNotFound();
    expect(Fam::audit($tenant, 'guardian_student_link', 'deleted'))->toHaveCount(1);
});

test('api.md §3: GET /guardian-links filtra por family_unit, guardian, student_person, is_emergency_contact, is_legal_guardian y status; status=revocado exige revocacion_tutor.leer', function (): void {
    [$tenant, $admin, $unit, $guardian, $student] = linkScenario('gl-list');
    [, $second] = Fam::familyUser($tenant, $admin, 'segundo', $unit);
    $other = Fam::person($tenant, 'Otra');

    Fam::link($tenant, $admin, $guardian, $student, ['is_emergency_contact' => true, 'is_legal_guardian' => true])->assertCreated();
    Fam::link($tenant, $admin, $second, $student)->assertCreated();
    $revoked = Fam::link($tenant, $admin, $second, $other)->assertCreated()->json('public_id');
    Fam::call($tenant, $admin, 'post', "/guardian-links/{$revoked}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    $total = fn (string $query): int => Fam::call($tenant, $admin, 'get', '/guardian-links'.$query)->assertOk()->json('meta.total');

    expect($total(''))->toBe(3)
        ->and($total("?guardian={$guardian}"))->toBe(1)
        ->and($total("?guardian={$guardian},{$second}"))->toBe(3)
        ->and($total("?family_unit={$unit}"))->toBe(3)
        ->and($total("?student_person={$student->public_id}"))->toBe(2)
        ->and($total('?student_person=01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBe(0)
        ->and($total('?is_emergency_contact=true'))->toBe(1)
        ->and($total('?is_legal_guardian=true'))->toBe(1)
        ->and($total('?status=revocado'))->toBe(1)
        ->and($total('?status=activo'))->toBe(2);

    Fam::call($tenant, $admin, 'get', '/guardian-links?status=otro')->assertStatus(422);
    Fam::call($tenant, $admin, 'get', '/guardian-links?guardian=mal')->assertStatus(422);

    // Sin revocacion_tutor.leer: el filtro status=revocado es 403 y el campo revocation no aparece (api.md §1.4, D-2) y no aparecen los revocados (RN-FAM-UNIT-40).
    $reader = Fam::userWith($tenant, $admin, 'lector', ['vinculo_tutor.leer' => 'todos']);
    Fam::call($tenant, $reader, 'get', '/guardian-links?status=revocado')->assertForbidden();

    // RN-FAM-UNIT-40: sin ese permiso tampoco se ve el vínculo revocado en el listado general.
    $rows = Fam::call($tenant, $reader, 'get', '/guardian-links')->assertOk()->json('data');
    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row)->not->toHaveKey('revocation');
    }
});

test('api.md §2.4 permisos.md §2: crear, editar y borrar vínculos exigen vinculo_tutor.crear / actualizar / eliminar', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-perm');
    $link = Fam::link($tenant, $admin, $guardian, $student)->assertCreated()->json('public_id');

    $reader = Fam::userWith($tenant, $admin, 'lector', ['vinculo_tutor.leer' => 'todos']);

    Fam::link($tenant, $reader, $guardian, Fam::person($tenant, 'Otro'))->assertForbidden();
    Fam::call($tenant, $reader, 'patch', "/guardian-links/{$link}", ['relationship' => 'padre'])->assertForbidden();
    Fam::call($tenant, $reader, 'delete', "/guardian-links/{$link}")->assertForbidden();
    Fam::call($tenant, $reader, 'get', "/guardian-links/{$link}")->assertOk();

    $stranger = Fam::userWith($tenant, $admin, 'sin_permisos', ['usuario.leer' => 'todos']);
    Fam::call($tenant, $stranger, 'get', '/guardian-links')->assertForbidden();
    Fam::call($tenant, $stranger, 'get', "/guardian-links/{$link}")->assertForbidden();
});

test('datos.md §1.4: el CHECK de coherencia de estado, el de contacto de emergencia revocado y el de verificación sin representación legal los impone el motor', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-checks');
    $link = Fam::link($tenant, $admin, $guardian, $student)->assertCreated()->json('public_id');

    $attempt = function (array $values) use ($tenant, $link): ?string {
        try {
            app(TenantContext::class)->runFor($tenant->id, fn () => DB::transaction(fn () => DB::table('guardian_student_links')->where('public_id', $link)->update($values)));
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? null;
        }

        return null;
    };

    // Revocado sin motivo ni instante; activo con motivo; verificado sin representación legal; emergencia y revocado a la vez.
    expect($attempt(['status' => 'revocado']))->toBe('23514')
        ->and($attempt(['revocation_reason' => 'resolucion_judicial']))->toBe('23514')
        ->and($attempt(['legal_guardian_verified_at' => now()]))->toBe('23514')
        ->and($attempt(['status' => 'revocado', 'revocation_reason' => 'resolucion_judicial', 'revoked_at' => now(), 'is_emergency_contact' => true]))->toBe('23514')
        ->and($attempt(['status' => 'otro']))->toBe('23514')
        ->and($attempt(['relationship' => 'tio']))->toBe('23514')
        ->and($attempt(['access_level' => 'total']))->toBe('23514')
        ->and($attempt(['revocation_reason' => 'otro', 'status' => 'revocado', 'revoked_at' => now()]))->toBe('23514');
});

test('CA-FAM-UNIT-080: ningún dato personal acompaña al vínculo en audit_logs (solo códigos y marcas; tutor y alumno redactados)', function (): void {
    [$tenant, $admin, , $guardian, $student] = linkScenario('gl-080');
    Fam::link($tenant, $admin, $guardian, $student, ['is_legal_guardian' => true])->assertCreated();

    $created = Fam::audit($tenant, 'guardian_student_link', 'created')[0];
    $encoded = json_encode($created->changes);

    expect($encoded)->not->toContain($student->public_id)->and($encoded)->not->toContain($student->given_name)
        ->and($created->changes['relationship'])->toBe(['to' => 'madre', 'from' => null]);
});

test('datos.md §1.4: dos centros pueden tener el mismo par de ids internos sin choque y la clave foránea compuesta impide un vínculo con una persona de otro centro', function (): void {
    [$tenantA, , , $guardianA] = linkScenario('gl-fk-a');
    [$tenantB] = Fam::setup('gl-fk-b');
    $foreign = Fam::person($tenantB);

    $guardianId = app(TenantContext::class)->runFor($tenantA->id, fn () => DB::table('guardians')->where('public_id', $guardianA)->value('id'));
    $state = null;

    try {
        app(TenantContext::class)->runFor($tenantA->id, fn () => DB::transaction(fn () => DB::table('guardian_student_links')->insert([
            'public_id' => (string) Str::ulid(),
            'guardian_id' => $guardianId,
            'student_person_id' => $foreign->id,
            'relationship' => 'madre',
        ])));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    // 23503: la FK compuesta (tenant_id, student_person_id) no encuentra a la persona en el centro A.
    expect($state)->toBe('23503');
});
