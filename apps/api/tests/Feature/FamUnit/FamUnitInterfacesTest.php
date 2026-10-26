<?php

use App\Models\Person;
use App\Models\User;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\FamilyMembership;
use App\Modules\FamUnit\Domain\GuardianOfStudent;
use App\Modules\FamUnit\Domain\GuardianRecipients;
use App\Modules\FamUnit\Domain\StudentUnit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-002 (1.14-a), docs/modulos/REQ-FAM-UNIT/funcional.md §7.1, §7.2 y §9: interfaces públicas
// `FamilyMembership` y `GuardianRecipients`. CA-FAM-UNIT-033, -043 (la parte de interfaces), RN-FAM-UNIT-34, INV-007,
// INV-015.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array{tenant: object, admin: User, unit: string, a: array{0: User, 1: string}, b: array{0: User, 1: string}, s1: Person, s2: Person} */
function membershipFamily(string $slug): array
{
    [$tenant, $admin] = Fam::setup($slug);
    $unit = Fam::unitId($tenant, $admin);
    $a = Fam::familyUser($tenant, $admin, 'tuta', $unit);
    $b = Fam::familyUser($tenant, $admin, 'tutb', $unit);
    $s1 = Fam::person($tenant, 'Uno', 'Alumno');
    $s2 = Fam::person($tenant, 'Dos', 'Alumno');
    Fam::addStudent($tenant, $admin, $unit, $s1)->assertCreated();

    return compact('tenant', 'admin', 'unit', 'a', 'b', 's1', 's2');
}

test('funcional.md §7.1: studentPersonIdsReachedBy devuelve los alumnos con vínculo activo, ordenados y sin repetidos; vacío si la persona no es tutora o no tiene vínculos', function (): void {
    $f = membershipFamily('im-reach');
    Fam::link($f['tenant'], $f['admin'], $f['a'][1], $f['s2'])->assertCreated();
    Fam::link($f['tenant'], $f['admin'], $f['a'][1], $f['s1'])->assertCreated();
    $stranger = Fam::userWith($f['tenant'], $f['admin'], 'ajena', ['usuario.leer' => 'todos']);

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f, $stranger): void {
        $membership = app(FamilyMembership::class);
        $expected = [$f['s1']->id, $f['s2']->id];
        sort($expected);

        expect($membership->studentPersonIdsReachedBy($f['a'][0]->person_id))->toBe($expected)
            ->and($membership->studentPersonIdsReachedBy($f['b'][0]->person_id))->toBe([])
            ->and($membership->studentPersonIdsReachedBy($stranger->person_id))->toBe([])
            ->and($membership->studentPersonIdsReachedBy(999999999))->toBe([]);
    });
});

test('funcional.md §7.1 OPEN-FAM-UNIT-07: pidiendo una categoría (salud) se excluyen los vínculos que la restringen; sin categoría se devuelven todos', function (): void {
    $f = membershipFamily('im-cat');
    Fam::link($f['tenant'], $f['admin'], $f['a'][1], $f['s1'], ['restricted_categories' => ['salud']])->assertCreated();
    Fam::link($f['tenant'], $f['admin'], $f['a'][1], $f['s2'])->assertCreated();

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $membership = app(FamilyMembership::class);
        $personId = $f['a'][0]->person_id;

        expect($membership->studentPersonIdsReachedBy($personId, 'salud'))->toBe([$f['s2']->id])
            ->and($membership->studentPersonIdsReachedBy($personId))->toHaveCount(2)
            ->and($membership->studentPersonIdsReachedBy($personId, 'otra'))->toHaveCount(2);

        // GuardianRecipients aplica la misma restricción.
        $recipients = app(GuardianRecipients::class);
        expect($recipients->recipientsFor([$f['s1']->id], 'salud'))->toBe([])
            ->and($recipients->recipientsFor([$f['s1']->id]))->toHaveCount(1)
            ->and($recipients->recipientsFor([$f['s1']->id, $f['s2']->id], 'salud'))->toHaveCount(1);
    });
});

test('funcional.md §7.1: activeGuardiansOf devuelve los tutores activos del alumno con relación, representación legal, nivel de acceso y contacto de emergencia, sin nombres', function (): void {
    $f = membershipFamily('im-guardians');
    Fam::link($f['tenant'], $f['admin'], $f['a'][1], $f['s1'], ['relationship' => 'madre', 'is_legal_guardian' => true, 'access_level' => 'lectura_escritura', 'is_emergency_contact' => true])->assertCreated();
    Fam::link($f['tenant'], $f['admin'], $f['b'][1], $f['s1'], ['relationship' => 'abuelo'])->assertCreated();

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $guardians = app(FamilyMembership::class)->activeGuardiansOf($f['s1']->id);

        expect($guardians)->toHaveCount(2)
            ->and($guardians[0])->toBeInstanceOf(GuardianOfStudent::class);

        $byPerson = [];
        foreach ($guardians as $guardian) {
            $byPerson[$guardian->guardianPersonId] = $guardian;
        }

        $madre = $byPerson[$f['a'][0]->person_id];
        $abuelo = $byPerson[$f['b'][0]->person_id];

        expect([$madre->relationship, $madre->isLegalGuardian, $madre->accessLevel, $madre->isEmergencyContact])->toBe(['madre', true, 'lectura_escritura', true])
            ->and([$abuelo->relationship, $abuelo->isLegalGuardian, $abuelo->accessLevel, $abuelo->isEmergencyContact])->toBe(['abuelo', false, 'lectura', false])
            ->and(array_keys(get_object_vars($madre)))->toBe(['guardianPersonId', 'relationship', 'isLegalGuardian', 'accessLevel', 'isEmergencyContact'])
            ->and(app(FamilyMembership::class)->activeGuardiansOf($f['s2']->id))->toBe([]);
    });
});

test('funcional.md §7.1 1.14b: unitOfStudent da la unidad del alumno y el id de persona de su tutor principal (null sin principal); null si el alumno no tiene unidad', function (): void {
    $f = membershipFamily('im-unit');

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $unit = app(FamilyMembership::class)->unitOfStudent($f['s1']->id);

        expect($unit)->toBeInstanceOf(StudentUnit::class)
            ->and($unit->familyUnitPublicId)->toBe($f['unit'])
            ->and($unit->principalGuardianPersonId)->toBeNull()
            ->and(app(FamilyMembership::class)->unitOfStudent($f['s2']->id))->toBeNull();
    });

    Fam::call($f['tenant'], $f['admin'], 'patch', "/family-units/{$f['unit']}", ['principal_guardian' => $f['a'][1]])->assertOk();

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        expect(app(FamilyMembership::class)->unitOfStudent($f['s1']->id)->principalGuardianPersonId)->toBe($f['a'][0]->person_id);
    });
});

test('RN-FAM-UNIT-34 CA-FAM-UNIT-032: ninguna interfaz memoiza entre llamadas — la revocación y su levantamiento se ven en la llamada siguiente dentro del mismo proceso', function (): void {
    $f = membershipFamily('im-nomemo');
    $link = Fam::link($f['tenant'], $f['admin'], $f['a'][1], $f['s1'])->assertCreated()->json('public_id');

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $membership = app(FamilyMembership::class);

        expect($membership->studentPersonIdsReachedBy($f['a'][0]->person_id))->toBe([$f['s1']->id])
            ->and($membership->isAccessRevoked($f['a'][0]->person_id, $f['s1']->id))->toBeFalse()
            ->and(app(GuardianRecipients::class)->recipientsFor([$f['s1']->id]))->toHaveCount(1);
    });

    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$link}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $membership = app(FamilyMembership::class);

        expect($membership->studentPersonIdsReachedBy($f['a'][0]->person_id))->toBe([])
            ->and($membership->isAccessRevoked($f['a'][0]->person_id, $f['s1']->id))->toBeTrue()
            ->and(app(GuardianRecipients::class)->recipientsFor([$f['s1']->id]))->toBe([]);
    });

    Fam::call($f['tenant'], $f['admin'], 'delete', "/guardian-links/{$link}/revocation")->assertOk();

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        expect(app(FamilyMembership::class)->studentPersonIdsReachedBy($f['a'][0]->person_id))->toBe([$f['s1']->id])
            ->and(app(FamilyMembership::class)->isAccessRevoked($f['a'][0]->person_id, $f['s1']->id))->toBeFalse();
    });
});

test('funcional.md §7.2 RN-FAM-UNIT-34: GuardianRecipients agrupa por usuario con los alumnos por los que es destinatario, omite usuarios dados de baja y no devuelve nada con una lista vacía', function (): void {
    $f = membershipFamily('im-recipients');
    Fam::link($f['tenant'], $f['admin'], $f['a'][1], $f['s1'])->assertCreated();
    Fam::link($f['tenant'], $f['admin'], $f['a'][1], $f['s2'])->assertCreated();
    Fam::link($f['tenant'], $f['admin'], $f['b'][1], $f['s2'])->assertCreated();

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $recipients = app(GuardianRecipients::class);
        $all = $recipients->recipientsFor([$f['s1']->id, $f['s2']->id, $f['s1']->id]);

        expect($all)->toHaveCount(2)
            ->and($all[0]->userId)->toBe(min($f['a'][0]->id, $f['b'][0]->id))
            ->and($recipients->recipientsFor([]))->toBe([]);

        $forA = array_values(array_filter($all, static fn ($r): bool => $r->userId === $f['a'][0]->id))[0];
        $forB = array_values(array_filter($all, static fn ($r): bool => $r->userId === $f['b'][0]->id))[0];

        expect($forA->studentPersonIds)->toEqualCanonicalizing([$f['s1']->id, $f['s2']->id])
            ->and($forB->studentPersonIds)->toBe([$f['s2']->id])
            ->and($forA->personId)->toBe($f['a'][0]->person_id)
            ->and($forA->userPublicId)->toBe($f['a'][0]->public_id);

        // Un usuario dado de baja deja de ser destinatario.
        $f['b'][0]->delete();
        expect(array_map(static fn ($r): int => $r->userId, $recipients->recipientsFor([$f['s2']->id])))->toBe([$f['a'][0]->id]);
    });
});

test('funcional.md §9 D-3: con el módulo descontratado los conjuntos salen vacíos y unitOfStudent es null, pero isAccessRevoked sigue diciendo la verdad (una revocación judicial no se deshace al descontratar)', function (): void {
    $f = membershipFamily('im-disabled');
    $link = Fam::link($f['tenant'], $f['admin'], $f['a'][1], $f['s1'])->assertCreated()->json('public_id');
    Fam::link($f['tenant'], $f['admin'], $f['b'][1], $f['s1'])->assertCreated();
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$link}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();

    Fam::disableModule($f['tenant']);

    app(TenantContext::class)->runFor($f['tenant']->id, function () use ($f): void {
        $membership = app(FamilyMembership::class);

        expect($membership->studentPersonIdsReachedBy($f['b'][0]->person_id))->toBe([])
            ->and($membership->activeGuardiansOf($f['s1']->id))->toBe([])
            ->and($membership->unitOfStudent($f['s1']->id))->toBeNull()
            ->and(app(GuardianRecipients::class)->recipientsFor([$f['s1']->id]))->toBe([])
            ->and($membership->isAccessRevoked($f['a'][0]->person_id, $f['s1']->id))->toBeTrue();
    });
});

test('CA-FAM-UNIT-043 INV-001 INV-007: las interfaces de otro centro no devuelven ni cuentan datos del primero, y el módulo consumidor solo ve ids de persona', function (): void {
    $a = membershipFamily('im-iso-a');
    $b = membershipFamily('im-iso-b');
    Fam::link($a['tenant'], $a['admin'], $a['a'][1], $a['s1'])->assertCreated();
    Fam::link($b['tenant'], $b['admin'], $b['a'][1], $b['s1'])->assertCreated();

    app(TenantContext::class)->runFor($a['tenant']->id, function () use ($a, $b): void {
        $membership = app(FamilyMembership::class);

        // Desde el centro A, los ids de personas del centro B no existen.
        expect($membership->studentPersonIdsReachedBy($b['a'][0]->person_id))->toBe([])
            ->and($membership->activeGuardiansOf($b['s1']->id))->toBe([])
            ->and($membership->isAccessRevoked($b['a'][0]->person_id, $b['s1']->id))->toBeFalse()
            ->and($membership->unitOfStudent($b['s1']->id))->toBeNull()
            ->and(app(GuardianRecipients::class)->recipientsFor([$b['s1']->id, $a['s1']->id]))->toHaveCount(1)
            ->and(app(PersonDirectory::class)->findByPublicId($b['s1']->public_id))->toBeNull();
    });
});
