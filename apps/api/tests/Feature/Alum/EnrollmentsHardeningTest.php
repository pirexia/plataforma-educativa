<?php

use App\Modules\Acad\Domain\CourseDirectory;
use App\Modules\Acad\Domain\GroupDirectory;
use App\Modules\Core\Domain\Models\DataExport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\AlumEnrollmentHelpers as En;
use Tests\Support\AlumTestHelpers as Alum;

// REQ-ALUM-002 (1.15-b, deudas #598, #599 y #601): legibilidad del curso en las escrituras de matrícula, tope de filas de la
// exportación y lecturas por lotes de `acad`. CA-ALUM-026, CA-ALUM-041, RN-ALUM-23, RN-ALUM-64, RNF-LIM-004, INV-002, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ALUM-026 RN-ALUM-23 RN-ALUM-64 (#598): sobre una matrícula de un curso cerrado, quien no tiene curso_historico.leer recibe 404 en PATCH, baja y anulación (no 409 academic-year-closed); con él, 409', function (): void {
    $s = En::scenario('enrhard-598');
    $id = Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', En::body($s))->assertCreated()->json('public_id');
    En::closeYear($s, $s['year']);

    $writer = Alum::userWith($s['tenant'], $s['admin'], 'escritor', ['matricula.actualizar', 'matricula.eliminar']);
    $historian = Alum::userWith($s['tenant'], $s['admin'], 'historico', ['matricula.actualizar', 'matricula.eliminar', 'curso_historico.leer']);
    $withdrawal = ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'traslado'];

    Alum::call($s['tenant'], $writer, 'patch', "/enrollments/{$id}", ['enrolled_on' => '2026-09-11'])->assertNotFound();
    Alum::call($s['tenant'], $writer, 'post', "/enrollments/{$id}/withdrawal", $withdrawal)->assertNotFound();
    Alum::call($s['tenant'], $writer, 'delete', "/enrollments/{$id}")->assertNotFound();

    foreach ([['patch', "/enrollments/{$id}", ['enrolled_on' => '2026-09-11']], ['post', "/enrollments/{$id}/withdrawal", $withdrawal], ['delete', "/enrollments/{$id}", []]] as [$method, $path, $body]) {
        Alum::call($s['tenant'], $historian, $method, $path, $body)->assertStatus(409)->assertJsonPath('type', 'urn:pge:error:academic-year-closed');
    }

    expect(Alum::row($s['tenant'], 'enrollments', $id)->status)->toBe('activa')
        ->and(En::liveCount($s['tenant']))->toBe(1);
});

test('CA-ALUM-041 RNF-LIM-004 (#599): POST /enrollments/exports respeta core.export_max_rows con el mismo 422 que los demás kinds, antes de encolar y sin crear la solicitud', function (): void {
    $s = En::scenario('enrhard-599');

    foreach ([0, 1, 2] as $i) {
        Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', En::body($s, $i))->assertCreated();
    }

    config(['core.export_max_rows' => 2]);
    $r = Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments/exports', ['academic_year' => $s['year']->public_id])->assertUnprocessable();

    expect(Alum::errorCode($r, 'filters'))->toBe('core.validation.export_range_too_large')
        ->and($r->json('errors.filters.0.params.max_rows'))->toBe(2)
        ->and(app(TenantContext::class)->runFor($s['tenant']->id, fn () => DataExport::query()->count()))->toBe(0);

    // Con el tope justo, 202.
    config(['core.export_max_rows' => 3]);
    Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments/exports', ['academic_year' => $s['year']->public_id])->assertStatus(202);
});

test('CA-ALUM-040 (#601): GroupDirectory y CourseDirectory::findManyById devuelven solo los vivos del centro, por id, en una consulta', function (): void {
    $s = En::scenario('enrhard-601');
    $ids = fn (string $table, string ...$publicIds) => array_map(fn (string $p): int => Alum::row($s['tenant'], $table, $p)->id, $publicIds);
    [$groupA, $groupB] = $ids('groups', $s['api']['group'], $s['groupB']);
    [$course] = $ids('courses', $s['api']['course']);

    $result = En::as($s['tenant'], $s['admin'], function () use ($groupA, $groupB, $course): array {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        return [
            app(GroupDirectory::class)->findManyById([$groupA, $groupB, $groupA, 999999999]),
            app(CourseDirectory::class)->findManyById([$course, 999999999]),
            $queries,
        ];
    });

    expect(array_keys($result[0]))->toBe([$groupA, $groupB])
        ->and($result[0][$groupA]->name)->toBe('Grupo A')
        ->and(array_keys($result[1]))->toBe([$course])
        ->and($result[1][$course]->code)->toBe('1ESO')
        ->and($result[2])->toBeLessThanOrEqual(4);
});
