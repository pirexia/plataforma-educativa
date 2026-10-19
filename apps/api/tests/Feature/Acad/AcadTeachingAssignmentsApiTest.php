<?php

use App\Models\AuditLog;
use App\Models\UserStatus;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTimetableHelpers as Tt;

// REQ-ACAD-002 (1.12-a), funcional.md §18.4.2 (RN-ACAD-92 a -95), §18.9:
// CA-ACAD-107 a -111. Asignación docente: alta, validación de usuario,
// asignatura y subgrupo, unicidad, inmutabilidad y borrado. INV-003, INV-010, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ACAD-107 RN-ACAD-93: el alta devuelve 201 con Location, curso del grupo, profesor con nombre visible y subgrupo null, y deja una entrada created de auditoría', function (): void {
    $s = Tt::scenario('ta-107');

    $response = Tt::postAssignment($s['tenant'], $s['admin'], $s['t1'], $s['api']['group'], $s['api']['subject'])->assertCreated();

    expect($response->json('academic_year'))->toBe($s['year']->public_id)
        ->and($response->json('teacher.public_id'))->toBe($s['t1']->public_id)
        ->and($response->json('teacher.name'))->toBe('Persona Prueba')
        ->and($response->json('group'))->toBe($s['api']['group'])
        ->and($response->json('subject'))->toBe($s['api']['subject'])
        ->and($response->json('subgroup'))->toBeNull()
        ->and($response->headers->get('Location'))->toEndWith('/teaching-assignments/'.$response->json('public_id'));

    app(TenantContext::class)->runFor($s['tenant']->id, function () use ($response): void {
        $log = AuditLog::query()->where('auditable_type', 'teaching_assignment')->where('event', 'created')->first();

        expect($log)->not->toBeNull();
        expect(TeachingAssignment::query()->where('public_id', $response->json('public_id'))->exists())->toBeTrue();
    });

    Tt::send($s['tenant'], $s['admin'], 'GET', '/teaching-assignments/'.$response->json('public_id'))->assertOk()->assertJsonPath('teacher.name', 'Persona Prueba');
});

test('CA-ACAD-107 RN-ACAD-91: un academic_year en el cuerpo es 422 academic_year_not_allowed', function (): void {
    $s = Tt::scenario('ta-107b');

    $response = Tt::send($s['tenant'], $s['admin'], 'POST', '/teaching-assignments', [
        'teacher' => $s['t1']->public_id, 'group' => $s['api']['group'], 'subject' => $s['api']['subject'], 'academic_year' => $s['year']->public_id,
    ])->assertStatus(422);

    expect($response->json('errors.academic_year.0.code'))->toBe('acad.validation.academic_year_not_allowed');
});

test('CA-ACAD-108 RN-ACAD-93: asignatura no asignada al grupo, o asignada y borrada, es 422 subject_not_in_group; subgrupo de otro grupo es 422 subgroup_not_in_group', function (): void {
    $s = Tt::scenario('ta-108');
    $tenant = $s['tenant'];
    $admin = $s['admin'];

    // Otra asignatura del curso que el grupo no cursa.
    resetSessionState();
    $other = test()->actingAs($admin)->postJson(Api::url($tenant, '/subjects'), ['course' => $s['api']['course'], 'code' => 'LEN', 'name_translations' => ['es-ES' => 'Lengua']])->assertCreated()->json('public_id');

    $r = Tt::postAssignment($tenant, $admin, $s['t1'], $s['api']['group'], $other)->assertStatus(422);
    expect($r->json('errors.subject.0.code'))->toBe('acad.validation.subject_not_in_group');

    // Asignada y luego quitada del grupo.
    Tt::send($tenant, $admin, 'PUT', "/groups/{$s['api']['group']}/subjects", ['subjects' => []])->assertOk();
    $r = Tt::postAssignment($tenant, $admin, $s['t1'], $s['api']['group'], $s['api']['subject'])->assertStatus(422);
    expect($r->json('errors.subject.0.code'))->toBe('acad.validation.subject_not_in_group');
    Tt::send($tenant, $admin, 'PUT', "/groups/{$s['api']['group']}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();

    // Subgrupo de otro grupo.
    $group2 = Tt::send($tenant, $admin, 'POST', '/groups', ['course' => $s['api']['course'], 'code' => 'B', 'name' => 'Grupo B'])->assertCreated()->json('public_id');
    $foreign = Tt::send($tenant, $admin, 'POST', "/groups/{$group2}/subgroups", ['code' => 'X1', 'name' => 'Otro'])->assertCreated()->json('public_id');
    $r = Tt::postAssignment($tenant, $admin, $s['t1'], $s['api']['group'], $s['api']['subject'], $foreign)->assertStatus(422);
    expect($r->json('errors.subgroup.0.code'))->toBe('acad.validation.subgroup_not_in_group');

    $r = Tt::postAssignment($tenant, $admin, $s['t1'], '01JZZZZZZZZZZZZZZZZZZZZZZZ', $s['api']['subject'])->assertStatus(422);
    expect($r->json('errors.group.0.code'))->toBe('acad.validation.parent_not_found');

    expect(Tt::count($tenant, 'teaching_assignments'))->toBe(0);
});

test('CA-ACAD-108 datos.md §9.5: la inserción directa con subgrupo de otro grupo o group_subject de otro grupo es 23503', function (): void {
    $s = Tt::scenario('ta-108b');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $group2 = Tt::send($tenant, $admin, 'POST', '/groups', ['course' => $s['api']['course'], 'code' => 'B', 'name' => 'Grupo B'])->assertCreated()->json('public_id');
    $foreignSub = Tt::send($tenant, $admin, 'POST', "/groups/{$group2}/subgroups", ['code' => 'X1', 'name' => 'Otro'])->assertCreated()->json('public_id');
    Tt::send($tenant, $admin, 'PUT', "/groups/{$group2}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($s, $group2, $foreignSub): void {
        $groupId = DB::table('groups')->where('public_id', $s['api']['group'])->value('id');
        $group2Id = DB::table('groups')->where('public_id', $group2)->value('id');
        $gsOwn = DB::table('group_subjects')->where('group_id', $groupId)->value('id');
        $gsOther = DB::table('group_subjects')->where('group_id', $group2Id)->value('id');
        $subId = DB::table('subgroups')->where('public_id', $foreignSub)->value('id');
        $row = ['tenant_id' => $s['tenant']->id, 'public_id' => (string) Str::ulid(), 'academic_year_id' => $s['year']->id, 'user_id' => $s['t1']->id, 'group_id' => $groupId, 'created_at' => now(), 'updated_at' => now()];

        foreach ([['group_subject_id' => $gsOwn, 'subgroup_id' => $subId], ['group_subject_id' => $gsOther, 'subgroup_id' => null]] as $bad) {
            try {
                DB::transaction(fn () => DB::table('teaching_assignments')->insert($row + $bad));
                $code = null;
            } catch (QueryException $e) {
                $code = $e->errorInfo[0];
            }

            expect($code)->toBe('23503');
        }
    });
});

test('CA-ACAD-109 RN-ACAD-92: usuario inexistente, de otro centro o inactivo es 422 user_not_found; un usuario sin rol docente es 201 y la asignación no le añade permisos', function (): void {
    $s = Tt::scenario('ta-109');
    [$tenantB, $adminB] = provisionCoreTenant('ta-109-b');
    contractModule($tenantB, 'acad');
    $foreign = Api::userWith($tenantB, $adminB, 'ajeno109', ['horarios.leer']);

    $inactive = Api::userWith($s['tenant'], $s['admin'], 'inactivo109', ['horarios.leer']);
    app(TenantContext::class)->runFor($s['tenant']->id, function () use ($inactive): void {
        $inactive->status = UserStatus::Inactivo;
        $inactive->save();
    });

    foreach (['01JZZZZZZZZZZZZZZZZZZZZZZZ', $foreign->public_id, $inactive->public_id] as $teacher) {
        $r = Tt::postAssignment($s['tenant'], $s['admin'], $teacher, $s['api']['group'], $s['api']['subject'])->assertStatus(422);
        expect($r->json('errors.teacher.0.code'))->toBe('acad.validation.user_not_found');
    }

    // t1 solo tiene horarios.leer (ningún rol docente): 201 y sigue sin poder escribir en la asignación docente.
    Tt::postAssignment($s['tenant'], $s['admin'], $s['t1'], $s['api']['group'], $s['api']['subject'])->assertCreated();
    Tt::send($s['tenant'], $s['t1'], 'POST', '/teaching-assignments', [])->assertForbidden();
    Tt::send($s['tenant'], $s['t1'], 'GET', '/teaching-assignments')->assertForbidden();
});

test('CA-ACAD-110 RN-ACAD-94: repetir (usuario, asignatura del grupo, sin subgrupo) es 422 assignment_exists; con subgrupo distinto o con otro usuario es 201', function (): void {
    $s = Tt::scenario('ta-110');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $g = $s['api']['group'];
    $m = $s['api']['subject'];

    Tt::postAssignment($tenant, $admin, $s['t1'], $g, $m)->assertCreated();
    $r = Tt::postAssignment($tenant, $admin, $s['t1'], $g, $m)->assertStatus(422);
    expect($r->json('errors.teacher.0.code'))->toBe('acad.validation.assignment_exists');

    Tt::postAssignment($tenant, $admin, $s['t1'], $g, $m, $s['api']['subgroup'])->assertCreated();
    $r = Tt::postAssignment($tenant, $admin, $s['t1'], $g, $m, $s['api']['subgroup'])->assertStatus(422);
    expect($r->json('errors.teacher.0.code'))->toBe('acad.validation.assignment_exists');

    Tt::postAssignment($tenant, $admin, $s['t2'], $g, $m)->assertCreated();

    expect(Tt::count($tenant, 'teaching_assignments'))->toBe(3);
});

test('CA-ACAD-111 RN-ACAD-95: PATCH no existe (405); DELETE sin sesiones es 204 con entrada deleted; con sesiones vivas es 409 has_dependents con timetable_sessions', function (): void {
    $s = Tt::scenario('ta-111');
    $tenant = $s['tenant'];
    $admin = $s['admin'];

    $id = Tt::postAssignment($tenant, $admin, $s['t1'], $s['api']['group'], $s['api']['subject'])->assertCreated()->json('public_id');
    Tt::send($tenant, $admin, 'PATCH', "/teaching-assignments/{$id}", ['teacher' => $s['t2']->public_id])->assertStatus(405);

    $session = Tt::postSession($tenant, $admin, $s['slots'][0], $id)->assertCreated()->json('public_id');
    $r = Tt::send($tenant, $admin, 'DELETE', "/teaching-assignments/{$id}")->assertStatus(409);
    expect($r->json('errors.dependents.0.params.dependents'))->toBe(['timetable_sessions']);

    Tt::send($tenant, $admin, 'DELETE', "/timetable-sessions/{$session}")->assertNoContent();
    Tt::send($tenant, $admin, 'DELETE', "/teaching-assignments/{$id}")->assertNoContent();
    Tt::send($tenant, $admin, 'GET', "/teaching-assignments/{$id}")->assertNotFound();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(AuditLog::query()->where('auditable_type', 'teaching_assignment')->where('event', 'deleted')->count())->toBe(1);
    });
});

test('RN-ACAD-95 listado: filtros por grupo, asignatura y profesor (múltiples), orden de alta e id desc; un filtro ajeno da colección vacía', function (): void {
    $s = Tt::scenario('ta-list');
    $tenant = $s['tenant'];
    $admin = $s['admin'];

    $a = Tt::postAssignment($tenant, $admin, $s['t1'], $s['api']['group'], $s['api']['subject'])->json('public_id');
    $b = Tt::postAssignment($tenant, $admin, $s['t2'], $s['api']['group'], $s['api']['subject'])->json('public_id');

    $ids = fn ($r) => array_column($r->json('data'), 'public_id');

    expect($ids(Tt::send($tenant, $admin, 'GET', '/teaching-assignments')->assertOk()))->toBe([$a, $b])
        ->and($ids(Tt::send($tenant, $admin, 'GET', '/teaching-assignments?sort=-id')))->toBe([$b, $a])
        ->and($ids(Tt::send($tenant, $admin, 'GET', '/teaching-assignments?teacher='.$s['t2']->public_id)))->toBe([$b])
        ->and($ids(Tt::send($tenant, $admin, 'GET', '/teaching-assignments?teacher='.$s['t1']->public_id.','.$s['t2']->public_id)))->toBe([$a, $b])
        ->and($ids(Tt::send($tenant, $admin, 'GET', '/teaching-assignments?group='.$s['api']['group'].'&subject='.$s['api']['subject'])))->toBe([$a, $b])
        ->and($ids(Tt::send($tenant, $admin, 'GET', '/teaching-assignments?group=01JZZZZZZZZZZZZZZZZZZZZZZZ')))->toBe([])
        ->and($ids(Tt::send($tenant, $admin, 'GET', '/teaching-assignments?teacher=01JZZZZZZZZZZZZZZZZZZZZZZZ')))->toBe([]);
});
