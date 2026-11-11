<?php

use App\Models\User;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Modules\Alum\Domain\Models\GroupChangeRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;

// REQ-ALUM-003 (1.15-c T2a), RN-ALUM-47, RN-ACAD-21, INV-007, INV-015: tercer veto de borrado de `alum` sobre un grupo de
// `acad` (decisión del usuario 2026-11-11 = B): una solicitud de cambio de grupo `pendiente`, de origen o de destino, impide
// borrarlo; una resuelta, no. Registrado por `AlumServiceProvider`, contra la API real de `acad`.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Centro con un curso de etapa, su grupo A con una matrícula viva y un segundo grupo B vacío (destino), y una solicitud
 * `pendiente` de A a B.
 *
 * @return array{0: object, 1: object, 2: array<string, string>, 3: GroupChangeRequest, 4: array{from: int, to: int}}
 */
function gcvSetup(string $slug): array
{
    [$tenant, $admin, $year] = Api::setup($slug);
    contractModule($tenant, 'alum');
    $tree = Api::tree($tenant, $admin, $year);
    resetSessionState();
    $to = test()->actingAs($admin)->postJson(Api::url($tenant, '/groups'), ['course' => $tree['course'], 'code' => 'B', 'name' => 'Grupo B'])->assertCreated()->json('public_id');
    $ids = ['year' => $year->id, 'course' => Api::row($tenant, 'courses', $tree['course'])->id, 'from' => Api::row($tenant, 'groups', $tree['group'])->id, 'to' => Api::row($tenant, 'groups', $to)->id];

    $request = app(TenantContext::class)->runFor($tenant->id, function () use ($ids): GroupChangeRequest {
        $enrollment = Enrollment::factory()->create(['academic_year_id' => $ids['year'], 'course_id' => $ids['course'], 'group_id' => $ids['from']]);

        return GroupChangeRequest::factory()->create(['enrollment_id' => $enrollment->id, 'from_group_id' => $ids['from'], 'to_group_id' => $ids['to']]);
    });

    return [$tenant, $admin, $tree + ['to' => $to], $request, $ids];
}

function gcvDelete(object $tenant, object $admin, string $path)
{
    resetSessionState();

    return test()->actingAs($admin)->deleteJson(Api::url($tenant, $path));
}

function gcvResolve(object $tenant, GroupChangeRequest $request, string $status): void
{
    app(TenantContext::class)->runFor($tenant->id, function () use ($request, $status): void {
        $request->forceFill(['status' => $status, 'decided_by' => User::factory()->create()->id, 'decided_at' => now()])->save();
    });
}

test('CA-ALUM-027 RN-ALUM-47: un grupo de destino con una solicitud pendiente no se borra (409 has_dependents con group_change_requests); resuelta la solicitud, sí', function (): void {
    [$tenant, $admin, $tree, $request] = gcvSetup('alum-gcv-to');

    $response = gcvDelete($tenant, $admin, "/groups/{$tree['to']}")->assertStatus(409);

    expect($response->json('errors.dependents.0.code'))->toBe('acad.conflict.has_dependents')
        ->and($response->json('errors.dependents.0.params.dependents'))->toBe(['group_change_requests'])
        ->and(Api::row($tenant, 'groups', $tree['to'])->deleted_at)->toBeNull();

    // La cuenta es de lo `pendiente`: una cancelada ya no veta.
    gcvResolve($tenant, $request, 'cancelada');

    gcvDelete($tenant, $admin, "/groups/{$tree['to']}")->assertNoContent();
});

test('CA-ALUM-027 RN-ALUM-47: el grupo de origen cuenta la matrícula y la solicitud pendiente, en ese orden; una solicitud de otro centro no veta', function (): void {
    [$tenant, $admin, $tree, $request] = gcvSetup('alum-gcv-from');
    // Quita lo propio de la prueba que bloquearía el borrado antes que `alum`.
    gcvDelete($tenant, $admin, "/subgroups/{$tree['subgroup']}")->assertNoContent();
    gcvDelete($tenant, $admin, "/subjects/{$tree['subject']}")->assertNoContent();

    $response = gcvDelete($tenant, $admin, "/groups/{$tree['group']}")->assertStatus(409);

    expect($response->json('errors.dependents.0.params.dependents'))->toBe(['enrollments', 'group_change_requests']);

    gcvResolve($tenant, $request, 'cancelada');

    // Sin la solicitud pendiente queda solo el veto de la matrícula, que sigue en pie.
    expect(gcvDelete($tenant, $admin, "/groups/{$tree['group']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))->toBe(['enrollments']);

    // El curso de etapa no tiene este veto: solo sus matrículas lo vetan.
    $course = gcvDelete($tenant, $admin, "/courses/{$tree['course']}")->assertStatus(409)->json('errors.dependents.0.params.dependents');
    expect($course)->toContain('enrollments')->not->toContain('group_change_requests');
});

test('INV-001 RN-ALUM-47: una solicitud pendiente de otro centro no veta el borrado de un grupo del centro', function (): void {
    [$tenantB] = gcvSetup('alum-gcv-iso-b');
    resetSessionState();
    [$tenantA, $adminA, $treeA] = gcvSetup('alum-gcv-iso-a');
    gcvResolve($tenantA, app(TenantContext::class)->runFor($tenantA->id, fn () => GroupChangeRequest::query()->firstOrFail()), 'cancelada');

    expect($tenantB->id)->not->toBe($tenantA->id);
    gcvDelete($tenantA, $adminA, "/groups/{$treeA['to']}")->assertNoContent();
});
