<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Core\Domain\UserDirectory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD-002 `RN-ACAD-92` (ampliación aprobada de `1.12-a`): resolución de un
 * usuario activo por `public_id` y nombres por id interno desde `UserDirectory`.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function directoryUser(object $tenant, string $email, UserStatus $status): User
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($email, $status): User {
        $person = Person::create(['given_name' => 'Ana', 'family_name_1' => 'Ejemplo']);
        $user = new User(['person_id' => $person->id, 'email' => $email, 'password' => 'x', 'status' => $status]);
        $user->save();

        return $user;
    });
}

test('RN-ACAD-92: findActiveByPublicId devuelve id y nombre de un usuario activo, y null si inactivo, pendiente, borrado, inexistente o de otro centro', function (): void {
    [$tenantA] = provisionCoreTenant('dir-active-a');
    [$tenantB] = provisionCoreTenant('dir-active-b');

    $active = directoryUser($tenantA, 'activo@example.com', UserStatus::Activo);
    $inactive = directoryUser($tenantA, 'inactivo@example.com', UserStatus::Inactivo);
    $pending = directoryUser($tenantA, 'pendiente@example.com', UserStatus::Pendiente);
    $deleted = directoryUser($tenantA, 'borrado@example.com', UserStatus::Activo);
    app(TenantContext::class)->runFor($tenantA->id, fn () => $deleted->delete());
    $foreign = directoryUser($tenantB, 'ajeno@example.com', UserStatus::Activo);

    app(TenantContext::class)->runFor($tenantA->id, function () use ($active, $inactive, $pending, $deleted, $foreign): void {
        $directory = app(UserDirectory::class);

        $ref = $directory->findActiveByPublicId($active->public_id);
        expect($ref)->not->toBeNull()
            ->and($ref->id)->toBe($active->id)
            ->and($ref->publicId)->toBe($active->public_id)
            ->and($ref->displayName)->toBe('Ana Ejemplo');

        foreach ([$inactive, $pending, $deleted, $foreign] as $user) {
            expect($directory->findActiveByPublicId($user->public_id))->toBeNull();
        }

        expect($directory->findActiveByPublicId('01JZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();
    });
});

test('RN-ACAD-92: displayNamesByIds devuelve el nombre visible por id, también de usuarios dados de baja, y omite los inexistentes', function (): void {
    [$tenant] = provisionCoreTenant('dir-names');
    $a = directoryUser($tenant, 'a@example.com', UserStatus::Inactivo);

    app(TenantContext::class)->runFor($tenant->id, function () use ($a): void {
        $directory = app(UserDirectory::class);

        expect($directory->displayNamesByIds([]))->toBe([])
            ->and($directory->displayNamesByIds([$a->id, 999999999]))->toBe([$a->id => 'Ana Ejemplo'])
            ->and($directory->refsByIds([$a->id, 999999999]))->toHaveCount(1)
            ->and($directory->refsByIds([$a->id])[$a->id]->publicId)->toBe($a->public_id);
    });
});

test('RN-ACAD-92 REQ-PRIV-006 ADR-004 (#446 B-3): un usuario borrado conserva la referencia pero nunca su nombre, en displayNamesByIds y en refsByIds', function (): void {
    [$tenant] = provisionCoreTenant('dir-removed');
    $alive = directoryUser($tenant, 'viva@example.com', UserStatus::Activo);
    $gone = directoryUser($tenant, 'baja@example.com', UserStatus::Activo);
    app(TenantContext::class)->runFor($tenant->id, fn () => $gone->delete());

    app(TenantContext::class)->runFor($tenant->id, function () use ($alive, $gone): void {
        $directory = app(UserDirectory::class);
        $refs = $directory->refsByIds([$alive->id, $gone->id]);

        expect($refs[$gone->id]->removed)->toBeTrue()
            ->and($refs[$gone->id]->displayName)->toBe('')
            ->and($refs[$gone->id]->publicId)->toBe($gone->public_id)
            ->and($refs[$alive->id]->removed)->toBeFalse()
            ->and($refs[$alive->id]->displayName)->toBe('Ana Ejemplo')
            ->and($directory->displayNamesByIds([$alive->id, $gone->id]))->toBe([$alive->id => 'Ana Ejemplo', $gone->id => '']);
    });
});
