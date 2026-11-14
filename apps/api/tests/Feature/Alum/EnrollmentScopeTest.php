<?php

use App\Models\User;
use App\Modules\Alum\Application\EnrollmentService;
use App\Modules\Alum\Application\StudentService;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadTimetableHelpers as Tt;
use Tests\Support\AlumEnrollmentHelpers as En;
use Tests\Support\AlumTestHelpers as Alum;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-ALUM-002 (1.15-b T2a), docs/modulos/REQ-ALUM/permisos.md §3.1, funcional.md RN-ALUM-60 a -64, ADR-044 §4.2:
// resolutores `grupo` (alumno y matrícula) y `unidad_familiar` (matrícula), en listado Y en detalle, a nivel de servicio.
// CA-ALUM-010, -011, -013, -015, -016, INV-001, INV-002, INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Escenario: alumnos 0 y 1 en el grupo A, el 2 en el grupo B (mismo curso de etapa), todos con matrícula `activa`;
 * `$s['enrollments']` por posición. Docente con `alumno.leer` y `matricula.leer` de ámbito `grupo`.
 *
 * @return array<string, mixed>
 */
function scopeScenario(string $slug): array
{
    $s = En::scenario($slug);
    $svc = fn (Closure $fn) => En::as($s['tenant'], $s['admin'], fn () => $fn(app(EnrollmentService::class)));

    $s['enrollments'] = [
        $svc(fn ($e) => $e->create($s['admin'], En::body($s, 0))),
        $svc(fn ($e) => $e->create($s['admin'], En::body($s, 1))),
        $svc(fn ($e) => $e->create($s['admin'], En::body($s, 2, ['group' => $s['groupB']]))),
    ];

    $s['docente'] = Alum::userWithScopes($s['tenant'], $s['admin'], 'docente', ['alumno.leer' => 'grupo', 'matricula.leer' => 'grupo']);
    $s['tutor'] = Alum::userWithScopes($s['tenant'], $s['admin'], 'tutor_grupo', ['alumno.leer' => 'grupo', 'matricula.leer' => 'grupo']);

    return $s;
}

/** Asigna al docente la asignatura del grupo `$group`. */
function scopeAssign(array $s, User $teacher, string $group): string
{
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$group}/subjects", ['subjects' => [$s['api']['subject']]])->assertOk();

    return Tt::postAssignment($s['tenant'], $s['admin'], $teacher, $group, $s['api']['subject'])->assertCreated()->json('public_id');
}

/** @return list<string> `public_id` de los alumnos que el sujeto lista */
function scopeStudents(array $s, User $user): array
{
    return En::as($s['tenant'], $user, fn () => collect(app(StudentService::class)->page(app(PermissionResolver::class)->decide($user, 'alumno.leer'), $user, null, false, 'created_at', 1, 100)->items())->pluck('public_id')->all());
}

/** @return list<string> `public_id` de las matrículas que el sujeto lista */
function scopeEnrollments(array $s, User $user, array $filters = []): array
{
    return En::as($s['tenant'], $user, fn () => collect(app(EnrollmentService::class)->page(app(PermissionResolver::class)->decide($user, 'matricula.leer'), $user, ['academic_year' => $s['year']->public_id] + $filters, 'enrolled_on', 1, 100)->items())->pluck('public_id')->all());
}

function scopeDetailIsNotFound(array $s, User $user, string $publicId): bool
{
    try {
        En::as($s['tenant'], $user, fn () => app(EnrollmentService::class)->findScoped($publicId, app(PermissionResolver::class)->decide($user, 'matricula.leer'), $user));
    } catch (ModelNotFoundException) {
        return true;
    }

    return false;
}

function scopeStudentDetailIsNotFound(array $s, User $user, string $publicId): bool
{
    try {
        En::as($s['tenant'], $user, fn () => app(StudentService::class)->findScoped($publicId, app(PermissionResolver::class)->decide($user, 'alumno.leer'), $user));
    } catch (ModelNotFoundException) {
        return true;
    }

    return false;
}

test('CA-ALUM-010 CA-ALUM-013 RN-ALUM-61 RN-ALUM-63: un docente con asignación en G1 lista solo los alumnos matriculados en G1 y el de G2 es 404 idéntico a un inexistente; al retirarle la asignación, en la petición siguiente ya no ve a nadie', function (): void {
    $s = scopeScenario('scope-010');
    $a = $s['students'][0]['public_id'];
    $c = $s['students'][2]['public_id'];
    $assignment = scopeAssign($s, $s['docente'], $s['api']['group']);

    expect(scopeStudents($s, $s['docente']))->toEqualCanonicalizing([$a, $s['students'][1]['public_id']])
        ->and(scopeStudentDetailIsNotFound($s, $s['docente'], $a))->toBeFalse()
        ->and(scopeStudentDetailIsNotFound($s, $s['docente'], $c))->toBeTrue()
        ->and(scopeStudentDetailIsNotFound($s, $s['docente'], '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeTrue()
        // Quien tiene `todos` ve a los tres.
        ->and(scopeStudents($s, $s['admin']))->toHaveCount(3);

    // Una matrícula en baja no cuenta (`activa` viva, RN-ALUM-61).
    En::as($s['tenant'], $s['admin'], fn () => app(EnrollmentService::class)->withdraw($s['admin'], $s['enrollments'][1]->public_id, ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'traslado']));
    expect(scopeStudents($s, $s['docente']))->toBe([$a]);

    // CA-ALUM-013: retirar la asignación.
    Tt::send($s['tenant'], $s['admin'], 'DELETE', "/teaching-assignments/{$assignment}")->assertNoContent();
    expect(scopeStudents($s, $s['docente']))->toBe([])
        ->and(scopeStudentDetailIsNotFound($s, $s['docente'], $a))->toBeTrue();
});

test('CA-ALUM-011 RN-ALUM-61 RN-ACAD-98: un tutor de grupo con la tutoría de G1 y sin asignaturas ve a los de G1 y no a los de G2', function (): void {
    $s = scopeScenario('scope-011');
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$s['api']['group']}/tutors", ['tutors' => [$s['tutor']->public_id]])->assertOk();

    expect(scopeStudents($s, $s['tutor']))->toEqualCanonicalizing([$s['students'][0]['public_id'], $s['students'][1]['public_id']])
        ->and(scopeStudentDetailIsNotFound($s, $s['tutor'], $s['students'][2]['public_id']))->toBeTrue()
        ->and(scopeEnrollments($s, $s['tutor']))->toEqualCanonicalizing([$s['enrollments'][0]->public_id, $s['enrollments'][1]->public_id]);
});

test('CA-ALUM-015 RN-ALUM-61 RN-ALUM-65: un docente con grupo y sin ninguna asignación ve el listado vacío y el detalle 404', function (): void {
    $s = scopeScenario('scope-015');

    expect(scopeStudents($s, $s['docente']))->toBe([])
        ->and(scopeStudentDetailIsNotFound($s, $s['docente'], $s['students'][0]['public_id']))->toBeTrue()
        ->and(scopeEnrollments($s, $s['docente']))->toBe([])
        ->and(scopeDetailIsNotFound($s, $s['docente'], $s['enrollments'][0]->public_id))->toBeTrue();
});

test('CA-ALUM-016 RN-ALUM-60 RN-ALUM-64: grupo sobre matrícula; un docente de G1 que filtra por G2 recibe lista vacía y el detalle de una matrícula de G2 es 404 idéntico al de una inexistente', function (): void {
    $s = scopeScenario('scope-016');
    scopeAssign($s, $s['docente'], $s['api']['group']);

    expect(scopeEnrollments($s, $s['docente']))->toEqualCanonicalizing([$s['enrollments'][0]->public_id, $s['enrollments'][1]->public_id])
        ->and(scopeEnrollments($s, $s['docente'], ['group' => $s['api']['group']]))->toHaveCount(2)
        ->and(scopeEnrollments($s, $s['docente'], ['group' => $s['groupB']]))->toBe([])
        ->and(scopeDetailIsNotFound($s, $s['docente'], $s['enrollments'][2]->public_id))->toBeTrue()
        ->and(scopeDetailIsNotFound($s, $s['docente'], '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeTrue()
        ->and(scopeDetailIsNotFound($s, $s['docente'], $s['enrollments'][0]->public_id))->toBeFalse();
});

test('RN-ALUM-60 permisos.md §3.1: la asignación de un curso académico no concede el grupo de otro; sin curso activo, grupo sobre alumno deniega', function (): void {
    $s = scopeScenario('scope-060');
    $plan = En::planningYear($s);
    scopeAssign($s, $s['docente'], $s['api']['group']);

    // Matrícula en la planificación (con su grupo): el docente no tiene asignación allí.
    Tt::send($s['tenant'], $s['admin'], 'PUT', "/groups/{$plan['api']['group']}/subjects", ['subjects' => [$plan['api']['subject']]])->assertOk();
    $planned = En::as($s['tenant'], $s['admin'], fn () => app(EnrollmentService::class)->create($s['admin'], [
        'student' => $s['students'][0]['public_id'], 'academic_year' => $plan['year']->public_id,
        'course' => $plan['api']['course'], 'group' => $plan['api']['group'], 'enrolled_on' => '2027-09-10',
    ]));
    $listPlan = En::as($s['tenant'], $s['docente'], fn () => app(EnrollmentService::class)->page(app(PermissionResolver::class)->decide($s['docente'], 'matricula.leer'), $s['docente'], ['academic_year' => $plan['year']->public_id], 'name', 1, 25)->total());

    expect($listPlan)->toBe(0)
        ->and(scopeDetailIsNotFound($s, $s['docente'], $planned->public_id))->toBeTrue();
});

test('RN-ALUM-62 RN-ALUM-63 RN-ALUM-64: unidad_familiar sobre matrícula; el tutor solo ve las del alumno con vínculo activo y, tras revocarlo, en la petición siguiente ninguna', function (): void {
    $s = scopeScenario('scope-062');
    $tenant = $s['tenant'];
    $admin = $s['admin'];
    $unit = Fam::unitId($tenant, $admin, 'U1');
    Fam::addStudent($tenant, $admin, $unit, Alum::person($tenant, $s['students'][0]['person']['public_id']))->assertCreated();
    Fam::addStudent($tenant, $admin, $unit, Alum::person($tenant, $s['students'][1]['person']['public_id']))->assertCreated();
    $tutor = Alum::userWithScopes($tenant, $admin, 'tutor', ['matricula.leer' => 'unidad_familiar', 'familia.leer' => 'unidad_familiar', 'vinculo_tutor.leer' => 'unidad_familiar']);
    $guardian = Fam::guardian($tenant, $admin, $tutor, $unit)->assertCreated()->json('public_id');
    $link = Fam::link($tenant, $admin, $guardian, Alum::person($tenant, $s['students'][0]['person']['public_id']))->assertCreated()->json('public_id');
    [$e1, $e2] = [$s['enrollments'][0], $s['enrollments'][1]];

    expect(scopeEnrollments($s, $tutor))->toBe([$e1->public_id])
        ->and(scopeDetailIsNotFound($s, $tutor, $e1->public_id))->toBeFalse()
        ->and(scopeDetailIsNotFound($s, $tutor, $e2->public_id))->toBeTrue()
        ->and(scopeDetailIsNotFound($s, $tutor, '01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeTrue();

    Fam::call($tenant, $admin, 'post', "/guardian-links/{$link}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    expect(scopeEnrollments($s, $tutor))->toBe([])
        ->and(scopeDetailIsNotFound($s, $tutor, $e1->public_id))->toBeTrue();

    // Sujeto sin faceta de tutor: nada.
    $none = Alum::userWithScopes($tenant, $admin, 'sin_tutor', ['matricula.leer' => 'unidad_familiar']);
    expect(scopeEnrollments($s, $none))->toBe([]);
});
