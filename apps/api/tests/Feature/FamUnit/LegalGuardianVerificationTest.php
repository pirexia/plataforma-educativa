<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-002, REQ-FAM-UNIT-004 (1.14-b), docs/modulos/REQ-FAM-UNIT/funcional.md §5.3 RN-FAM-UNIT-74 (OPEN-FAM-UNIT-24),
// CA-FAM-UNIT-102 (la parte de la verificación; la del estado de imagen vive en ImageConsentLegalVerificationTest),
// INV-003, INV-015: la representación legal solo se verifica en la transición `false → true`.

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array{0: object, 1: User, 2: string, 3: string, 4: User} centro, administrador, unidad, vínculo y un segundo usuario que edita vínculos */
function legalVerificationScenario(string $slug): array
{
    [$tenant, $admin] = Fam::setup($slug);
    $unit = Fam::unitId($tenant, $admin);
    [, $guardian] = Fam::familyUser($tenant, $admin, 'tutor', $unit);
    $student = Fam::person($tenant);

    CarbonImmutable::setTestNow('2026-10-27 09:00:00');
    $link = Fam::link($tenant, $admin, $guardian, $student, ['is_legal_guardian' => true, 'access_level' => 'lectura'])->assertCreated()->json('public_id');
    $editor = Fam::userWith($tenant, $admin, 'editor', ['vinculo_tutor.actualizar' => 'todos', 'vinculo_tutor.leer' => 'todos']);

    return [$tenant, $admin, $unit, $link, $editor];
}

test('RN-FAM-UNIT-74 CA-FAM-UNIT-102: reenviar is_legal_guardian = true sobre un vínculo que ya lo tiene no cambia la verificación (fecha ni autor) ni deja cambios de ellos en audit_logs', function (): void {
    [$tenant, $admin, , $link, $editor] = legalVerificationScenario('lv-74a');
    $before = Fam::row($tenant, 'guardian_student_links', $link);

    expect($before->legal_guardian_verified_at)->not->toBeNull()
        ->and((int) $before->legal_guardian_verified_by)->toBe($admin->id);

    CarbonImmutable::setTestNow('2026-10-27 11:30:00');
    Fam::call($tenant, $editor, 'patch', "/guardian-links/{$link}", ['is_legal_guardian' => true, 'access_level' => 'lectura_escritura'])
        ->assertOk()
        ->assertJsonPath('access_level', 'lectura_escritura');

    $after = Fam::row($tenant, 'guardian_student_links', $link);

    expect($after->legal_guardian_verified_at)->toBe($before->legal_guardian_verified_at)
        ->and((int) $after->legal_guardian_verified_by)->toBe($admin->id)
        ->and($after->is_legal_guardian)->toBeTrue();

    $updated = Fam::audit($tenant, 'guardian_student_link', 'updated');

    expect($updated)->toHaveCount(1)
        ->and(array_keys($updated[0]->changes))->toContain('access_level')
        ->and(array_keys($updated[0]->changes))->not->toContain('is_legal_guardian', 'legal_guardian_verified_at', 'legal_guardian_verified_by');
});

test('RN-FAM-UNIT-74 CA-FAM-UNIT-102: desmarcar y volver a marcar la representación legal sí reverifica, con la fecha y el autor de la segunda petición', function (): void {
    [$tenant, , , $link, $editor] = legalVerificationScenario('lv-74b');

    CarbonImmutable::setTestNow('2026-10-27 12:00:00');
    Fam::call($tenant, $editor, 'patch', "/guardian-links/{$link}", ['is_legal_guardian' => false])->assertOk();

    $unmarked = Fam::row($tenant, 'guardian_student_links', $link);
    expect($unmarked->is_legal_guardian)->toBeFalse()
        ->and($unmarked->legal_guardian_verified_at)->toBeNull()
        ->and($unmarked->legal_guardian_verified_by)->toBeNull();

    CarbonImmutable::setTestNow('2026-10-27 13:00:00');
    Fam::call($tenant, $editor, 'patch', "/guardian-links/{$link}", ['is_legal_guardian' => true])->assertOk();

    $remarked = Fam::row($tenant, 'guardian_student_links', $link);
    expect($remarked->is_legal_guardian)->toBeTrue()
        ->and(CarbonImmutable::parse($remarked->legal_guardian_verified_at)->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-27 13:00:00')->toIso8601String())
        ->and((int) $remarked->legal_guardian_verified_by)->toBe($editor->id);
});

test('RN-FAM-UNIT-74: crear el vínculo con is_legal_guardian = true lo verifica en el alta; crearlo con false no lo verifica', function (): void {
    [$tenant, $admin, $unit] = legalVerificationScenario('lv-74c');
    [, $other] = Fam::familyUser($tenant, $admin, 'otro', $unit);
    $student = Fam::person($tenant, 'Otro', 'Alumno');

    CarbonImmutable::setTestNow('2026-10-27 14:00:00');
    $plain = Fam::link($tenant, $admin, $other, $student, ['is_legal_guardian' => false, 'relationship' => 'abuelo'])->assertCreated()->json('public_id');
    $legal = Fam::link($tenant, $admin, Fam::guardian($tenant, $admin, Fam::userWith($tenant, $admin, 'tercero', ['familia.leer' => 'todos']), $unit)->assertCreated()->json('public_id'), $student, ['is_legal_guardian' => true])->assertCreated()->json('public_id');

    expect(Fam::row($tenant, 'guardian_student_links', $plain)->legal_guardian_verified_at)->toBeNull()
        ->and(Fam::row($tenant, 'guardian_student_links', $legal)->legal_guardian_verified_at)->not->toBeNull();
});
