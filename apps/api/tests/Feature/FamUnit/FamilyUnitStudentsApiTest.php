<?php

use App\Modules\FamUnit\Domain\FamilyUnitStudents;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-001 (1.14-a), docs/modulos/REQ-FAM-UNIT/api.md §2.3, funcional.md §4.3, §5.2 y §7.4: alumnado de la
// unidad. CA-FAM-UNIT-010, -011 y -012, INV-003, INV-015. El alumno de prueba es una persona sintética
// (`funcional.md §14` punto 3): hasta `REQ-ALUM` (1.15) no hay alta de alumno por API.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('api.md §2.3 §1.3: añadir un alumno responde 201 con la forma de FamilyUnitStudent; el listado va ordenado por nombre y sin paginar', function (): void {
    [$tenant, $admin] = Fam::setup('fus-add');
    $unit = Fam::unitId($tenant, $admin);

    $zoe = Fam::person($tenant, 'Zoe', 'Zapata');
    $ana = Fam::person($tenant, 'Ana', 'Alba');

    $created = Fam::addStudent($tenant, $admin, $unit, $zoe)->assertCreated();
    Fam::addStudent($tenant, $admin, $unit, $ana)->assertCreated();

    expect($created->json())->toMatchArray(['family_unit' => $unit, 'student_person' => $zoe->public_id, 'display_name' => 'Zoe Zapata'])
        ->and(array_keys($created->json()))->toEqualCanonicalizing(['public_id', 'family_unit', 'student_person', 'display_name', 'created_at'])
        ->and($created->json('public_id'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

    $list = Fam::call($tenant, $admin, 'get', "/family-units/{$unit}/students")->assertOk();

    expect(array_column($list->json('data'), 'display_name'))->toBe(['Ana Alba', 'Zoe Zapata'])
        ->and($list->json())->not->toHaveKey('meta');

    Fam::call($tenant, $admin, 'get', "/family-units/{$unit}")->assertOk()->assertJsonPath('students_count', 2);
});

test('CA-FAM-UNIT-010 RN-FAM-UNIT-20: la persona de un alumno ya en la unidad A no se añade a la B (422 already_in_unit), ni dos veces a la misma; tras quitarla sí', function (): void {
    [$tenant, $admin] = Fam::setup('fus-010');
    $unitA = Fam::unitId($tenant, $admin, 'A');
    $unitB = Fam::unitId($tenant, $admin, 'B');
    $student = Fam::person($tenant);

    $membership = Fam::addStudent($tenant, $admin, $unitA, $student)->assertCreated()->json('public_id');

    foreach ([$unitB, $unitA] as $unit) {
        expect(Fam::errorCode(Fam::addStudent($tenant, $admin, $unit, $student)->assertStatus(422), 'student_person'))->toBe('fam_unit.student.already_in_unit');
    }

    Fam::call($tenant, $admin, 'delete', "/family-units/{$unitA}/students/{$membership}")->assertOk();
    Fam::addStudent($tenant, $admin, $unitB, $student)->assertCreated();
});

test('api.md §2.3 CA-FAM-UNIT-008: un alumno de otro centro o inexistente es 422 student_not_found con el mismo cuerpo; la unidad inexistente es 404 aunque el alumno sea inválido', function (): void {
    [$tenantA, $adminA] = Fam::setup('fus-nf-a');
    [$tenantB] = Fam::setup('fus-nf-b');
    $unit = Fam::unitId($tenantA, $adminA);
    $foreign = Fam::person($tenantB);

    $withForeign = Fam::addStudent($tenantA, $adminA, $unit, $foreign)->assertStatus(422);
    $withMissing = Fam::call($tenantA, $adminA, 'post', "/family-units/{$unit}/students", ['student_person' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertStatus(422);

    expect($withForeign->json('errors'))->toBe($withMissing->json('errors'))
        ->and(Fam::errorCode($withForeign, 'student_person'))->toBe('fam_unit.validation.student_not_found')
        ->and(Fam::errorCode(Fam::call($tenantA, $adminA, 'post', "/family-units/{$unit}/students", [])->assertStatus(422), 'student_person'))->toBe('fam_unit.validation.required')
        ->and(Fam::errorCode(Fam::call($tenantA, $adminA, 'post', "/family-units/{$unit}/students", ['student_person' => 'x'])->assertStatus(422), 'student_person'))->toBe('fam_unit.validation.reference_invalid');

    Fam::call($tenantA, $adminA, 'post', '/family-units/01JZZZZZZZZZZZZZZZZZZZZZZZ/students', ['student_person' => 'x'])->assertNotFound();
});

test('CA-FAM-UNIT-012 RN-FAM-UNIT-22: quitar de su unidad a un alumno con un vínculo vivo con un tutor de otra unidad conserva el vínculo y responde remaining_links = 1', function (): void {
    [$tenant, $admin] = Fam::setup('fus-012');
    $unitA = Fam::unitId($tenant, $admin, 'A');
    $unitB = Fam::unitId($tenant, $admin, 'B');
    [, $guardianB] = Fam::familyUser($tenant, $admin, 'tutorb', $unitB);
    $student = Fam::person($tenant);

    $membership = Fam::addStudent($tenant, $admin, $unitA, $student)->assertCreated()->json('public_id');
    $link = Fam::link($tenant, $admin, $guardianB, $student)->assertCreated()->json('public_id');

    Fam::call($tenant, $admin, 'delete', "/family-units/{$unitA}/students/{$membership}")->assertOk()->assertExactJson(['remaining_links' => 1]);

    Fam::call($tenant, $admin, 'get', "/guardian-links/{$link}")->assertOk()->assertJsonPath('status', 'activo');
    expect(Fam::call($tenant, $admin, 'get', "/family-units/{$unitA}/students")->assertOk()->json('data'))->toBe([]);

    // Una pertenencia que no es de esa unidad o no existe es 404.
    Fam::call($tenant, $admin, 'delete', "/family-units/{$unitB}/students/{$membership}")->assertNotFound();
    Fam::call($tenant, $admin, 'delete', "/family-units/{$unitA}/students/01JZZZZZZZZZZZZZZZZZZZZZZZ")->assertNotFound();
});

test('CA-FAM-UNIT-011 §7.4: la interfaz FamilyUnitStudents aplica las mismas reglas que el endpoint y produce la misma auditoría', function (): void {
    [$tenant, $admin] = Fam::setup('fus-011');
    $unitA = Fam::unitId($tenant, $admin, 'A');
    $unitB = Fam::unitId($tenant, $admin, 'B');
    $viaApi = Fam::person($tenant, 'Por', 'Api');
    $viaInterface = Fam::person($tenant, 'Por', 'Interfaz');

    Fam::addStudent($tenant, $admin, $unitA, $viaApi)->assertCreated();

    app(TenantContext::class)->runFor($tenant->id, function () use ($unitA, $unitB, $viaInterface, $viaApi): void {
        $students = app(FamilyUnitStudents::class);

        $membership = $students->add($unitA, $viaInterface->id);
        expect($membership)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

        // Mismas reglas: ya en una unidad, persona inexistente o de otro centro, unidad inexistente.
        foreach ([[$unitB, $viaInterface->id, 422, 'student.already_in_unit'], [$unitB, 999999999, 422, 'validation.student_not_found']] as [$unit, $person, $status, $code]) {
            try {
                $students->add($unit, $person);
                $this->fail('Debía fallar.');
            } catch (ApiException $e) {
                expect($e->status)->toBe($status)->and($e->errors['student_person'][0]['code'])->toBe("fam_unit.{$code}");
            }
        }

        try {
            $students->add('01JZZZZZZZZZZZZZZZZZZZZZZZ', $viaApi->id);
            $this->fail('Debía fallar.');
        } catch (ModelNotFoundException) {
            // Unidad inexistente: 404 en el endpoint.
            expect(true)->toBeTrue();
        }

        expect($students->remove($membership))->toBe(0);
    });

    $created = Fam::audit($tenant, 'family_unit_student', 'created');

    expect($created)->toHaveCount(2)
        ->and(array_keys($created[0]->changes))->toBe(array_keys($created[1]->changes))
        ->and(Fam::audit($tenant, 'family_unit_student', 'deleted'))->toHaveCount(1);
});

test('api.md §2.3: añadir o quitar alumnos exige familia.actualizar (no crear ni eliminar) y listar, familia.leer', function (): void {
    [$tenant, $admin] = Fam::setup('fus-perm');
    $unit = Fam::unitId($tenant, $admin);
    $student = Fam::person($tenant);
    $membership = Fam::addStudent($tenant, $admin, $unit, Fam::person($tenant, 'Otro'))->assertCreated()->json('public_id');

    $creator = Fam::userWith($tenant, $admin, 'creador', ['familia.crear' => 'todos', 'familia.eliminar' => 'todos', 'familia.leer' => 'todos']);
    Fam::addStudent($tenant, $creator, $unit, $student)->assertForbidden();
    Fam::call($tenant, $creator, 'delete', "/family-units/{$unit}/students/{$membership}")->assertForbidden();
    Fam::call($tenant, $creator, 'get', "/family-units/{$unit}/students")->assertOk();

    $updater = Fam::userWith($tenant, $admin, 'actualizador', ['familia.actualizar' => 'todos']);
    Fam::call($tenant, $updater, 'get', "/family-units/{$unit}/students")->assertForbidden();
    Fam::addStudent($tenant, $updater, $unit, $student)->assertCreated();
    Fam::call($tenant, $updater, 'delete', "/family-units/{$unit}/students/{$membership}")->assertOk();
});
