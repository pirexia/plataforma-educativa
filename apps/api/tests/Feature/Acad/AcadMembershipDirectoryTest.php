<?php

use App\Modules\Acad\Domain\GroupDirectory;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\TeachingMembership;
use App\Modules\Acad\Infrastructure\EloquentGroupDirectory;
use App\Modules\Acad\Infrastructure\EloquentTeachingMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTimetableHelpers as Tt;

// REQ-ACAD §7.2 (GroupDirectory), §18.7 y RN-ACAD-98 (TeachingMembership),
// CA-ALUM-017, INV-001, INV-007, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ALUM-017 RN-ACAD-98: groupIdsFor devuelve solo grupos con tutoría o asignación vivas del curso pedido (subgrupo proyectado) en una consulta', function (): void {
    $s = Tt::scenario('mem-017');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $user = $s['t1'];

    $post = fn (string $path, array $body) => Tt::send($tenant, $admin, 'POST', $path, $body)->assertCreated()->json('public_id');
    $lengua = $post('/subjects', ['course' => $s['api']['course'], 'code' => 'LEN', 'name_translations' => ['es-ES' => 'Lengua']]);
    $groupA = $s['api']['group'];
    $groupB = $post('/groups', ['course' => $s['api']['course'], 'code' => 'B', 'name' => 'B']);
    $groupC = $post('/groups', ['course' => $s['api']['course'], 'code' => 'C', 'name' => 'C']);
    $d1 = $post("/groups/{$groupC}/subgroups", ['code' => 'D1', 'name' => 'D1']);
    $groupD = $post('/groups', ['course' => $s['api']['course'], 'code' => 'D', 'name' => 'D']);
    $groupE = $post('/groups', ['course' => $s['api']['course'], 'code' => 'E', 'name' => 'E']);
    foreach ([$groupB => [$s['api']['subject']], $groupC => [$lengua], $groupD => [$s['api']['subject']]] as $group => $subjects) {
        Tt::send($tenant, $admin, 'PUT', "/groups/{$group}/subjects", ['subjects' => $subjects])->assertOk();
    }
    // A: tutoría; A y B además por asignación (sin duplicar); C: asignación en subgrupo; D: asignación borrada; E: sin nada.
    Tt::send($tenant, $admin, 'PUT', "/groups/{$groupA}/tutors", ['tutors' => [$user->public_id]])->assertOk();
    Tt::postAssignment($tenant, $admin, $user, $groupA, $s['api']['subject'])->assertCreated();
    Tt::postAssignment($tenant, $admin, $user, $groupB, $s['api']['subject'])->assertCreated();
    Tt::postAssignment($tenant, $admin, $user, $groupC, $lengua, $d1)->assertCreated();
    $deleted = Tt::postAssignment($tenant, $admin, $user, $groupD, $s['api']['subject'])->assertCreated()->json('public_id');
    Tt::send($tenant, $admin, 'DELETE', "/teaching-assignments/{$deleted}")->assertNoContent();
    // Otra tutoría, borrada.
    Tt::send($tenant, $admin, 'PUT', "/groups/{$groupE}/tutors", ['tutors' => [$user->public_id]])->assertOk();
    Tt::send($tenant, $admin, 'PUT', "/groups/{$groupE}/tutors", ['tutors' => []])->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $s, $groupA, $groupB, $groupC): void {
        $ids = Group::query()->whereIn('public_id', [$groupA, $groupB, $groupC])->orderBy('id')->pluck('id')->all();
        $membership = app(TeachingMembership::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $membership->groupIdsFor($user->id, $s['year']->id);
        $again = $membership->groupIdsFor($user->id, $s['year']->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($result)->toBe($ids)
            ->and($queries)->toBe(1)
            // Memoizada por petición: la segunda llamada no consulta.
            ->and($again)->toBe($ids)
            // Otro curso académico u otro usuario: nada.
            ->and($membership->groupIdsFor($user->id, $s['year']->id + 9999))->toBe([])
            ->and($membership->groupIdsFor($user->id + 9999, $s['year']->id))->toBe([]);
    });
});

test('INV-001 CA-ALUM-017: groupIdsFor no ve tutorías ni asignaciones de otro centro', function (): void {
    $a = Tt::scenario('mem-iso-a');
    Tt::send($a['tenant'], $a['admin'], 'PUT', "/groups/{$a['api']['group']}/tutors", ['tutors' => [$a['t1']->public_id]])->assertOk();
    $b = Tt::scenario('mem-iso-b');

    $fromB = app(TenantContext::class)->runFor($b['tenant']->id, fn (): array => app(TeachingMembership::class)->groupIdsFor($a['t1']->id, $a['year']->id));
    $fromA = app(TenantContext::class)->runFor($a['tenant']->id, fn (): array => app(TeachingMembership::class)->groupIdsFor($a['t1']->id, $a['year']->id));

    expect($fromB)->toBe([])->and($fromA)->toHaveCount(1);
});

test('§7.2 INV-001: GroupDirectory resuelve un grupo vivo a su resumen (curso, etapa y régimen); borrado u otro centro, null', function (): void {
    [$tenant, $admin, $year] = Api::setup('dir-grp');
    $tree = Api::tree($tenant, $admin, $year);
    resetSessionState();
    [$other, $otherAdmin, $otherYear] = Api::setup('dir-grp-b');
    $otherTree = Api::tree($other, $otherAdmin, $otherYear);

    app(TenantContext::class)->runFor($tenant->id, function () use ($tree, $year, $otherTree): void {
        $group = Group::query()->where('public_id', $tree['group'])->firstOrFail();
        $directory = app(GroupDirectory::class);
        $summary = $directory->findByPublicId($tree['group']);

        expect($summary)->not->toBeNull()
            ->and($summary->id)->toBe($group->id)
            ->and($summary->academicYearId)->toBe($year->id)
            ->and($summary->courseId)->toBe($group->course_id)
            ->and($summary->regime)->toBe('concertado')
            ->and($directory->findById($group->id))->toBe($summary)
            ->and($directory->findByPublicId($otherTree['group']))->toBeNull()
            ->and($directory->findByPublicId('01JA0000000000000000000099'))->toBeNull();
    });

    // Borrado lógico: ya no se resuelve (instancia nueva, sin memo de la petición anterior).
    vetoLessDelete($tenant, $admin, "/subgroups/{$tree['subgroup']}");
    vetoLessDelete($tenant, $admin, "/groups/{$tree['group']}");
    app()->forgetScopedInstances();

    app(TenantContext::class)->runFor($tenant->id, function () use ($tree): void {
        expect(app(GroupDirectory::class)->findByPublicId($tree['group']))->toBeNull()
            ->and(app(GroupDirectory::class))->toBeInstanceOf(EloquentGroupDirectory::class)
            ->and(app(TeachingMembership::class))->toBeInstanceOf(EloquentTeachingMembership::class);
    });
});

function vetoLessDelete(object $tenant, object $admin, string $path): void
{
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, $path))->assertNoContent();
}
