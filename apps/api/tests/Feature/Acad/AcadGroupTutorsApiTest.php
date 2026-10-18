<?php

use App\Models\AuditLog;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\GroupTutor;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadTimetableHelpers as Tt;

// REQ-ACAD-002 (1.12-a), funcional.md §18.4.2 (RN-ACAD-97, -98), §18.9:
// CA-ACAD-113 (tutores de un grupo) y CA-ACAD-114 (proyección para ámbitos).
// INV-003, INV-010, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function tutorEvents(object $tenant, string $event): int
{
    return app(TenantContext::class)->runFor($tenant->id, fn (): int => AuditLog::query()->where('auditable_type', 'group_tutor')->where('event', $event)->count());
}

test('CA-ACAD-113 RN-ACAD-97: PUT con dos usuarios es 200 y dos created; repetir no escribe; con uno es un deleted; lista vacía deja sin tutores; los repetidos son un conjunto', function (): void {
    $s = Tt::scenario('tu-113');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $url = "/groups/{$s['api']['group']}/tutors";
    $both = [$s['t1']->public_id, $s['t2']->public_id];

    $r = Tt::send($tenant, $admin, 'PUT', $url, ['tutors' => [...$both, $s['t1']->public_id]])->assertOk();
    expect(array_column($r->json('data'), 'public_id'))->toBe($both)
        ->and($r->json('data.0.name'))->toBe('Persona Prueba')
        ->and(tutorEvents($tenant, 'created'))->toBe(2);

    Tt::send($tenant, $admin, 'PUT', $url, ['tutors' => $both])->assertOk();
    expect(tutorEvents($tenant, 'created'))->toBe(2)->and(tutorEvents($tenant, 'deleted'))->toBe(0);

    $r = Tt::send($tenant, $admin, 'PUT', $url, ['tutors' => [$s['t2']->public_id]])->assertOk();
    expect(array_column($r->json('data'), 'public_id'))->toBe([$s['t2']->public_id])
        ->and(tutorEvents($tenant, 'deleted'))->toBe(1);

    expect(Tt::send($tenant, $admin, 'GET', $url)->assertOk()->json('data'))->toHaveCount(1);
    expect(Tt::send($tenant, $admin, 'PUT', $url, ['tutors' => []])->assertOk()->json('data'))->toBe([]);
    expect(Tt::count($tenant, 'group_tutors'))->toBe(0);
});

test('CA-ACAD-113 RN-ACAD-92: un usuario inactivo o inexistente es 422 user_not_found en tutors.<n> y nada cambia; el grupo inexistente es 404', function (): void {
    $s = Tt::scenario('tu-113b');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $url = "/groups/{$s['api']['group']}/tutors";

    Tt::send($tenant, $admin, 'PUT', $url, ['tutors' => [$s['t1']->public_id]])->assertOk();

    $r = Tt::send($tenant, $admin, 'PUT', $url, ['tutors' => [$s['t2']->public_id, '01JZZZZZZZZZZZZZZZZZZZZZZZ']])->assertStatus(422);
    expect($r->json('errors')['tutors.1'][0]['code'])->toBe('acad.validation.user_not_found');

    expect(array_column(Tt::send($tenant, $admin, 'GET', $url)->json('data'), 'public_id'))->toBe([$s['t1']->public_id]);

    Tt::send($tenant, $admin, 'PUT', '/groups/01JZZZZZZZZZZZZZZZZZZZZZZZ/tutors', ['tutors' => []])->assertNotFound();
    Tt::send($tenant, $admin, 'GET', '/groups/01JZZZZZZZZZZZZZZZZZZZZZZZ/tutors')->assertNotFound();
    Tt::send($tenant, $admin, 'PUT', $url, [])->assertStatus(422);
});

test('CA-ACAD-114 RN-ACAD-98: grupo(u, curso) = tutorías ∪ asignaciones (también las de subgrupo, proyectadas al grupo) y clase(u, curso) = pares (grupo, asignatura); borradas y de otro curso no cuentan', function (): void {
    $s = Tt::scenario('tu-114');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $user = $s['t1'];

    // Grupos A (tutor), B (MAT) y C (LEN en el subgrupo D1), más una asignatura LEN.
    $post = fn (string $path, array $body) => Tt::send($tenant, $admin, 'POST', $path, $body)->assertCreated()->json('public_id');
    $lengua = $post('/subjects', ['course' => $s['api']['course'], 'code' => 'LEN', 'name_translations' => ['es-ES' => 'Lengua']]);
    $groupA = $s['api']['group'];
    $groupB = $post('/groups', ['course' => $s['api']['course'], 'code' => 'B', 'name' => 'B']);
    $groupC = $post('/groups', ['course' => $s['api']['course'], 'code' => 'C', 'name' => 'C']);
    $d1 = $post("/groups/{$groupC}/subgroups", ['code' => 'D1', 'name' => 'D1']);
    $groupD = $post('/groups', ['course' => $s['api']['course'], 'code' => 'D', 'name' => 'D']);
    foreach ([$groupB => [$s['api']['subject']], $groupC => [$lengua], $groupD => [$s['api']['subject']]] as $group => $subjects) {
        Tt::send($tenant, $admin, 'PUT', "/groups/{$group}/subjects", ['subjects' => $subjects])->assertOk();
    }
    Tt::send($tenant, $admin, 'PUT', "/groups/{$groupA}/tutors", ['tutors' => [$user->public_id]])->assertOk();
    Tt::postAssignment($tenant, $admin, $user, $groupB, $s['api']['subject'])->assertCreated();
    Tt::postAssignment($tenant, $admin, $user, $groupC, $lengua, $d1)->assertCreated();
    $deleted = Tt::postAssignment($tenant, $admin, $user, $groupD, $s['api']['subject'])->assertCreated()->json('public_id');
    Tt::send($tenant, $admin, 'DELETE', "/teaching-assignments/{$deleted}")->assertNoContent();

    // Consulta que respaldará TeachingMembership (funcional.md §18.7): solo filas vivas, del curso dado.
    $result = app(TenantContext::class)->runFor($tenant->id, function () use ($user, $s): array {
        $yearId = $s['year']->id;
        $assignments = TeachingAssignment::query()->where('user_id', $user->id)->where('academic_year_id', $yearId)->get();
        $groups = GroupTutor::query()->where('user_id', $user->id)->where('academic_year_id', $yearId)->pluck('group_id')
            ->merge($assignments->pluck('group_id'))->unique()->values();
        $pairs = $assignments->map(fn (TeachingAssignment $a): array => ['group_id' => $a->group_id, 'subject_id' => GroupSubject::query()->whereKey($a->group_subject_id)->value('subject_id')])->all();

        return [
            'groups' => Group::query()->whereIn('id', $groups)->orderBy('code')->pluck('code')->all(),
            'pairs' => collect($pairs)->map(fn (array $p): string => Group::query()->whereKey($p['group_id'])->value('code').':'.DB::table('subjects')->where('id', $p['subject_id'])->value('code'))->sort()->values()->all(),
            'otherYear' => TeachingAssignment::query()->where('user_id', $user->id)->where('academic_year_id', $yearId + 999)->count(),
        ];
    });

    expect($result['groups'])->toBe(['A', 'B', 'C'])
        ->and($result['pairs'])->toBe(['B:MAT', 'C:LEN'])
        ->and($result['otherYear'])->toBe(0);
});
