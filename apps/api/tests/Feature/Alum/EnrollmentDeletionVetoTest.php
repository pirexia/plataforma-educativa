<?php

use App\Modules\Alum\Domain\Models\Enrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;

// REQ-ALUM-002 (1.15-b T1), RN-ALUM-26, RN-ACAD-21, CA-ALUM-027, INV-007, INV-015: los dos vetos de borrado de `alum`
// (grupo y curso de etapa), registrados por `AlumServiceProvider`, contra la API real de `acad`. CA-ALUM-027 queda
// cubierto en lo que `acad` ejecuta; la matrícula por *endpoint* llega con el T2.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * @return array{0: object, 1: object, 2: array<string, string>, 3: array{year: int, course: int, group: int}}
 */
function vetoSetup(string $slug): array
{
    [$tenant, $admin, $year] = Api::setup($slug);
    contractModule($tenant, 'alum');
    $tree = Api::tree($tenant, $admin, $year);
    $ids = ['year' => $year->id, 'course' => Api::row($tenant, 'courses', $tree['course'])->id, 'group' => Api::row($tenant, 'groups', $tree['group'])->id];

    return [$tenant, $admin, $tree, $ids];
}

function vetoEnroll(object $tenant, array $ids, bool $withGroup, array $overrides = []): Enrollment
{
    return app(TenantContext::class)->runFor($tenant->id, fn (): Enrollment => Enrollment::factory()->create(array_merge([
        'academic_year_id' => $ids['year'],
        'course_id' => $ids['course'],
        'group_id' => $withGroup ? $ids['group'] : null,
    ], $overrides)));
}

function vetoDeleteJson(object $tenant, object $admin, string $path)
{
    resetSessionState();

    return test()->actingAs($admin)->deleteJson(Api::url($tenant, $path));
}

/** Quita el subgrupo y la asignatura de la prueba, que bloquearían el borrado antes que `alum`. */
function vetoClearOwnChildren(object $tenant, object $admin, array $tree): void
{
    vetoDeleteJson($tenant, $admin, "/subgroups/{$tree['subgroup']}")->assertNoContent();
    vetoDeleteJson($tenant, $admin, "/subjects/{$tree['subject']}")->assertNoContent();
}

test('CA-ALUM-027 RN-ALUM-26: un grupo con una matrícula viva no se borra (409 has_dependents con enrollments); sin ella, sí', function (): void {
    [$tenant, $admin, $tree, $ids] = vetoSetup('alum-veto-group');
    vetoClearOwnChildren($tenant, $admin, $tree);
    $enrollment = vetoEnroll($tenant, $ids, withGroup: true);

    $response = vetoDeleteJson($tenant, $admin, "/groups/{$tree['group']}")->assertStatus(409);

    expect($response->json('errors.dependents.0.code'))->toBe('acad.conflict.has_dependents')
        ->and($response->json('errors.dependents.0.params.dependents'))->toBe(['enrollments'])
        ->and(Api::row($tenant, 'groups', $tree['group'])->deleted_at)->toBeNull();

    // Una matrícula en baja sigue siendo viva (`deleted_at IS NULL`): el veto sigue en pie.
    app(TenantContext::class)->runFor($tenant->id, function () use ($enrollment): void {
        $enrollment->forceFill(['status' => 'baja', 'withdrawn_on' => '2027-12-01', 'withdrawal_reason' => 'traslado'])->save();
    });
    vetoDeleteJson($tenant, $admin, "/groups/{$tree['group']}")->assertStatus(409);

    // Anulada (borrado lógico) deja de vetar.
    app(TenantContext::class)->runFor($tenant->id, fn () => $enrollment->delete());
    vetoDeleteJson($tenant, $admin, "/groups/{$tree['group']}")->assertNoContent();
});

test('CA-ALUM-027 RN-ALUM-26: un curso de etapa con una matrícula viva no se borra; cuenta también la matrícula sin grupo y no depende del veto de grupo', function (): void {
    [$tenant, $admin, $tree, $ids] = vetoSetup('alum-veto-course');
    vetoClearOwnChildren($tenant, $admin, $tree);
    $enrollment = vetoEnroll($tenant, $ids, withGroup: false);

    // El grupo no tiene matrículas (la matrícula no tiene grupo): se borra; el curso, no.
    vetoDeleteJson($tenant, $admin, "/groups/{$tree['group']}")->assertNoContent();
    $response = vetoDeleteJson($tenant, $admin, "/courses/{$tree['course']}")->assertStatus(409);

    expect($response->json('errors.dependents.0.code'))->toBe('acad.conflict.has_dependents')
        ->and($response->json('errors.dependents.0.params.dependents'))->toBe(['enrollments'])
        ->and(Api::row($tenant, 'courses', $tree['course'])->deleted_at)->toBeNull();

    app(TenantContext::class)->runFor($tenant->id, fn () => $enrollment->delete());
    vetoDeleteJson($tenant, $admin, "/courses/{$tree['course']}")->assertNoContent();
});

test('CA-ALUM-027 RN-ALUM-26: con una matrícula con grupo, los dos vetos son independientes y el del curso la cuenta también', function (): void {
    [$tenant, $admin, $tree, $ids] = vetoSetup('alum-veto-both');
    vetoClearOwnChildren($tenant, $admin, $tree);
    vetoEnroll($tenant, $ids, withGroup: true);

    expect(vetoDeleteJson($tenant, $admin, "/groups/{$tree['group']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))->toBe(['enrollments'])
        ->and(vetoDeleteJson($tenant, $admin, "/courses/{$tree['course']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))->toContain('enrollments');
});

test('INV-001 RN-ALUM-26: una matrícula de otro centro no veta el borrado de un grupo del centro', function (): void {
    [$tenantB, , , $idsB] = vetoSetup('alum-veto-iso-b');
    vetoEnroll($tenantB, $idsB, withGroup: true);
    resetSessionState();
    [$tenantA, $adminA, $treeA] = vetoSetup('alum-veto-iso-a');
    vetoClearOwnChildren($tenantA, $adminA, $treeA);

    vetoDeleteJson($tenantA, $adminA, "/groups/{$treeA['group']}")->assertNoContent();
});
