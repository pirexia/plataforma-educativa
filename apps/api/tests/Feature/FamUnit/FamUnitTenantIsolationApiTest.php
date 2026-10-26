<?php

use App\Models\Person;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT (1.14-a), CA-FAM-UNIT-043, INV-001, skill aislamiento-tenant: dos centros con unidades, tutores, alumnado
// y vínculos equivalentes; desde el centro A, ningún endpoint del módulo devuelve, modifica ni cuenta datos del B. El
// de otro centro responde 404, no 403 (no revelar existencia).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, mixed> */
function isolationCentre(string $slug): array
{
    [$tenant, $admin] = Fam::setup($slug);
    $unit = Fam::unitId($tenant, $admin, 'SAME-CODE');
    [$user, $guardian] = Fam::familyUser($tenant, $admin, 'tutor', $unit);
    $student = Fam::person($tenant, 'Igual', 'Alumno');
    $membership = Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated()->json('public_id');
    $link = Fam::link($tenant, $admin, $guardian, $student)->assertCreated()->json('public_id');

    return compact('tenant', 'admin', 'unit', 'user', 'guardian', 'student', 'membership', 'link');
}

test('CA-FAM-UNIT-043 INV-001: desde el centro A, ningún endpoint de lectura del módulo devuelve ni cuenta datos del B (listados con totales propios, detalles 404)', function (): void {
    $a = isolationCentre('iso-a');
    $b = isolationCentre('iso-b');

    foreach (['/family-units', '/guardians', '/guardian-links'] as $path) {
        $response = Fam::call($a['tenant'], $a['admin'], 'get', $path)->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    }

    // Detalles y sub-recursos de B, pedidos con el administrador de A.
    foreach ([
        "/family-units/{$b['unit']}", "/family-units/{$b['unit']}/students",
        "/guardians/{$b['guardian']}", "/guardian-links/{$b['link']}",
    ] as $path) {
        Fam::call($a['tenant'], $a['admin'], 'get', $path)->assertNotFound();
    }

    // El filtro por un id de B no es error: devuelve vacío.
    expect(Fam::call($a['tenant'], $a['admin'], 'get', "/guardians?family_unit={$b['unit']}")->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', "/guardian-links?student_person={$b['student']->public_id}")->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', "/guardian-links?guardian={$b['guardian']}")->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', "/guardians?user={$b['user']->public_id}")->assertOk()->json('data'))->toBe([]);

    // Los códigos iguales no chocan entre centros (RN-FAM-UNIT-01 es por centro).
    expect(Fam::call($a['tenant'], $a['admin'], 'get', '/family-units')->json('data.0.code'))->toBe('SAME-CODE');
});

test('CA-FAM-UNIT-043 INV-001: desde el centro A, ningún endpoint de escritura modifica datos del B — todos responden 404 o 422 — y el centro B queda intacto', function (): void {
    $a = isolationCentre('iso-w-a');
    $b = isolationCentre('iso-w-b');

    $snapshot = fn (): array => app(TenantContext::class)->runFor($b['tenant']->id, fn (): array => [
        DB::table('family_units')->get()->all(),
        DB::table('guardians')->get()->all(),
        DB::table('family_unit_students')->get()->all(),
        DB::table('guardian_student_links')->get()->all(),
    ]);
    $before = $snapshot();

    $admin = fn (string $method, string $path, array $body = []) => Fam::call($a['tenant'], $a['admin'], $method, $path, $body);

    $admin('patch', "/family-units/{$b['unit']}", ['code' => 'HACK'])->assertNotFound();
    $admin('delete', "/family-units/{$b['unit']}")->assertNotFound();
    $admin('post', "/family-units/{$b['unit']}/restore")->assertNotFound();
    $admin('post', "/family-units/{$b['unit']}/students", ['student_person' => $a['student']->public_id])->assertNotFound();
    $admin('delete', "/family-units/{$b['unit']}/students/{$b['membership']}")->assertNotFound();
    $admin('patch', "/guardians/{$b['guardian']}", [])->assertNotFound();
    $admin('delete', "/guardians/{$b['guardian']}")->assertNotFound();
    $admin('patch', "/guardian-links/{$b['link']}", ['relationship' => 'padre'])->assertNotFound();
    $admin('delete', "/guardian-links/{$b['link']}")->assertNotFound();
    $admin('post', "/guardian-links/{$b['link']}/revocation", ['reason' => 'resolucion_judicial'])->assertNotFound();
    $admin('delete', "/guardian-links/{$b['link']}/revocation")->assertNotFound();

    // Mezclar ids de dos centros en una escritura propia tampoco funciona: 422, sin revelar existencia.
    expect(Fam::errorCode($admin('post', '/guardians', ['user' => $b['user']->public_id, 'family_unit' => $a['unit']])->assertStatus(422), 'user'))->toBe('fam_unit.validation.user_not_found')
        ->and(Fam::errorCode($admin('post', '/guardians', ['user' => $a['user']->public_id, 'family_unit' => $b['unit']])->assertStatus(422), 'family_unit'))->toBe('fam_unit.validation.family_unit_not_found')
        ->and(Fam::errorCode($admin('post', '/guardian-links', ['guardian' => $b['guardian'], 'student_person' => $a['student']->public_id, 'relationship' => 'madre'])->assertStatus(422), 'guardian'))->toBe('fam_unit.validation.guardian_not_found')
        ->and(Fam::errorCode($admin('post', '/guardian-links', ['guardian' => $a['guardian'], 'student_person' => $b['student']->public_id, 'relationship' => 'padre'])->assertStatus(422), 'student_person'))->toBe('fam_unit.validation.student_not_found')
        ->and(Fam::errorCode($admin('patch', "/family-units/{$a['unit']}", ['principal_guardian' => $b['guardian']])->assertStatus(422), 'principal_guardian'))->toBe('fam_unit.validation.principal_guardian_not_found');

    expect($snapshot())->toEqual($before);
});

test('CA-FAM-UNIT-043 ADR-044 §4.2: el tutor del centro A con ámbito unidad_familiar no alcanza nada del B, ni siquiera con el mismo código de unidad', function (): void {
    $a = isolationCentre('iso-s-a');
    $b = isolationCentre('iso-s-b');

    foreach (["/family-units/{$b['unit']}", "/guardians/{$b['guardian']}", "/guardian-links/{$b['link']}"] as $path) {
        Fam::call($a['tenant'], $a['user'], 'get', $path)->assertNotFound();
    }

    expect(Fam::call($a['tenant'], $a['user'], 'get', '/family-units')->json('meta.total'))->toBe(1)
        ->and(Fam::call($a['tenant'], $a['user'], 'get', '/guardian-links')->json('meta.total'))->toBe(1);
});

test('INV-001 ADR-033 §6: una persona del centro B no puede ser alumno ni tutor del A ni por la base de datos (la FK compuesta lo impide)', function (): void {
    $a = isolationCentre('iso-fk-a');
    $b = isolationCentre('iso-fk-b');

    $unitId = app(TenantContext::class)->runFor($a['tenant']->id, fn () => DB::table('family_units')->where('public_id', $a['unit'])->value('id'));
    $state = null;

    try {
        app(TenantContext::class)->runFor($a['tenant']->id, fn () => DB::transaction(fn () => DB::table('family_unit_students')->insert([
            'public_id' => (string) Str::ulid(),
            'family_unit_id' => $unitId,
            'student_person_id' => Person::query()->withoutGlobalScopes()->where('public_id', $b['student']->public_id)->value('id') ?? $b['student']->id,
        ])));
    } catch (QueryException $e) {
        $state = $e->errorInfo[0] ?? null;
    }

    expect($state)->toBe('23503');
});
