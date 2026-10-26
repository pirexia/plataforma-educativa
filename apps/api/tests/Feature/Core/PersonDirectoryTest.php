<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Domain\PersonDirectory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/funcional.md §1.5, §7.4 y `OPEN-FAM-UNIT-03` (ampliación aprobada de `Core\Domain`, 1.14-a): lectura de
 * personas y resolución usuario ⇄ persona desde `PersonDirectory`, sin que el módulo consumidor lea `people` ni `users`
 * (`INV-007`). `INV-001`: lo de otro centro no existe. `CA-FAM-UNIT-043`, `INV-015`.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function directoryPerson(object $tenant, string $given, ?string $email = null, UserStatus $status = UserStatus::Activo): array
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($given, $email, $status): array {
        $person = Person::create(['given_name' => $given, 'family_name_1' => 'Ejemplo']);
        $user = $email === null ? null : (function () use ($person, $email, $status): User {
            $user = new User(['person_id' => $person->id, 'email' => $email, 'password' => 'x', 'status' => $status]);
            $user->save();

            return $user;
        })();

        return [$person, $user];
    });
}

test('OPEN-FAM-UNIT-03: findByPublicId devuelve la persona viva del centro (id, public_id y nombre sin correo) y null si no existe, está borrada o es de otro centro', function (): void {
    [$tenantA] = provisionCoreTenant('pd-a');
    [$tenantB] = provisionCoreTenant('pd-b');

    [$ana] = directoryPerson($tenantA, 'Ana');
    [$gone] = directoryPerson($tenantA, 'Borrada');
    app(TenantContext::class)->runFor($tenantA->id, fn () => $gone->delete());
    [$foreign] = directoryPerson($tenantB, 'Ajena');

    app(TenantContext::class)->runFor($tenantA->id, function () use ($ana, $gone, $foreign): void {
        $directory = app(PersonDirectory::class);

        $ref = $directory->findByPublicId($ana->public_id);

        expect($ref)->not->toBeNull()
            ->and($ref->id)->toBe($ana->id)
            ->and($ref->publicId)->toBe($ana->public_id)
            ->and($ref->displayName)->toBe('Ana Ejemplo')
            ->and($ref->removed)->toBeFalse()
            ->and($directory->findByPublicId($gone->public_id))->toBeNull()
            ->and($directory->findByPublicId($foreign->public_id))->toBeNull()
            ->and($directory->findByPublicId('01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();
    });
});

test('OPEN-FAM-UNIT-03 REQ-PRIV-006 (#446 B-3): refsByIds devuelve vivas y borradas (estas con removed y nombre vacío), omite inexistentes y ajenas, y no consulta con lista vacía', function (): void {
    [$tenantA] = provisionCoreTenant('pd-refs-a');
    [$tenantB] = provisionCoreTenant('pd-refs-b');

    [$alive] = directoryPerson($tenantA, 'Viva');
    [$gone] = directoryPerson($tenantA, 'Baja');
    app(TenantContext::class)->runFor($tenantA->id, fn () => $gone->delete());
    [$foreign] = directoryPerson($tenantB, 'Ajena');

    app(TenantContext::class)->runFor($tenantA->id, function () use ($alive, $gone, $foreign): void {
        $directory = app(PersonDirectory::class);

        $refs = $directory->refsByIds([$alive->id, $gone->id, $foreign->id, 999999999]);

        expect(array_keys($refs))->toEqualCanonicalizing([$alive->id, $gone->id])
            ->and($refs[$alive->id]->displayName)->toBe('Viva Ejemplo')
            ->and($refs[$alive->id]->removed)->toBeFalse()
            ->and($refs[$gone->id]->removed)->toBeTrue()
            ->and($refs[$gone->id]->displayName)->toBe('')
            ->and($refs[$gone->id]->publicId)->toBe($gone->public_id)
            ->and($directory->refsByIds([]))->toBe([]);
    });
});

test('OPEN-FAM-UNIT-03: personIdForUser resuelve la persona de un usuario vivo del centro y null si no existe, está borrado o es de otro centro', function (): void {
    [$tenantA] = provisionCoreTenant('pd-user-a');
    [$tenantB] = provisionCoreTenant('pd-user-b');

    [$person, $user] = directoryPerson($tenantA, 'Con', 'con@example.com');
    [, $gone] = directoryPerson($tenantA, 'Sin', 'sin@example.com');
    app(TenantContext::class)->runFor($tenantA->id, fn () => $gone->delete());
    [, $foreign] = directoryPerson($tenantB, 'Aj', 'aj@example.com');

    app(TenantContext::class)->runFor($tenantA->id, function () use ($person, $user, $gone, $foreign): void {
        $directory = app(PersonDirectory::class);

        expect($directory->personIdForUser($user->id))->toBe($person->id)
            ->and($directory->personIdForUser($gone->id))->toBeNull()
            ->and($directory->personIdForUser($foreign->id))->toBeNull()
            ->and($directory->personIdForUser(999999999))->toBeNull();
    });
});

test('OPEN-FAM-UNIT-03: findUserByPublicId devuelve el usuario vivo de cualquier estado con su persona, sin correo, y null si está borrado, es de otro centro o no existe', function (): void {
    [$tenantA] = provisionCoreTenant('pd-fu-a');
    [$tenantB] = provisionCoreTenant('pd-fu-b');

    [$person, $pending] = directoryPerson($tenantA, 'Pendiente', 'p@example.com', UserStatus::Pendiente);
    [, $inactive] = directoryPerson($tenantA, 'Inactivo', 'i@example.com', UserStatus::Inactivo);
    [, $gone] = directoryPerson($tenantA, 'Gone', 'g@example.com');
    app(TenantContext::class)->runFor($tenantA->id, fn () => $gone->delete());
    [, $foreign] = directoryPerson($tenantB, 'Aj', 'x@example.com');

    app(TenantContext::class)->runFor($tenantA->id, function () use ($person, $pending, $inactive, $gone, $foreign): void {
        $directory = app(PersonDirectory::class);

        $ref = $directory->findUserByPublicId($pending->public_id);

        expect($ref)->not->toBeNull()
            ->and($ref->userId)->toBe($pending->id)
            ->and($ref->personId)->toBe($person->id)
            ->and($ref->personPublicId)->toBe($person->public_id)
            ->and($ref->status)->toBe('pendiente')
            ->and($ref->displayName)->toBe('Pendiente Ejemplo')
            ->and($directory->findUserByPublicId($inactive->public_id)->status)->toBe('inactivo')
            ->and($directory->findUserByPublicId($gone->public_id))->toBeNull()
            ->and($directory->findUserByPublicId($foreign->public_id))->toBeNull()
            ->and($directory->findUserByPublicId('01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();
    });
});

test('OPEN-FAM-UNIT-03: usersByPersonIds devuelve el usuario de cada persona (el borrado con removed y nombre vacío), omite a quien no tiene usuario o es de otro centro', function (): void {
    [$tenantA] = provisionCoreTenant('pd-up-a');
    [$tenantB] = provisionCoreTenant('pd-up-b');

    [$withUser, $user] = directoryPerson($tenantA, 'Con', 'c@example.com');
    [$noUser] = directoryPerson($tenantA, 'Sin');
    [$removedPerson, $removedUser] = directoryPerson($tenantA, 'Quitado', 'q@example.com');
    app(TenantContext::class)->runFor($tenantA->id, fn () => $removedUser->delete());
    [$foreign] = directoryPerson($tenantB, 'Aj', 'z@example.com');

    app(TenantContext::class)->runFor($tenantA->id, function () use ($withUser, $user, $noUser, $removedPerson, $removedUser, $foreign): void {
        $refs = app(PersonDirectory::class)->usersByPersonIds([$withUser->id, $noUser->id, $removedPerson->id, $foreign->id]);

        expect(array_keys($refs))->toEqualCanonicalizing([$withUser->id, $removedPerson->id])
            ->and($refs[$withUser->id]->userPublicId)->toBe($user->public_id)
            ->and($refs[$withUser->id]->displayName)->toBe('Con Ejemplo')
            ->and($refs[$withUser->id]->removed)->toBeFalse()
            ->and($refs[$removedPerson->id]->removed)->toBeTrue()
            ->and($refs[$removedPerson->id]->displayName)->toBe('')
            ->and($refs[$removedPerson->id]->userPublicId)->toBe($removedUser->public_id)
            ->and(app(PersonDirectory::class)->usersByPersonIds([]))->toBe([]);
    });
});
