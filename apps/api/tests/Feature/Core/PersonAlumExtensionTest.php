<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Modules\Core\Domain\PersonDataCorrection;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\Core\Domain\PersonNotCorrectableException;
use App\Modules\Core\Domain\PersonRegistrar;
use App\Modules\Core\Domain\PersonRegistration;
use App\Support\Api\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

// REQ-ALUM-001, OPEN-ALUM-19 = A, RN-ALUM-10, RN-CORE-90 a -93, INV-001, INV-003, INV-007: ampliación aditiva de
// `Core\Domain` en 1.15-a: PersonDirectory::birthDatesByIds() y PersonRegistrar::correctPerson().

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function alumExtIn(object $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->runFor($tenant->id, $callback);
}

test('REQ-ALUM-001 OPEN-ALUM-19: birthDatesByIds devuelve la fecha de las personas vivas del centro, null si no la tienen, y omite borradas, inexistentes y de otro centro', function (): void {
    [$tenantA] = provisionCoreTenant('alum-ext-a');
    [$tenantB] = provisionCoreTenant('alum-ext-b');
    $registrar = app(PersonRegistrar::class);
    $directory = app(PersonDirectory::class);

    $withDate = alumExtIn($tenantA, fn () => $registrar->registerOrReuse(new PersonRegistration('Ana', 'Perez')));
    alumExtIn($tenantA, fn () => $registrar->correctPerson($withDate->personId, new PersonDataCorrection(['birth_date' => '2015-03-09'])));
    $noDate = alumExtIn($tenantA, fn () => $registrar->registerOrReuse(new PersonRegistration('Luis', 'Perez')));
    $deleted = alumExtIn($tenantA, fn () => $registrar->registerOrReuse(new PersonRegistration('Borrada', 'Perez')));
    alumExtIn($tenantA, fn () => Person::query()->whereKey($deleted->personId)->firstOrFail()->delete());
    $foreign = alumExtIn($tenantB, fn () => $registrar->registerOrReuse(new PersonRegistration('Otra', 'Perez')));

    $result = alumExtIn($tenantA, fn () => $directory->birthDatesByIds([$withDate->personId, $noDate->personId, $deleted->personId, $foreign->personId, 987654321]));

    expect(array_keys($result))->toEqualCanonicalizing([$withDate->personId, $noDate->personId])
        ->and($result[$withDate->personId]->birthDate)->toBe('2015-03-09')
        ->and($result[$noDate->personId]->birthDate)->toBeNull()
        ->and(alumExtIn($tenantA, fn () => $directory->birthDatesByIds([])))->toBe([]);
});

test('RN-ALUM-10 OPEN-ALUM-19: correctPerson corrige nombre, documento, fecha e idioma de una persona CON usuario sin tocar el usuario, y lo audita Core', function (): void {
    [$tenant] = provisionCoreTenant('alum-ext-fix');
    $registrar = app(PersonRegistrar::class);

    $person = alumExtIn($tenant, fn () => Person::query()->whereHas('user')->firstOrFail());
    $userBefore = alumExtIn($tenant, fn () => (array) DB::table('users')->where('person_id', $person->id)->first());

    alumExtIn($tenant, fn () => $registrar->correctPerson((int) $person->id, new PersonDataCorrection([
        'given_name' => ' Marta ', 'document_type' => 'dni', 'document_number' => ' 00000001-r ', 'birth_date' => '2010-01-31', 'locale' => 'es-ES',
    ])));

    $after = alumExtIn($tenant, fn () => (array) DB::table('people')->where('id', $person->id)->first());
    $userAfter = alumExtIn($tenant, fn () => (array) DB::table('users')->where('person_id', $person->id)->first());

    expect($after['given_name'])->toBe('Marta')
        ->and($after['document_number'])->toBe('00000001R')
        ->and($after['birth_date'])->toStartWith('2010-01-31')
        ->and($userAfter['email'])->toBe($userBefore['email'])
        ->and($userAfter['password'])->toBe($userBefore['password'])
        ->and(alumExtIn($tenant, fn () => AuditLog::query()->where('auditable_type', 'person')->where('auditable_id', $person->id)->where('event', 'updated')->count()))->toBe(1);
});

test('RN-ALUM-10 RN-CORE-90 RN-CORE-93: correctPerson rechaza con 422 un documento inválido, uno duplicado y un idioma no activo, sin escribir nada', function (): void {
    [$tenant] = provisionCoreTenant('alum-ext-val');
    $registrar = app(PersonRegistrar::class);

    $a = alumExtIn($tenant, fn () => $registrar->registerOrReuse(new PersonRegistration('Ana', 'Perez', null, 'dni', '12345678Z')));
    $b = alumExtIn($tenant, fn () => $registrar->registerOrReuse(new PersonRegistration('Luis', 'Perez')));

    $cases = [
        'dígito de control inválido' => ['document_type' => 'dni', 'document_number' => '12345678A'],
        'duplicado en el centro' => ['document_type' => 'dni', 'document_number' => '12345678Z'],
        'par incompleto' => ['document_type' => 'dni'],
        'idioma no activo' => ['locale' => 'xx'],
    ];

    foreach ($cases as $label => $changes) {
        $thrown = null;

        try {
            alumExtIn($tenant, fn () => $registrar->correctPerson($b->personId, new PersonDataCorrection($changes)));
        } catch (ApiException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(ApiException::class, $label)
            ->and($thrown->status)->toBe(422, $label);
    }

    $row = alumExtIn($tenant, fn () => (array) DB::table('people')->where('id', $b->personId)->first());

    expect($row['document_type'])->toBeNull()->and($row['document_number'])->toBeNull()->and($a->personId)->not->toBe($b->personId);
});

test('RN-ALUM-10 INV-001: correctPerson lanza PersonNotCorrectableException sobre una persona inexistente, borrada o de otro centro, sin modificar nada', function (): void {
    [$tenantA] = provisionCoreTenant('alum-ext-nf-a');
    [$tenantB] = provisionCoreTenant('alum-ext-nf-b');
    $registrar = app(PersonRegistrar::class);
    $correction = new PersonDataCorrection(['given_name' => 'Cambiado']);

    $foreign = alumExtIn($tenantB, fn () => $registrar->registerOrReuse(new PersonRegistration('Ana', 'Perez')));
    $deleted = alumExtIn($tenantA, fn () => $registrar->registerOrReuse(new PersonRegistration('Borrada', 'Perez')));
    alumExtIn($tenantA, fn () => Person::query()->whereKey($deleted->personId)->firstOrFail()->delete());

    foreach ([$foreign->personId, $deleted->personId, 987654321] as $personId) {
        expect(fn () => alumExtIn($tenantA, fn () => $registrar->correctPerson($personId, $correction)))->toThrow(PersonNotCorrectableException::class);
    }

    expect(alumExtIn($tenantB, fn () => Person::query()->findOrFail($foreign->personId)->given_name))->toBe('Ana');
});

test('RN-ALUM-10: PersonDataCorrection exige al menos un campo, solo los permitidos, no vacía nombre ni primer apellido y valida la fecha', function (): void {
    expect(fn () => new PersonDataCorrection([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PersonDataCorrection(['contact_phone' => '600000000']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PersonDataCorrection(['given_name' => ' ']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PersonDataCorrection(['family_name_1' => null]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PersonDataCorrection(['birth_date' => '2015-02-30']))->toThrow(InvalidArgumentException::class)
        ->and((new PersonDataCorrection(['birth_date' => null, 'locale' => null]))->changes)->toBe(['birth_date' => null, 'locale' => null]);
});
