<?php

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\AlumTestHelpers as Alum;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-ALUM-001 (1.15-a T2), CA-ALUM-018a, INV-001, skill aislamiento-tenant: dos centros con alumnos equivalentes; desde el
// centro A, ningún endpoint de alumnado devuelve, modifica ni cuenta datos del B. Lo del otro centro responde 404, no
// 403 (no revelar existencia).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, mixed> */
function alumCentre(string $slug): array
{
    [$tenant, $admin] = Alum::setup($slug);
    $unit = Fam::unitId($tenant, $admin, 'SAME-CODE');
    $student = Alum::student($tenant, $admin, ['given_name' => 'Igual', 'family_name_1' => 'Alumno', 'document_type' => 'dni', 'document_number' => Alum::dni(55555555), 'family_unit' => $unit]);

    return compact('tenant', 'admin', 'unit', 'student');
}

test('CA-ALUM-018a INV-001: desde el centro A, el alumno del B no se lista, ni se lee, ni se modifica, ni se da de baja, ni se restaura: 404 idéntico a un inexistente, y no cambia', function (): void {
    $a = alumCentre('alum-018a');
    $b = alumCentre('alum-018b');
    $foreign = $b['student']['public_id'];

    $list = Alum::call($a['tenant'], $a['admin'], 'get', '/students')->assertOk();

    expect(array_column($list->json('data'), 'public_id'))->toBe([$a['student']['public_id']])
        ->and($list->json('meta.total'))->toBe(1)
        ->and(Alum::call($a['tenant'], $a['admin'], 'get', '/students?q=Igual')->json('meta.total'))->toBe(1)
        ->and(Alum::call($a['tenant'], $a['admin'], 'get', '/students?include_deleted=true')->json('meta.total'))->toBe(1);

    $missing = Alum::call($a['tenant'], $a['admin'], 'get', '/students/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    $got = Alum::call($a['tenant'], $a['admin'], 'get', "/students/{$foreign}")->assertNotFound();

    expect(Arr::except($got->json(), ['instance', 'request_id']))->toBe(Arr::except($missing->json(), ['instance', 'request_id']));

    Alum::call($a['tenant'], $a['admin'], 'patch', "/students/{$foreign}", ['given_name' => 'Hackeado'])->assertNotFound();
    Alum::call($a['tenant'], $a['admin'], 'delete', "/students/{$foreign}")->assertNotFound();
    Alum::call($a['tenant'], $a['admin'], 'post', "/students/{$foreign}/restore")->assertNotFound();

    // El alumno del B sigue intacto, visto desde el B.
    $still = Alum::call($b['tenant'], $b['admin'], 'get', "/students/{$foreign}")->assertOk();

    expect($still->json('given_name'))->toBe('Igual')
        ->and(Alum::row($b['tenant'], 'students', $foreign)->deleted_at)->toBeNull();
});

test('CA-ALUM-018a INV-001: el alta en A no ve las unidades ni los documentos de B: la unidad de B es family_unit_not_found y el mismo documento en dos centros no es person_exists', function (): void {
    $a = alumCentre('alum-018c');
    $b = alumCentre('alum-018d');

    // Mismo documento y mismo código de unidad en ambos centros: el alta de A no choca con B.
    $other = Alum::create($a['tenant'], $a['admin'], ['given_name' => 'Otro', 'document_type' => 'dni', 'document_number' => Alum::dni(66666666)])->assertCreated();
    Alum::create($b['tenant'], $b['admin'], ['given_name' => 'Otro', 'document_type' => 'dni', 'document_number' => Alum::dni(66666666)])->assertCreated();

    expect(Alum::errorCode(Alum::create($a['tenant'], $a['admin'], ['family_unit' => $b['unit']])->assertUnprocessable(), 'family_unit'))->toBe('alum.validation.family_unit_not_found')
        // Y el mismo documento dentro del centro sí es `person_exists`.
        ->and(Alum::errorCode(Alum::create($a['tenant'], $a['admin'], ['document_type' => 'dni', 'document_number' => Alum::dni(66666666)])->assertUnprocessable(), 'document_number'))->toBe('alum.validation.person_exists')
        ->and(Alum::count($a['tenant'], 'students'))->toBe(2)
        ->and(Alum::count($b['tenant'], 'students'))->toBe(2)
        ->and($other->json('public_id'))->not->toBe($b['student']['public_id']);

    // RLS: ni por SQL directo se ven filas del otro centro.
    expect(app(TenantContext::class)->runFor($a['tenant']->id, fn () => DB::table('students')->where('tenant_id', $b['tenant']->id)->count()))->toBe(0);
});
