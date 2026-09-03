<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// REQ-AUTH-004 (1.4c), funcional.md §G.6 ("Emparejamiento, vinculación y
// MFA"), §G.4.3, §G.4.4. RN-AUTH-108 a RN-AUTH-111, RN-AUTH-129,
// RN-AUTH-130.
//
// HALLAZGO REAL que bloquea LOS DIEZ tests de este fichero, documentado
// con detalle en tests/Feature/Auth/SamlCertificatesTest.php (cabecera,
// antes de CA-AUTH-327): `FakeSamlIdentityProviderController` nunca firma
// el nodo `<samlp:Response>`, y `wantMessagesSigned = true` rechaza
// cualquier aserción simulada ANTES de que `SamlAcsService::handle()`
// llegue a `handleLogin()`/`handleLink()` — es decir, antes de tocar el
// emparejamiento, el bloqueo, el estado de la cuenta o el MFA, que es
// justo lo que este fichero cubre. No hay forma de escribir estos diez
// tests de manera que no dependan de al menos una aserción SAML válida
// completa: se escriben tal y como pide la especificación, como tests de
// regresión que deberían pasar en cuanto se corrija el defecto de la
// infraestructura de test — no se corrigen aquí, mismo motivo que
// CA-AUTH-327/343/344/345/348.

function samlLoginSlug(string $base): string
{
    return $base.'-'.strtolower(Str::random(6));
}

// CA-AUTH-351
test('CA-AUTH-351: el emparejamiento crea una única fila en user_identities con provider=saml, link_method=emparejamiento_sso y email_verified_at_link=false, sin tocar el resto de la cuenta', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlLoginSlug('saml-351'), ['email' => 'u351@example.com']);

    $before = app(TenantContext::class)->runFor($tenant->id, fn () => [
        'password' => $user->fresh()->password,
        'status' => $user->fresh()->status,
        'email' => $user->fresh()->email,
        'person_id' => $user->fresh()->person_id,
        'locale' => $user->fresh()->locale,
    ]);

    loginWithSamlFor($tenant->slug, $providerId, [
        'sub' => 'sub-351', 'attribute_name' => 'mail', 'attribute_value' => 'u351@example.com',
    ]);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $before): void {
        $identities = UserIdentity::query()->where('user_id', $user->id)->get();
        expect($identities)->toHaveCount(1);

        $identity = $identities->first();
        expect($identity->provider)->toBe('saml')
            ->and($identity->link_method->value)->toBe('emparejamiento_sso')
            ->and($identity->identity_provider_id)->not->toBeNull()
            ->and($identity->email_verified_at_link)->toBeFalse();

        $fresh = $user->fresh();
        expect($fresh->password)->toBe($before['password'])
            ->and($fresh->status)->toBe($before['status'])
            ->and($fresh->email)->toBe($before['email'])
            ->and($fresh->person_id)->toBe($before['person_id'])
            ->and($fresh->locale)->toBe($before['locale']);
    });
});

// CA-AUTH-352 ("el test que más importa del paso", junto con CA-AUTH-337)
test('CA-AUTH-352: el emparejamiento no crea ninguna fila nueva en people ni en users', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlLoginSlug('saml-352'), ['email' => 'u352@example.com']);

    $countsBefore = app(TenantContext::class)->runFor($tenant->id, fn () => [
        'people' => Person::query()->count(),
        'users' => User::query()->count(),
    ]);

    loginWithSamlFor($tenant->slug, $providerId, [
        'sub' => 'sub-352', 'attribute_name' => 'mail', 'attribute_value' => 'u352@example.com',
    ]);

    app(TenantContext::class)->runFor($tenant->id, function () use ($countsBefore): void {
        expect(Person::query()->count())->toBe($countsBefore['people'])
            ->and(User::query()->count())->toBe($countsBefore['users']);
    });
});

// CA-AUTH-353
test('CA-AUTH-353: una cuenta emparejada conserva exactamente los roles que tenía, y sin roles sigue sin poder ver una sola pantalla', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlLoginSlug('saml-353'), ['email' => 'u353@example.com']);

    $cookie = loginWithSamlFor($tenant->slug, $providerId, [
        'sub' => 'sub-353', 'attribute_name' => 'mail', 'attribute_value' => 'u353@example.com',
    ]);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect($user->fresh()->roles()->count())->toBe(0);
    });

    // Sin roles, sigue sin poder ver una pantalla que exija permiso
    // (RPERM-011): /identity-providers exige proveedor_identidad.leer.
    withSessionCookie($cookie)
        ->getJson(coreApiUrl($tenant->slug, '/identity-providers'))
        ->assertForbidden();
});

// CA-AUTH-354
test('CA-AUTH-354: con factor TOTP confirmado, el login SAML no crea sesión y abre mfa_challenges; el emparejamiento se escribe solo al superar el desafío', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlLoginSlug('saml-354'), ['email' => 'u354@example.com']);
    $secret = createConfirmedTotpFactor($tenant, $user);

    [$authorizationUrl] = beginSamlFlow($tenant->slug, $providerId);
    $callback = completeSamlFlow($authorizationUrl, [
        'sub' => 'sub-354', 'attribute_name' => 'mail', 'attribute_value' => 'u354@example.com',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('segundo_factor');

    $challengeCookie = sessionCookieValue($callback);
    withSessionCookie($challengeCookie)->getJson(coreApiUrl($tenant->slug, '/auth/mfa-challenges'))->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        // El emparejamiento pendiente todavía no se ha escrito.
        expect(UserIdentity::query()->count())->toBe(0);
    });

    withSessionCookie($challengeCookie)
        ->postJson(coreApiUrl($tenant->slug, '/auth/mfa-verifications'), ['code' => currentTotpCode($secret)])
        ->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        // Superado el desafío: ahora sí se escribe el vínculo pendiente.
        $identities = UserIdentity::query()->where('user_id', $user->id)->get();
        expect($identities)->toHaveCount(1)
            ->and($identities->first()->link_method->value)->toBe('emparejamiento_sso');
    });
});

// CA-AUTH-355
test('CA-AUTH-355: intent=link arrancado desde el perfil crea el vínculo sobre el usuario de linking_user_id aunque el ACS llegue sin cookie de sesión', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlLoginSlug('saml-355'));
    $provider = createActiveSamlProvider($tenant->slug, $admin);

    $rawPassword = 'Cl4v3-Correcta-2026!';
    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($rawPassword) {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create([
            'email' => 'u355@example.com', 'password' => $rawPassword, 'status' => UserStatus::Activo,
        ]);
    });

    $authenticated = loginFor($tenant->slug, 'u355@example.com', $rawPassword);
    $sessionCookie = sessionCookieValue($authenticated);

    [$authorizationUrl] = beginSamlFlow($tenant->slug, $provider['public_id'], 'link', $sessionCookie);

    // completeSamlFlow() entrega el ACS SIN cookie de sesión de por
    // medio (§G.4.4: el ACS no tiene sesión) — el usuario a vincular sale
    // de linking_user_id, capturado al emitir la petición.
    $callback = completeSamlFlow($authorizationUrl, [
        'sub' => 'sub-355', 'attribute_name' => 'mail', 'attribute_value' => 'correo-distinto-355@example.com',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('vinculado');

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $identity = UserIdentity::query()->where('user_id', $user->id)->first();
        expect($identity)->not->toBeNull()
            ->and($identity->link_method->value)->toBe('perfil');
    });
});

// CA-AUTH-356
test('CA-AUTH-356: intent=link cuyo linking_user_id se desactivó entre la petición y la aserción no vincula y no crea sesión', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlLoginSlug('saml-356'));
    $provider = createActiveSamlProvider($tenant->slug, $admin);

    $rawPassword = 'Cl4v3-Correcta-2026!';
    $user = app(TenantContext::class)->runFor($tenant->id, function () use ($rawPassword) {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create([
            'email' => 'u356@example.com', 'password' => $rawPassword, 'status' => UserStatus::Activo,
        ]);
    });

    $authenticated = loginFor($tenant->slug, 'u356@example.com', $rawPassword);
    $sessionCookie = sessionCookieValue($authenticated);

    [$authorizationUrl] = beginSamlFlow($tenant->slug, $provider['public_id'], 'link', $sessionCookie);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        $user->fresh()->update(['status' => UserStatus::Inactivo]);
    });

    $callback = completeSamlFlow($authorizationUrl, [
        'sub' => 'sub-356', 'attribute_name' => 'mail', 'attribute_value' => 'u356@example.com',
    ]);

    // No se reinterpreta como login: la salida es la de correlación
    // inválida, no un acceso.
    expect(oauthCallbackResultCode($callback))->toBe('estado_no_valido');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-357
test('CA-AUTH-357: un bloqueo vivo para (tenant_id, email) impide entrar por SAML', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlLoginSlug('saml-357'), ['email' => 'u357@example.com']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        app(AccountLockService::class)->lock('u357@example.com', $user, 5);
    });

    [$authorizationUrl] = beginSamlFlow($tenant->slug, $providerId);
    $callback = completeSamlFlow($authorizationUrl, [
        'sub' => 'sub-357', 'attribute_name' => 'mail', 'attribute_value' => 'u357@example.com',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('cuenta_bloqueada');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-358
test('CA-AUTH-358: un usuario pendiente cuyo correo coincide no entra, no se activa y no se crea vínculo', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlLoginSlug('saml-358'), [
        'email' => 'u358@example.com', 'status' => UserStatus::Pendiente,
    ]);

    [$authorizationUrl] = beginSamlFlow($tenant->slug, $providerId);
    $callback = completeSamlFlow($authorizationUrl, [
        'sub' => 'sub-358', 'attribute_name' => 'mail', 'attribute_value' => 'u358@example.com',
    ]);

    // RN-AUTH-23/OPEN-AUTH-39: findActiveByEmail() resuelve el candidato
    // igual (no filtra por status), y es la comprobación de estado la que
    // rechaza — misma salida genérica que un usuario inactivo.
    expect(oauthCallbackResultCode($callback))->toBe('acceso_denegado');

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect($user->fresh()->status)->toBe(UserStatus::Pendiente);
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-359
test('CA-AUTH-359: un acceso SAML completado registra login_attempts con method=sso, sin ampliar el enumerado, y user_sessions funciona por el mismo camino que el login local', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlLoginSlug('saml-359'), ['email' => 'u359@example.com']);

    loginWithSamlFor($tenant->slug, $providerId, [
        'sub' => 'sub-359', 'attribute_name' => 'mail', 'attribute_value' => 'u359@example.com',
    ]);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $attempt = DB::table('login_attempts')->where('email', 'u359@example.com')->orderByDesc('id')->first();
        expect($attempt->outcome)->toBe('exito')
            ->and($attempt->method)->toBe('sso');

        expect(DB::table('user_sessions')->count())->toBeGreaterThan(0);
    });
});

// CA-AUTH-360
test('CA-AUTH-360: un centro con un proveedor OIDC y uno SAML activos produce dos vínculos independientes sobre el mismo usuario', function (): void {
    [$tenant, $admin] = provisionCoreTenant(samlLoginSlug('saml-360'));
    $user = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['email' => 'u360@example.com', 'status' => UserStatus::Activo]);
    });

    $oidc = createActiveOidcProvider($tenant->slug, $admin);
    $saml = createActiveSamlProvider($tenant->slug, $admin);

    loginWithOidcFor($tenant->slug, $oidc['public_id'], [
        'sub' => 'sub-360-oidc', 'email' => 'u360@example.com', 'email_verified' => '1',
    ]);

    loginWithSamlFor($tenant->slug, $saml['public_id'], [
        'sub' => 'sub-360-saml', 'attribute_name' => 'mail', 'attribute_value' => 'u360@example.com',
    ]);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $oidc, $saml): void {
        $identities = UserIdentity::query()->where('user_id', $user->id)->get();
        expect($identities)->toHaveCount(2);

        $oidcProviderId = IdentityProvider::query()->where('public_id', $oidc['public_id'])->value('id');
        $samlProviderId = IdentityProvider::query()->where('public_id', $saml['public_id'])->value('id');

        expect($identities->pluck('identity_provider_id')->sort()->values()->all())
            ->toBe(collect([$oidcProviderId, $samlProviderId])->sort()->values()->all());
    });
});
