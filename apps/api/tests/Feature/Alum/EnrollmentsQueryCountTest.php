<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\AlumEnrollmentHelpers as En;
use Tests\Support\AlumTestHelpers as Alum;

// REQ-ALUM-002 (1.15-b), docs/modulos/REQ-ALUM/funcional.md §11.4, CA-ALUM-040: la lista de clase no hace N+1; INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Número de consultas SQL que hace una petición `GET`. */
function queryCount(array $s, string $path): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    Alum::call($s['tenant'], $s['admin'], 'get', $path)->assertOk();

    return $count;
}

/** Matricula `$n` alumnos nuevos en el grupo A. */
function enrollMore(array &$s, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $student = Alum::student($s['tenant'], $s['admin'], ['given_name' => 'Extra', 'family_name_1' => 'Alumno'.count($s['students'])]);
        $s['students'][] = $student;
        Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', En::body($s, count($s['students']) - 1))->assertCreated();
    }
}

test('CA-ALUM-040: GET /enrollments (lista de clase) y GET /students con enrollment hacen las mismas consultas con 3 que con 15 matrículas en la página', function (): void {
    $s = En::scenario('enrqc-040');
    $list = fn () => "/enrollments?academic_year={$s['year']->public_id}&group={$s['api']['group']}&status=activa";
    $students = fn () => "/students?academic_year={$s['year']->public_id}&group={$s['api']['group']}";

    enrollMore($s, 3);
    // Primera llamada sin medir: calienta cachés de la petición (idiomas, permisos) que no dependen del tamaño.
    queryCount($s, $list());
    $listSmall = queryCount($s, $list());
    $studentsSmall = queryCount($s, $students());

    enrollMore($s, 12);

    expect(Alum::call($s['tenant'], $s['admin'], 'get', $list())->json('data'))->toHaveCount(15)
        ->and(queryCount($s, $list()))->toBe($listSmall)
        ->and(Alum::call($s['tenant'], $s['admin'], 'get', $students())->json('data'))->toHaveCount(15)
        ->and(queryCount($s, $students()))->toBe($studentsSmall);
});
