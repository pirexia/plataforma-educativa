<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// REQ-AUTH-004 (1.4b), ADR-043 §3.6, datos.md §F.4. El re-tecleado de
// `user_identities` por proveedor concreto: las garantías del motor, no
// del servicio.

// CA-AUTH-295
test('CA-AUTH-295: un usuario con vínculo vivo del proveedor A puede vincular también el proveedor B del mismo centro', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-295');
    $user = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['email' => 'u295@example.com', 'status' => UserStatus::Activo]);
    });

    $providerA = createActiveOidcProvider($tenant->slug, $admin, ['display_name' => 'A', 'discovery_url' => OIDC_DISCOVERY_URL.'?issuer_suffix=-295a']);
    $providerB = createActiveOidcProvider($tenant->slug, $admin, ['display_name' => 'B', 'discovery_url' => OIDC_DISCOVERY_URL.'?issuer_suffix=-295b']);

    loginWithOidcFor($tenant->slug, $providerA['public_id'], [
        'sub' => 'sub-295a', 'email' => 'u295@example.com', 'email_verified' => '1',
        'iss_override' => 'http://localhost:8000/_sso-simulator-295a',
    ]);
    loginWithOidcFor($tenant->slug, $providerB['public_id'], [
        'sub' => 'sub-295b', 'email' => 'u295@example.com', 'email_verified' => '1',
        'iss_override' => 'http://localhost:8000/_sso-simulator-295b',
    ]);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserIdentity::query()->where('user_id', $user->id)->count())->toBe(2);
    });
});

// CA-AUTH-296
test('CA-AUTH-296: un (proveedor, subject) ya vinculado a un usuario se rechaza al intentar vincularlo a otro, por el índice único', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-296');
    [$userA, $userB] = app(TenantContext::class)->runFor($tenant->id, function () {
        return [
            User::factory()->for(Person::factory()->create())->create(['email' => 'a-296@example.com', 'status' => UserStatus::Activo]),
            User::factory()->for(Person::factory()->create())->create(['email' => 'b-296@example.com', 'status' => UserStatus::Activo]),
        ];
    });

    $provider = createActiveOidcProvider($tenant->slug, $admin);

    // userA se empareja con sub-296.
    loginWithOidcFor($tenant->slug, $provider['public_id'], [
        'sub' => 'sub-296', 'email' => 'a-296@example.com', 'email_verified' => '1',
    ]);

    // Intento de userB de vincular la MISMA identidad manualmente
    // (intent=link) mientras tiene sesión propia.
    $sessionB = loginFor($tenant->slug, 'b-296@example.com', 'password');
    $cookieB = sessionCookieValue($sessionB);

    [$authorizationUrl] = beginOidcFlow($tenant->slug, $provider['public_id'], 'link', $cookieB);
    $callback = completeOidcFlow($authorizationUrl, $cookieB, [
        'sub' => 'sub-296', 'email' => 'a-296@example.com', 'email_verified' => '1',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('proveedor_ya_vinculado');

    app(TenantContext::class)->runFor($tenant->id, function () use ($userA, $userB): void {
        expect(UserIdentity::query()->where('user_id', $userA->id)->count())->toBe(1)
            ->and(UserIdentity::query()->where('user_id', $userB->id)->count())->toBe(0);
    });
});

// CA-AUTH-297
test('CA-AUTH-297: las filas provider=google de 1.4 siguen respetando su unicidad tras las migraciones de este paso', function (): void {
    [$tenant] = provisionCoreTenant('oidc-297');
    $user = app(TenantContext::class)->runFor($tenant->id, function () {
        return User::factory()->for(Person::factory()->create())->create(['email' => 'u297@example.com', 'status' => UserStatus::Activo]);
    });

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'subject' => 'google-sub-297',
            'email_at_link' => 'u297@example.com',
            'email_verified_at_link' => true,
            'link_method' => 'fusion_automatica',
            'linked_at' => now(),
        ]);

        expect(UserIdentity::query()->where('provider', 'google')->first()->identity_provider_id)->toBeNull();

        // DB::transaction(): Postgres deja la sesión "en transacción
        // abortada" tras un fallo de restricción hasta el ROLLBACK — sin
        // envolver en una transacción propia, la siguiente consulta de
        // este mismo test (incluida la que hace TenantContext::runFor()
        // al salir) fallaría en cascada con el mismo error.
        expect(fn () => DB::transaction(fn () => UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'subject' => 'google-sub-297',
            'email_at_link' => 'otro@example.com',
            'email_verified_at_link' => true,
            'link_method' => 'fusion_automatica',
            'linked_at' => now(),
        ])))->toThrow(QueryException::class);
    });
});

// CA-AUTH-298
test('CA-AUTH-298: el motor impide un link_method=emparejamiento_sso sin identity_provider_id, y un provider=google con identity_provider_id', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-298');
    $user = app(TenantContext::class)->runFor($tenant->id, function () {
        return User::factory()->for(Person::factory()->create())->create(['email' => 'u298@example.com', 'status' => UserStatus::Activo]);
    });

    $provider = createActiveOidcProvider($tenant->slug, $admin);
    $providerRow = app(TenantContext::class)->runFor($tenant->id, fn () => IdentityProvider::query()->where('public_id', $provider['public_id'])->firstOrFail());

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $providerRow): void {
        expect(fn () => DB::transaction(fn () => UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'oidc',
            'identity_provider_id' => null,
            'subject' => 'sub-298-a',
            'email_at_link' => 'u298@example.com',
            'email_verified_at_link' => false,
            'link_method' => 'emparejamiento_sso',
            'linked_at' => now(),
        ])))->toThrow(QueryException::class);

        expect(fn () => DB::transaction(fn () => UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'identity_provider_id' => $providerRow->id,
            'subject' => 'sub-298-b',
            'email_at_link' => 'u298@example.com',
            'email_verified_at_link' => true,
            'link_method' => 'fusion_automatica',
            'linked_at' => now(),
        ])))->toThrow(QueryException::class);
    });
});
