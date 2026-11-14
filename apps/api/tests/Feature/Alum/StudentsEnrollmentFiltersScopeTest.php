<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\AlumEnrollmentHelpers as En;
use Tests\Support\AlumTestHelpers as Alum;

// REQ-ALUM-002 (1.15-b, corrección de la revisión de seguridad M1), docs/modulos/REQ-ALUM/api.md §1: los filtros de matrícula
// de `GET /students` (`course`, `group`, `enrollment_status`) exigen `matricula.leer` y se acotan por su ámbito.
// CA-ALUM-016, RN-ALUM-60, RN-ALUM-61, RN-ALUM-64; INV-002, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Alumnos 0 y 1 en el grupo A y el 2 en el grupo B, todos con matrícula activa. */
function filtersScenario(string $slug): array
{
    $s = En::scenario($slug);

    foreach ([0 => $s['api']['group'], 1 => $s['api']['group'], 2 => $s['groupB']] as $i => $group) {
        Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', En::body($s, $i, ['group' => $group]))->assertCreated();
    }

    return $s;
}

/** Asigna al docente la asignatura del grupo `$group`. */
function filtersAssign(array $s, User $teacher, string $group): void
{
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$group}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();
    Tt::postAssignment($s['tenant'], $s['admin'], $teacher, $group, $s['api']['subject'])->assertCreated();
}

/** @return list<string> */
function filtersList(array $s, User $as, string $query): array
{
    return array_column(Alum::call($s['tenant'], $as, 'get', "/students?academic_year={$s['year']->public_id}{$query}")->assertOk()->json('data'), 'public_id');
}

test('CA-ALUM-016 RN-ALUM-64 INV-002: sin matricula.leer, los filtros course, group y enrollment_status de GET /students son 403 filter_forbidden; academic_year solo sigue siendo 200', function (): void {
    $s = filtersScenario('stflt-403');
    $reader = Alum::userWithScopes($s['tenant'], $s['admin'], 'lector', ['alumno.leer' => 'todos']);

    foreach (["&group={$s['api']['group']}", "&course={$s['api']['course']}", '&enrollment_status=activa'] as $query) {
        $r = Alum::call($s['tenant'], $reader, 'get', "/students?academic_year={$s['year']->public_id}{$query}")->assertForbidden();

        expect($r->json('detail'))->toBe(__('alum.validation.filter_forbidden'));
    }

    // Sin filtro de matrícula no hay nada que proteger: los tres alumnos.
    expect(filtersList($s, $reader, ''))->toHaveCount(3);

    // Con matricula.leer sí se filtra.
    expect(filtersList($s, $s['admin'], "&group={$s['groupB']}"))->toBe([$s['students'][2]['public_id']]);
});

test('CA-ALUM-016 RN-ALUM-60 RN-ALUM-61: con alumno.leer todos y matricula.leer de ámbito grupo, un docente de G1 que filtra por G2 recibe lista vacía; por G1, solo los de G1', function (): void {
    $s = filtersScenario('stflt-grupo');
    $mixed = Alum::userWithScopes($s['tenant'], $s['admin'], 'mixto', ['alumno.leer' => 'todos', 'matricula.leer' => 'grupo']);
    $docente = Alum::userWithScopes($s['tenant'], $s['admin'], 'docente', ['alumno.leer' => 'grupo', 'matricula.leer' => 'grupo']);
    filtersAssign($s, $mixed, $s['api']['group']);
    filtersAssign($s, $docente, $s['api']['group']);
    $inA = [$s['students'][0]['public_id'], $s['students'][1]['public_id']];

    foreach ([$mixed, $docente] as $user) {
        expect(filtersList($s, $user, "&group={$s['groupB']}"))->toBe([])
            ->and(filtersList($s, $user, "&group={$s['api']['group']}"))->toEqualCanonicalizing($inA)
            ->and(filtersList($s, $user, "&course={$s['api']['course']}&enrollment_status=activa"))->toEqualCanonicalizing($inA)
            ->and(filtersList($s, $user, '&enrollment_status=baja'))->toBe([]);
    }

    // Sin la acotación por ámbito, `mixto` (alumno.leer todos) vería también al alumno del grupo B.
    expect(filtersList($s, $mixed, ''))->toHaveCount(3);
});
