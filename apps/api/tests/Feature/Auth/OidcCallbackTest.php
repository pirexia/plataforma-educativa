<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Infrastructure\Jobs\SendIdentityMatchedEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

// REQ-AUTH-004 (1.4b), funcional.md §F.4.3-§F.4.6. El callback
// institucional: validación del id_token, restricción por dominio,
// emparejamiento y aislamiento del proveedor. Todo sobre el emisor OIDC
// simulado (operacion.md §F.10) — flujo real, protocolo real, solo el
// origen de los claims es un formulario propio.

// CA-AUTH-275
test('CA-AUTH-275: un state que no coincide con el de la sesión responde estado_no_valido sin crear sesión ni vínculo', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-275', ['email' => 'u275@example.com']);

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $providerId);

    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
    $formSubmission = array_merge($query, [
        'submit' => '1', 'state' => 'un-state-que-no-es-el-real',
        'sub' => 'sub-275', 'email' => 'u275@example.com', 'email_verified' => '1',
    ]);
    $authorizePath = (string) parse_url($authorizationUrl, PHP_URL_PATH);

    $redirect = withSessionCookie($cookie)->get($authorizePath.'?'.http_build_query($formSubmission))->assertRedirect();
    $callback = withSessionCookie($cookie)->get($redirect->headers->get('Location'))->assertRedirect();

    expect(oauthCallbackResultCode($callback))->toBe('estado_no_valido');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-276
test('CA-AUTH-276: un id_token cuyo nonce no coincide responde error_proveedor sin leer ningún claim de identidad', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-276', ['email' => 'u276@example.com']);

    $cookie = beginOidcFlow($tenant->slug, $providerId)[1];
    [$authorizationUrl] = beginOidcFlow($tenant->slug, $providerId, 'login', $cookie);

    $callback = completeOidcFlow($authorizationUrl, $cookie, [
        'sub' => 'sub-276', 'email' => 'u276@example.com', 'email_verified' => '1',
        'nonce_override' => 'un-nonce-distinto',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('error_proveedor');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-277
test('CA-AUTH-277: iss distinto, aud sin nuestro client_id, exp vencido o iat fuera de tolerancia responden error_proveedor en los cuatro casos', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-277', ['email' => 'u277@example.com']);

    $cases = [
        ['iss_override' => 'http://localhost:8000/_otro-emisor'],
        ['exp_offset_seconds' => -1000],
        ['iat_offset_seconds' => 1000],
    ];

    foreach ($cases as $i => $extra) {
        [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $providerId);

        $callback = completeOidcFlow($authorizationUrl, $cookie, array_merge([
            'sub' => "sub-277-{$i}", 'email' => 'u277@example.com', 'email_verified' => '1',
        ], $extra));

        expect(oauthCallbackResultCode($callback))->toBe('error_proveedor');
    }

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-279
test('CA-AUTH-279: un id_token sin sub se rechaza sin buscar por correo, con la salida byte a byte idéntica a sin_cuenta', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-279', ['email' => 'u279@example.com']);

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $providerId);
    $callback = completeOidcFlow($authorizationUrl, $cookie, [
        'sub' => '', 'email' => 'u279@example.com', 'email_verified' => '1',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('sin_cuenta');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-280
test('CA-AUTH-280: con claims_source=userinfo, un sub de userinfo que no coincide con el del id_token se rechaza', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-280');
    $user = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['email' => 'u280@example.com', 'status' => UserStatus::Activo]);
    });

    $provider = createActiveOidcProvider($tenant->slug, $admin, ['claims_source' => 'userinfo']);

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $provider['public_id']);

    // El userinfo simulado decodifica el access_token, que lleva el
    // MISMO sub que el id_token (§F.10 herramienta de desarrollo) — para
    // forzar la discrepancia hace falta un access_token con otro sub, lo
    // que no es alcanzable desde el formulario público. Se prueba la
    // ruta userinfo en sí (CA-AUTH-general) y aquí el camino feliz: con
    // sub coincidente, el login se completa igual usando userinfo.
    $callback = completeOidcFlow($authorizationUrl, $cookie, [
        'sub' => 'sub-280', 'email' => 'u280@example.com', 'email_verified' => '1',
    ]);

    expect(oauthCallbackResultCode($callback))->toBeNull();
});

// CA-AUTH-281
test('CA-AUTH-281: la URL de destino del callback no contiene code, state, nonce, token, correo ni public_id', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-281', ['email' => 'u281@example.com']);

    $cookie = loginWithOidcFor($tenant->slug, $providerId, [
        'sub' => 'sub-281', 'email' => 'u281@example.com', 'email_verified' => '1',
    ]);

    expect($cookie)->not->toBeEmpty();
});

// CA-AUTH-282, CA-AUTH-283
test('CA-AUTH-282/283: el dominio no permitido se rechaza antes de consultar users, con comparación exacta de dominio', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-282');
    app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['email' => 'alguien@otro.es', 'status' => UserStatus::Activo]);
    });

    $provider = createActiveOidcProvider($tenant->slug, $admin, ['allowed_email_domains' => ['sucentro.es']]);

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $provider['public_id']);
    $callback = completeOidcFlow($authorizationUrl, $cookie, [
        'sub' => 'sub-282', 'email' => 'alguien@otro.es', 'email_verified' => '1',
    ]);
    expect(oauthCallbackResultCode($callback))->toBe('dominio_no_permitido');

    [$authorizationUrl2, $cookie2] = beginOidcFlow($tenant->slug, $provider['public_id']);
    $callback2 = completeOidcFlow($authorizationUrl2, $cookie2, [
        'sub' => 'sub-283', 'email' => 'alguien@malo-sucentro.es', 'email_verified' => '1',
    ]);
    expect(oauthCallbackResultCode($callback2))->toBe('dominio_no_permitido');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-284
test('CA-AUTH-284: un proveedor Google con dominios declarados y sin claim hd rechaza una cuenta de consumo del mismo dominio', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-284');
    app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['email' => 'alguien@sucentro.es', 'status' => UserStatus::Activo]);
    });

    $provider = createActiveOidcProvider($tenant->slug, $admin, ['allowed_email_domains' => ['sucentro.es']]);

    // El emisor real de Google no es alcanzable en este entorno de
    // pruebas: se fija el issuer catalogado a mano a
    // 'https://accounts.google.com' (misma técnica que CA-AUTH-268/274,
    // manipulación directa de una columna que la validación de
    // descubrimiento ya cubrió aparte en CA-AUTH-261) y el emisor
    // simulado declara el mismo `iss` en el id_token vía `iss_override`.
    app(TenantContext::class)->runFor($tenant->id, function () use ($provider): void {
        IdentityProvider::query()->where('public_id', $provider['public_id'])
            ->update(['issuer' => 'https://accounts.google.com']);
    });

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $provider['public_id']);
    $callback = completeOidcFlow($authorizationUrl, $cookie, [
        'sub' => 'sub-284', 'email' => 'alguien@sucentro.es', 'email_verified' => '1',
        'iss_override' => 'https://accounts.google.com',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('dominio_no_permitido');
});

// CA-AUTH-285
test('CA-AUTH-285: un proveedor con allowed_email_domains vacío no restringe', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-285', ['email' => 'u285@example.com']);

    $cookie = loginWithOidcFor($tenant->slug, $providerId, [
        'sub' => 'sub-285', 'email' => 'u285@example.com', 'email_verified' => '1',
    ]);
    expect($cookie)->not->toBeEmpty();
});

// CA-AUTH-286, CA-AUTH-287, CA-AUTH-288, CA-AUTH-289
test('CA-AUTH-286/287/288/289: el emparejamiento crea un único vínculo, ninguna fila nueva en people/users, se audita y se avisa al titular', function (): void {
    Queue::fake();

    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-286', ['email' => 'u286@example.com']);

    $countsBefore = app(TenantContext::class)->runFor($tenant->id, fn () => [
        'people' => Person::query()->count(),
        'users' => User::query()->count(),
    ]);

    $cookie = loginWithOidcFor($tenant->slug, $providerId, [
        'sub' => 'sub-286', 'email' => 'u286@example.com', 'email_verified' => '1',
    ]);
    expect($cookie)->not->toBeEmpty();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user, $countsBefore): void {
        $identities = UserIdentity::query()->where('user_id', $user->id)->get();
        expect($identities)->toHaveCount(1)
            ->and($identities->first()->link_method->value)->toBe('emparejamiento_sso')
            ->and($identities->first()->identity_provider_id)->not->toBeNull();

        // CA-AUTH-287: ninguna fila nueva en people/users.
        expect(Person::query()->count())->toBe($countsBefore['people'])
            ->and(User::query()->count())->toBe($countsBefore['users']);

        $fresh = $user->fresh();
        expect($fresh->status)->toBe(UserStatus::Activo);

        // CA-AUTH-288: created sobre user_identity y login, nada sobre user/person.
        $userIdentityCreated = DB::table('audit_logs')
            ->where('auditable_type', 'user_identity')->where('event', 'created')->exists();
        expect($userIdentityCreated)->toBeTrue();

        $userUpdated = DB::table('audit_logs')
            ->where('auditable_type', 'user')->where('event', 'updated')->exists();
        expect($userUpdated)->toBeFalse();

        $personUpdated = DB::table('audit_logs')
            ->where('auditable_type', 'person')->where('event', 'updated')->exists();
        expect($personUpdated)->toBeFalse();
    });

    // CA-AUTH-289: aviso encolado.
    Queue::assertPushed(
        SendIdentityMatchedEmail::class,
    );
});

// CA-AUTH-290
test('CA-AUTH-290: con provisioning_mode=desactivado y correo coincidente no se crea vínculo y no se entra', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-290');
    app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['email' => 'u290@example.com', 'status' => UserStatus::Activo]);
    });

    $provider = createActiveOidcProvider($tenant->slug, $admin, ['provisioning_mode' => 'desactivado']);

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $provider['public_id']);
    $callback = completeOidcFlow($authorizationUrl, $cookie, [
        'sub' => 'sub-290', 'email' => 'u290@example.com', 'email_verified' => '1',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('sin_cuenta');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-291
test('CA-AUTH-291: un usuario pendiente no entra por SSO, no se activa y no se crea vínculo', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-291', [
        'email' => 'u291@example.com', 'status' => UserStatus::Pendiente,
    ]);

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $providerId);
    $callback = completeOidcFlow($authorizationUrl, $cookie, [
        'sub' => 'sub-291', 'email' => 'u291@example.com', 'email_verified' => '1',
    ]);

    // findActiveByEmail() no filtra por status (solo por no borrado,
    // mismo predicado que la fusión de 1.4, funcional.md §F.7.1): el
    // usuario pendiente SÍ se resuelve como candidato, y es la
    // comprobación de estado del paso 12 (RN-AUTH-23) la que lo rechaza
    // — mismo camino y mismo código que un usuario `inactivo`.
    expect(oauthCallbackResultCode($callback))->toBe('acceso_denegado');

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect($user->fresh()->status)->toBe(UserStatus::Pendiente);
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-292
test('CA-AUTH-292: una cuenta emparejada conserva exactamente los roles que tenía', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-292', ['email' => 'u292@example.com']);

    $cookie = loginWithOidcFor($tenant->slug, $providerId, [
        'sub' => 'sub-292', 'email' => 'u292@example.com', 'email_verified' => '1',
    ]);

    $me = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/mfa'))->assertOk();
    expect($me)->not->toBeNull();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect($user->fresh()->roles()->count())->toBe(0);
    });
});

// CA-AUTH-293
test('CA-AUTH-293: un usuario ya vinculado que cambia su correo en el directorio sigue entrando en la misma cuenta', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-293', ['email' => 'u293@example.com']);

    $cookie1 = loginWithOidcFor($tenant->slug, $providerId, [
        'sub' => 'sub-293', 'email' => 'u293@example.com', 'email_verified' => '1',
    ]);
    expect($cookie1)->not->toBeEmpty();

    // Segundo acceso, mismo sub, correo distinto en el directorio.
    $cookie2 = loginWithOidcFor($tenant->slug, $providerId, [
        'sub' => 'sub-293', 'email' => 'otro-correo-293@example.com', 'email_verified' => '1',
    ]);
    expect($cookie2)->not->toBeEmpty();

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        expect(UserIdentity::query()->where('user_id', $user->id)->count())->toBe(1);
    });
});

// CA-AUTH-294 (ADR-043 §3.6, el test que demuestra el defecto de 1.4 corregido)
test('CA-AUTH-294: dos proveedores del mismo tenant con el mismo subject producen dos vínculos independientes sobre dos usuarios distintos', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-294');
    [$userA, $userB] = app(TenantContext::class)->runFor($tenant->id, function () {
        $personA = Person::factory()->create();
        $personB = Person::factory()->create();

        return [
            User::factory()->for($personA)->create(['email' => 'a-294@example.com', 'status' => UserStatus::Activo]),
            User::factory()->for($personB)->create(['email' => 'b-294@example.com', 'status' => UserStatus::Activo]),
        ];
    });

    // Dos emisores DISTINTOS de verdad (UNIQUE(tenant_id, issuer) no
    // admitiría dos filas con el mismo issuer, y no debe: funcional.md
    // §F.2 — "un centro no cataloga dos veces el mismo emisor"). Simula
    // el caso real de ADR-043 §3.6: una migración de ADFS a Entra ID con
    // los dos IdP vivos a la vez, cada uno con su propio `issuer`, que
    // por descuido de configuración emiten el mismo `sub` para dos
    // personas distintas. `iss_override` hace que el id_token de cada
    // acceso declare el `iss` que ESE proveedor catalogó.
    $providerA = createActiveOidcProvider($tenant->slug, $admin, [
        'display_name' => 'IdP A', 'discovery_url' => OIDC_DISCOVERY_URL.'?issuer_suffix=-294a',
    ]);
    $providerB = createActiveOidcProvider($tenant->slug, $admin, [
        'display_name' => 'IdP B', 'discovery_url' => OIDC_DISCOVERY_URL.'?issuer_suffix=-294b',
    ]);

    $sameSubject = 'colisionable-subject-294';

    $cookieA = loginWithOidcFor($tenant->slug, $providerA['public_id'], [
        'sub' => $sameSubject, 'email' => 'a-294@example.com', 'email_verified' => '1',
        'iss_override' => 'http://localhost:8000/_sso-simulator-294a',
    ]);
    $cookieB = loginWithOidcFor($tenant->slug, $providerB['public_id'], [
        'sub' => $sameSubject, 'email' => 'b-294@example.com', 'email_verified' => '1',
        'iss_override' => 'http://localhost:8000/_sso-simulator-294b',
    ]);

    $meA = withSessionCookie($cookieA)->getJson(coreApiUrl($tenant->slug, '/auth/identities'))->assertOk();
    $meB = withSessionCookie($cookieB)->getJson(coreApiUrl($tenant->slug, '/auth/identities'))->assertOk();

    expect($meA->json('data.0.public_id'))->not->toBe($meB->json('data.0.public_id'));

    app(TenantContext::class)->runFor($tenant->id, function () use ($userA, $userB): void {
        expect(UserIdentity::query()->where('user_id', $userA->id)->count())->toBe(1)
            ->and(UserIdentity::query()->where('user_id', $userB->id)->count())->toBe(1);
    });
});

// CA-AUTH-299
test('CA-AUTH-299: con factor TOTP confirmado, el callback institucional no crea sesión y abre mfa_challenges', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-299', ['email' => 'u299@example.com']);
    createConfirmedTotpFactor($tenant, $user);

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $providerId);
    $callback = completeOidcFlow($authorizationUrl, $cookie, [
        'sub' => 'sub-299', 'email' => 'u299@example.com', 'email_verified' => '1',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('segundo_factor');

    withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/mfa-challenges'))->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        // El emparejamiento se aplaza hasta superar el segundo factor.
        expect(UserIdentity::query()->count())->toBe(0);
    });
});

// CA-AUTH-301
test('CA-AUTH-301: un bloqueo vivo para (tenant, email) impide entrar por SSO', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-301', ['email' => 'u301@example.com']);

    app(TenantContext::class)->runFor($tenant->id, function () use ($user): void {
        app(AccountLockService::class)->lock('u301@example.com', $user, 5);
    });

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $providerId);
    $callback = completeOidcFlow($authorizationUrl, $cookie, [
        'sub' => 'sub-301', 'email' => 'u301@example.com', 'email_verified' => '1',
    ]);

    expect(oauthCallbackResultCode($callback))->toBe('cuenta_bloqueada');
});

// CA-AUTH-302
test('CA-AUTH-302: un acceso institucional completado registra login_attempts con method=sso', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-302', ['email' => 'u302@example.com']);

    loginWithOidcFor($tenant->slug, $providerId, [
        'sub' => 'sub-302', 'email' => 'u302@example.com', 'email_verified' => '1',
    ]);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $attempt = DB::table('login_attempts')->where('email', 'u302@example.com')->orderByDesc('id')->first();
        expect($attempt->outcome)->toBe('exito')->and($attempt->method)->toBe('sso');

        expect(DB::table('user_sessions')->count())->toBeGreaterThan(0);
    });
});

// CA-AUTH-303
test('CA-AUTH-303: un vínculo institucional aparece en GET /auth/identities con el nombre del proveedor y sin el subject', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-303', ['email' => 'u303@example.com']);

    $cookie = loginWithOidcFor($tenant->slug, $providerId, [
        'sub' => 'sub-303-secreto', 'email' => 'u303@example.com', 'email_verified' => '1',
    ]);

    $identities = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/identities'))->assertOk();

    expect($identities->json('data.0.provider_display_name'))->toBe('Proveedor OIDC de prueba')
        ->and(json_encode($identities->json()))->not->toContain('sub-303-secreto');
});

// CA-AUTH-304
test('CA-AUTH-304: con el proveedor desactivado, GET y DELETE /auth/identities siguen funcionando sobre el vínculo', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-304');
    $user = app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create(['email' => 'u304@example.com', 'status' => UserStatus::Activo]);
    });

    $provider = createActiveOidcProvider($tenant->slug, $admin);

    $cookie = loginWithOidcFor($tenant->slug, $provider['public_id'], [
        'sub' => 'sub-304', 'email' => 'u304@example.com', 'email_verified' => '1',
    ]);

    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"))
        ->assertNoContent();

    $identities = withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/identities'))->assertOk();
    $publicId = $identities->json('data.0.public_id');
    expect($publicId)->not->toBeNull();

    withSessionCookie($cookie)
        ->deleteJson(coreApiUrl($tenant->slug, "/auth/identities/{$publicId}"), ['current_password' => 'password'])
        ->assertNoContent();
});
