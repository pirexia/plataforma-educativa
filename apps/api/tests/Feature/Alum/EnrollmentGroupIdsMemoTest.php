<?php

use App\Modules\Alum\Application\EnrollmentService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\AlumEnrollmentHelpers as En;
use Tests\Support\AlumTestHelpers as Alum;

// REQ-ALUM-002 (1.15-b, corrección de la revisión de `db-reviewer`), RN-ALUM-63: `EnrollmentService::groupIdsOf()` se memoiza por
// petición (no barre `enrollments` en cada consulta acotada) y no cambia de resultado; entre peticiones no hay caché.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('RN-ALUM-63 CA-ALUM-013: groupIdsOf devuelve los grupos con asignación, consulta enrollments una sola vez por petición y no guarda nada entre peticiones', function (): void {
    $s = En::scenario('gidmemo');

    foreach ([0 => $s['api']['group'], 1 => $s['groupB']] as $i => $group) {
        Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', En::body($s, $i, ['group' => $group]))->assertCreated();
    }

    $teacher = Alum::userWithScopes($s['tenant'], $s['admin'], 'docente', ['alumno.leer' => 'grupo', 'matricula.leer' => 'grupo']);
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$s['api']['group']}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    $assignment = Tt::postAssignment($s['tenant'], $s['admin'], $teacher, $s['api']['group'], $s['api']['subject'])->assertCreated()->json('public_id');
    $groupA = Alum::row($s['tenant'], 'groups', $s['api']['group'])->id;

    $sweeps = 0;
    DB::listen(function ($query) use (&$sweeps): void {
        if (str_contains($query->sql, 'from "enrollments"') && str_contains($query->sql, 'distinct')) {
            $sweeps++;
        }
    });

    [$first, $second] = En::as($s['tenant'], $teacher, function () use ($teacher): array {
        $service = app(EnrollmentService::class);

        return [$service->groupIdsOf($teacher), app(EnrollmentService::class)->groupIdsOf($teacher)];
    });

    expect($first)->toBe([$groupA])->and($second)->toBe($first)->and($sweeps)->toBe(1);

    // Sin caché entre peticiones: retirada la asignación, la petición siguiente ya no la ve.
    Tt::send($s['tenant'], $s['admin'], 'DELETE', "/teaching-assignments/{$assignment}")->assertNoContent();
    $after = En::as($s['tenant'], $teacher, fn () => app(EnrollmentService::class)->groupIdsOf($teacher));

    expect($after)->toBe([]);
});
