<?php

use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Acad\Domain\Models\GroupSubject;
use App\Modules\Acad\Domain\Models\GroupTutor;
use App\Modules\Acad\Domain\Models\Subgroup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadTimetableHelpers as Tt;

// REQ-ACAD-002 (1.12-a), funcional.md §18.4.2 (RN-ACAD-96), §18.9: CA-ACAD-112.
// La asignación docente, la tutoría y los marcos horarios amplían los
// dependientes que bloquean el borrado de grupo, subgrupo, asignatura y etapa,
// y la sustitución del conjunto de asignaturas de un grupo. INV-004, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Árbol por la API con la asignatura asignada al grupo, y su asignación docente creada por el modelo. */
function ttDependentsSetup(string $slug): array
{
    [$tenant, $admin, $year] = Api::setup($slug);
    $api = Api::tree($tenant, $admin, $year);
    resetSessionState();
    test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$api['group']}/subjects"), ['subjects' => [$api['subject']]])->assertOk();
    resetSessionState();

    $models = app(TenantContext::class)->runFor($tenant->id, fn (): array => [
        'group' => Group::query()->where('public_id', $api['group'])->firstOrFail(),
        'subgroup' => Subgroup::query()->where('public_id', $api['subgroup'])->firstOrFail(),
        'groupSubject' => GroupSubject::query()->firstOrFail(),
    ]);

    return [$tenant, $admin, $year, $api, $models];
}

function ttDependentsOf($response): array
{
    $response->assertStatus(409);

    return $response->json('errors.dependents.0.params.dependents');
}

test('CA-ACAD-112 RN-ACAD-96: con una asignación docente viva, borrar el grupo, el subgrupo de la asignación o la asignatura es 409 has_dependents con teaching_assignments', function (): void {
    [$tenant, $admin, $year, $api, $models] = ttDependentsSetup('dep-112');
    $assignment = Tt::assignment($tenant, $year, $models['group'], $models['groupSubject'], $admin, $models['subgroup']);

    resetSessionState();
    expect(ttDependentsOf(test()->actingAs($admin)->deleteJson(Api::url($tenant, "/groups/{$api['group']}"))))->toContain('teaching_assignments');
    resetSessionState();
    expect(ttDependentsOf(test()->actingAs($admin)->deleteJson(Api::url($tenant, "/subgroups/{$api['subgroup']}"))))->toBe(['teaching_assignments']);
    resetSessionState();
    expect(ttDependentsOf(test()->actingAs($admin)->deleteJson(Api::url($tenant, "/subjects/{$api['subject']}"))))->toContain('teaching_assignments');

    // Borrada la asignación (lógico), todo se desbloquea.
    app(TenantContext::class)->runFor($tenant->id, fn () => $assignment->delete());
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/subgroups/{$api['subgroup']}"))->assertNoContent();
});

test('CA-ACAD-112 RN-ACAD-96: PUT /groups/{id}/subjects sin la asignatura con asignaciones vivas es 409 has_dependents y el conjunto no cambia; añadir asignaturas o dejar la asignada sí se admite', function (): void {
    [$tenant, $admin, $year, $api, $models] = ttDependentsSetup('dep-112b');
    Tt::assignment($tenant, $year, $models['group'], $models['groupSubject'], $admin);

    resetSessionState();
    expect(ttDependentsOf(test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$api['group']}/subjects"), ['subjects' => []])))->toBe(['teaching_assignments']);

    resetSessionState();
    $unchanged = test()->actingAs($admin)->getJson(Api::url($tenant, "/groups/{$api['group']}/subjects"))->assertOk()->json('data');
    expect(array_column($unchanged, 'public_id'))->toBe([$api['subject']]);

    // El mismo conjunto (idempotente) es 200.
    resetSessionState();
    test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$api['group']}/subjects"), ['subjects' => [$api['subject']]])->assertOk();
});

test('CA-ACAD-112 RN-ACAD-96: con un tutor vivo, borrar el grupo es 409 has_dependents con group_tutors', function (): void {
    [$tenant, $admin, $year, $api, $models] = ttDependentsSetup('dep-112c');

    // Sin asignatura asignada que bloquee antes: se quita el conjunto y el subgrupo.
    resetSessionState();
    test()->actingAs($admin)->putJson(Api::url($tenant, "/groups/{$api['group']}/subjects"), ['subjects' => []])->assertOk();
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/subgroups/{$api['subgroup']}"))->assertNoContent();

    $tutor = app(TenantContext::class)->runFor($tenant->id, fn (): GroupTutor => GroupTutor::create([
        'academic_year_id' => $year->id, 'group_id' => $models['group']->id, 'user_id' => $admin->id,
    ]));

    resetSessionState();
    expect(ttDependentsOf(test()->actingAs($admin)->deleteJson(Api::url($tenant, "/groups/{$api['group']}"))))->toBe(['group_tutors']);

    app(TenantContext::class)->runFor($tenant->id, fn () => $tutor->delete());
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/groups/{$api['group']}"))->assertNoContent();
});

test('CA-ACAD-112 RN-ACAD-96: con un marco horario vivo, borrar la etapa es 409 has_dependents con timetable_frames; borrado el marco, se puede', function (): void {
    [$tenant, $admin, $year] = Api::setup('dep-112d');
    $level = Api::level($tenant, $admin, $year);
    $frame = Tt::postFrame($tenant, $admin, Tt::frameBody($level))->assertCreated()->json('public_id');

    resetSessionState();
    expect(ttDependentsOf(test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$level}"))))->toBe(['timetable_frames']);

    Tt::deleteFrame($tenant, $admin, $frame)->assertNoContent();
    resetSessionState();
    test()->actingAs($admin)->deleteJson(Api::url($tenant, "/education-levels/{$level}"))->assertNoContent();
});

test('CA-ACAD-112 INV-004: los textos del 409 con los dependientes nuevos existen en los cuatro idiomas', function (): void {
    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        foreach (['group_tutors', 'teaching_assignments', 'timetable_frames', 'timetable_sessions', 'facility_bookings'] as $key) {
            expect(trans("acad.dependents.{$key}", [], $locale))->not->toBe("acad.dependents.{$key}");
        }

        foreach (['subgroup', 'facility', 'timetable_frame'] as $key) {
            expect(trans("acad.entities.{$key}", [], $locale))->not->toBe("acad.entities.{$key}");
        }
    }
});
