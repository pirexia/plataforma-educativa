<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\IdentityProviderSecret;
use App\Modules\Auth\Infrastructure\SsoEnvironmentGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;

// REQ-AUTH-004 (1.4b), funcional.md Parte F, api.md Parte F, permisos.md
// Parte F. Catálogo de proveedores OIDC por tenant: alta con validación
// síncrona del documento de descubrimiento (las cinco guardas contra
// SSRF de funcional.md §F.4.2), credenciales cifradas con rotación, y
// aislamiento por tenant.

// CA-AUTH-260
test('CA-AUTH-260: alta con URL de descubrimiento válida guarda el issuer y los endpoints tal cual y nace no activo', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-260');

    $response = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'oidc',
        'display_name' => 'Entra ID del centro',
        'discovery_url' => OIDC_DISCOVERY_URL,
        'client_id' => 'client-260',
    ])->assertStatus(201);

    $response->assertJsonPath('is_enabled', false)
        ->assertJsonPath('provisioning_mode', 'desactivado')
        ->assertJsonPath('discovery_url', OIDC_DISCOVERY_URL)
        ->assertJsonPath('authorization_endpoint', 'http://localhost:8000/api/_sso-simulator/authorize')
        ->assertJsonPath('token_endpoint', 'http://localhost:8000/api/_sso-simulator/token');

    expect($response->json('issuer'))->toBe('http://localhost:8000/_sso-simulator');
});

// CA-AUTH-261
test('CA-AUTH-261: un issuer que no coincide con el origen de la URL responde 422 y no crea nada', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-261');

    $response = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'oidc',
        'display_name' => 'Proveedor roto',
        'discovery_url' => OIDC_DISCOVERY_URL.'?broken=emisor_no_coincide',
        'client_id' => 'client-261',
    ])->assertStatus(422);

    expect($response->json('errors.discovery_url.0.code'))->toBe('auth.sso.discovery.emisor_no_coincide');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(IdentityProvider::query()->count())->toBe(0);
    });
});

// CA-AUTH-262
test('CA-AUTH-262: una URL de descubrimiento que resuelve a una dirección privada se rechaza sin hacer la petición', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-262');

    foreach (['https://127.0.0.1/x', 'https://169.254.169.254/x', 'https://10.0.0.1/x'] as $url) {
        config(['auth-local.sso.allow_insecure_discovery' => false]);

        $response = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
            'protocol' => 'oidc',
            'display_name' => 'Proveedor privado',
            'discovery_url' => $url,
            'client_id' => 'client-262',
        ])->assertStatus(422);

        expect($response->json('errors.discovery_url.0.code'))->toBe('auth.sso.discovery.destino_no_publico');
    }

    config(['auth-local.sso.allow_insecure_discovery' => true]);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        expect(IdentityProvider::query()->count())->toBe(0);
    });
});

// CA-AUTH-264
test('CA-AUTH-264: AUTH_SSO_ALLOW_INSECURE_DISCOVERY=true fuera de local/testing aborta el arranque', function (): void {
    config(['auth-local.sso.allow_insecure_discovery' => true]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(SsoEnvironmentGuard::class)->verify())
        ->toThrow(RuntimeException::class);

    app()->detectEnvironment(fn () => 'testing');
    config(['auth-local.sso.allow_insecure_discovery' => true]);
});

// CA-AUTH-265
test('CA-AUTH-265: un public_id de proveedor de otro tenant responde 404, nunca 403, en las rutas de administración', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('idp-265a');
    [$tenantB, $adminB] = provisionCoreTenant('idp-265b');

    $providerB = createActiveOidcProvider($tenantB->slug, $adminB);

    test()->actingAs($adminA)
        ->getJson(coreApiUrl($tenantA->slug, "/identity-providers/{$providerB['public_id']}"))
        ->assertNotFound();

    test()->actingAs($adminA)
        ->patchJson(coreApiUrl($tenantA->slug, "/identity-providers/{$providerB['public_id']}"), ['display_name' => 'x'])
        ->assertNotFound();

    test()->actingAs($adminA)
        ->deleteJson(coreApiUrl($tenantA->slug, "/identity-providers/{$providerB['public_id']}"))
        ->assertNotFound();

    app(TenantContext::class)->runFor($tenantB->id, function () use ($providerB): void {
        expect(IdentityProvider::query()->where('public_id', $providerB['public_id'])->exists())->toBeTrue();
    });
});

// CA-AUTH-266
test('CA-AUTH-266: la credencial de cliente no aparece en el detalle, en la colección ni en audit_logs', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-266');

    $provider = createActiveOidcProvider($tenant->slug, $admin, ['display_name' => 'Con secreto']);

    $detail = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"))
        ->assertOk();

    $body = json_encode($detail->json());
    expect($body)->not->toContain('client_secret')
        ->and($detail->json('secrets.0'))->not->toHaveKey('client_secret');

    $index = test()->actingAs($admin)->getJson(coreApiUrl($tenant->slug, '/identity-providers'))->assertOk();
    expect(json_encode($index->json()))->not->toContain('client_secret');

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $logs = DB::table('audit_logs')
            ->where('auditable_type', 'identity_provider_secret')
            ->where('event', 'created')
            ->get();

        expect($logs)->not->toBeEmpty();

        // ADR-035 §4: client_secret declarado en $auditSecretAttributes
        // se registra como "que cambió", nunca su valor.
        foreach ($logs as $log) {
            $changes = json_decode($log->changes, true);
            expect($changes['client_secret'] ?? null)->toBe(['redacted' => 'secret']);
        }
    });
});

// CA-AUTH-267
test('CA-AUTH-267: con dos credenciales activas se usa la más reciente, y al retirarla el canje usa la otra sin intervención', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-267');
    app(TenantContext::class)->runFor($tenant->id, function () {
        $person = Person::factory()->create();

        return User::factory()->for($person)->create([
            'email' => 'u267@example.com',
            'status' => UserStatus::Activo,
        ]);
    });

    $provider = createActiveOidcProvider($tenant->slug, $admin);

    // Segunda credencial, más reciente.
    $secondSecret = test()->actingAs($admin)
        ->postJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/secrets"), [
            'client_secret' => 'segunda-credencial-267',
        ])->assertStatus(201);

    // El login funciona con la credencial más reciente ya vigente.
    $cookie = loginWithOidcFor($tenant->slug, $provider['public_id'], [
        'sub' => 'sub-267', 'email' => 'u267@example.com', 'email_verified' => '1',
    ]);
    expect($cookie)->not->toBeEmpty();

    // Retira la más reciente: el canje siguiente usa la primera, sin intervención.
    test()->actingAs($admin)
        ->deleteJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}/secrets/{$secondSecret->json('public_id')}"))
        ->assertNoContent();

    $cookie2 = loginWithOidcFor($tenant->slug, $provider['public_id'], [
        'sub' => 'sub-267', 'email' => 'u267@example.com', 'email_verified' => '1',
    ]);
    expect($cookie2)->not->toBeEmpty();
});

// CA-AUTH-268
test('CA-AUTH-268: una credencial a menos de 30 días de caducar se marca expiring_soon', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-268');

    $provider = createActiveOidcProvider($tenant->slug, $admin);

    app(TenantContext::class)->runFor($tenant->id, function () use ($provider): void {
        IdentityProviderSecret::query()
            ->where('public_id', $provider['secret_public_id'])
            ->update(['expires_at' => now()->addDays(10)]);
    });

    $detail = test()->actingAs($admin)
        ->getJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"))
        ->assertOk();

    expect($detail->json('secret_status.expiring_soon'))->toBeTrue();

    test()->artisan('auth:warn-expiring-client-secrets')->assertSuccessful();
});

// CA-AUTH-269
test('CA-AUTH-269: un tenant sin proveedores catalogados y AUTH_OAUTH_DRIVER=none no pinta ningún botón', function (): void {
    config(['auth-local.oauth.driver' => 'none']);
    [$tenant] = provisionCoreTenant('idp-269');

    $response = test()->getJson(coreApiUrl($tenant->slug, '/auth/identity-providers'))->assertOk();

    $response->assertExactJson(['data' => [], 'meta' => ['total' => 0]]);

    config(['auth-local.oauth.driver' => 'fake']);
});

// CA-AUTH-270
test('CA-AUTH-270: de dos proveedores, uno activo y otro no, la lista pública solo trae el activo, y arrancar con el inactivo responde 422', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-270');

    $active = createActiveOidcProvider($tenant->slug, $admin, ['display_name' => 'Activo']);

    // Segundo proveedor, con un emisor distinto (UNIQUE(tenant_id,
    // issuer) impide catalogar dos veces el mismo), nunca activado.
    $inactiveStore = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'oidc',
        'display_name' => 'Inactivo',
        'discovery_url' => OIDC_DISCOVERY_URL.'?issuer_suffix=-alt-270',
        'client_id' => 'client-270-inactive',
    ])->assertStatus(201);
    $inactiveId = $inactiveStore->json('public_id');
    expect($inactiveStore->json('is_enabled'))->toBeFalse();

    resetSessionState();
    $list = test()->getJson(coreApiUrl($tenant->slug, '/auth/identity-providers'))->assertOk();
    // AUTH_OAUTH_DRIVER=fake (entorno de test) añade la entrada "google"
    // del driver global de 1.4 (api.md §F.6) — se filtra para quedarse
    // solo con lo que aporta este paso: el catálogo del tenant.
    $catalogEntries = collect($list->json('data'))->reject(fn ($row) => $row['id'] === 'google')->values();
    expect($catalogEntries)->toHaveCount(1)
        ->and($catalogEntries[0]['id'])->toBe($active['public_id']);

    resetSessionState();
    test()->postJson(coreApiUrl($tenant->slug, '/auth/oauth-authorizations'), [
        'provider' => $inactiveId,
        'intent' => 'login',
    ])->assertStatus(422);
});

// CA-AUTH-271
test('CA-AUTH-271: un identificador de proveedor de otro tenant en el arranque anónimo responde 422 con el mismo cuerpo que uno inexistente', function (): void {
    [$tenantA] = provisionCoreTenant('idp-271a');
    [$tenantB, $adminB] = provisionCoreTenant('idp-271b');

    $providerB = createActiveOidcProvider($tenantB->slug, $adminB);

    resetSessionState();
    $withOther = test()->postJson(coreApiUrl($tenantA->slug, '/auth/oauth-authorizations'), [
        'provider' => $providerB['public_id'],
        'intent' => 'login',
    ])->assertStatus(422);

    resetSessionState();
    $withInexistent = test()->postJson(coreApiUrl($tenantA->slug, '/auth/oauth-authorizations'), [
        'provider' => 'does-not-exist-at-all',
        'intent' => 'login',
    ])->assertStatus(422);

    $normalize = fn (array $body) => Arr::except($body, ['request_id']);
    expect($normalize($withOther->json()))->toBe($normalize($withInexistent->json()));
});

// CA-AUTH-272
test('CA-AUTH-272: la URL de autorización lleva response_type=code, state, nonce, PKCE S256 y el authorization_endpoint descubierto', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-272');

    $provider = createActiveOidcProvider($tenant->slug, $admin);

    [$authorizationUrl] = beginOidcFlow($tenant->slug, $provider['public_id']);

    expect($authorizationUrl)->toStartWith('http://localhost:8000/api/_sso-simulator/authorize?');

    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    expect($query['response_type'])->toBe('code')
        ->and($query['state'])->not->toBeEmpty()
        ->and($query['nonce'])->not->toBeEmpty()
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->not->toBeEmpty();
});

// CA-AUTH-273
test('CA-AUTH-273: un Host ajeno no aparece en la redirect_uri publicada en el detalle del proveedor', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-273');

    $provider = createActiveOidcProvider($tenant->slug, $admin);

    $detail = test()->actingAs($admin)
        ->withHeader('Host', 'maligno.example.test')
        ->getJson(coreApiUrl($tenant->slug, "/identity-providers/{$provider['public_id']}"))
        ->assertOk();

    expect($detail->json('integration.redirect_uri'))
        ->toContain($tenant->slug)
        ->not->toContain('maligno.example.test');
});

// CA-AUTH-274
test('CA-AUTH-274: un proveedor activo sin credencial vigente responde 422 al arrancar el flujo', function (): void {
    [$tenant, $admin] = provisionCoreTenant('idp-274');

    $store = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/identity-providers'), [
        'protocol' => 'oidc',
        'display_name' => 'Sin credencial',
        'discovery_url' => OIDC_DISCOVERY_URL,
        'client_id' => 'client-274',
    ])->assertStatus(201);

    $publicId = $store->json('public_id');

    // No puede activarse sin credencial (409) -- se demuestra aparte;
    // aquí forzamos el estado "activo sin credencial vigente" a mano,
    // el único camino real es retirar la última tras activar (api.md §F.1).
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, "/identity-providers/{$publicId}/secrets"), [
        'client_secret' => 'temporal-274',
    ])->assertStatus(201);

    test()->actingAs($admin)->patchJson(coreApiUrl($tenant->slug, "/identity-providers/{$publicId}"), [
        'is_enabled' => true,
    ])->assertOk();

    app(TenantContext::class)->runFor($tenant->id, function () use ($publicId): void {
        IdentityProviderSecret::query()
            ->whereHas('identityProvider', fn ($q) => $q->where('public_id', $publicId))
            ->update(['retired_at' => now()]);
    });

    resetSessionState();
    test()->postJson(coreApiUrl($tenant->slug, '/auth/oauth-authorizations'), [
        'provider' => $publicId,
        'intent' => 'login',
    ])->assertStatus(422);
});
