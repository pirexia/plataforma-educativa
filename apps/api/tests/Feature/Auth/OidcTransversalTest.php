<?php

use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Infrastructure\SsoEnvironmentGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// REQ-AUTH-004 (1.4b). Transversales: catálogo de permisos, ausencia de
// module-enabled, no persistencia de tokens, aislamiento de Socialite,
// i18n de los 4 idiomas, y seguridad de arranque sin tocar variables.

// CA-AUTH-278
test('CA-AUTH-278: el callback ignora cualquier identificador de proveedor en la URL y resuelve siempre desde la sesión', function (): void {
    [$tenant, $admin] = provisionCoreTenant('oidc-278');
    app(TenantContext::class)->runFor($tenant->id, function () {
        return User::factory()->for(Person::factory()->create())->create(['email' => 'u278@example.com', 'status' => UserStatus::Activo]);
    });

    $providerB = createActiveOidcProvider($tenant->slug, $admin, ['display_name' => 'B', 'discovery_url' => OIDC_DISCOVERY_URL.'?issuer_suffix=-278b']);
    $providerA = createActiveOidcProvider($tenant->slug, $admin, ['display_name' => 'A']);

    [$authorizationUrl, $cookie] = beginOidcFlow($tenant->slug, $providerA['public_id']);

    $query = [];
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
    $formSubmission = array_merge($query, [
        'submit' => '1', 'sub' => 'sub-278', 'email' => 'u278@example.com', 'email_verified' => '1',
    ]);
    $authorizePath = (string) parse_url($authorizationUrl, PHP_URL_PATH);

    $redirect = withSessionCookie($cookie)->get($authorizePath.'?'.http_build_query($formSubmission))->assertRedirect();
    $callbackUrl = $redirect->headers->get('Location');

    // Se añade a mano un `provider` ajeno (el de B) en la query string del
    // callback real: RN-AUTH-103 dice que debe ignorarse por completo.
    $callbackUrl .= '&provider='.$providerB['public_id'];

    expect(parse_url((string) $callbackUrl, PHP_URL_PATH))->toContain('/auth/oauth/oidc/callback');

    $callback = withSessionCookie($cookie)->get($callbackUrl)->assertRedirect();

    expect(oauthCallbackResultCode($callback))->toBeNull();
});

// CA-AUTH-305
test('CA-AUTH-305: tras platform:sync-registry hay exactamente once permisos de auth, ninguno retirado ni especial', function (): void {
    test()->artisan('platform:sync-registry')->run();

    $permissions = DB::connection('pgsql_platform')->table('permissions')->where('module_code', 'auth')->get();

    expect($permissions)->toHaveCount(11)
        ->and($permissions->pluck('code')->sort()->values()->all())->toContain(
            'proveedor_identidad.leer', 'proveedor_identidad.crear',
            'proveedor_identidad.actualizar', 'proveedor_identidad.eliminar',
        )
        ->and($permissions->whereNotNull('retired_at'))->toHaveCount(0)
        ->and($permissions->where('is_special_category', true))->toHaveCount(0);
});

// CA-AUTH-306
test('CA-AUTH-306: ninguna de las nueve rutas de este paso lleva el middleware module-enabled', function (): void {
    $routeNames = [
        'identity-providers.index', 'identity-providers.show', 'identity-providers.store',
        'identity-providers.update', 'identity-providers.destroy',
        'identity-providers.secrets.store', 'identity-providers.secrets.destroy',
        'identity-providers.discovery-refreshes.store', 'auth.oauth.oidc.callback',
    ];

    foreach ($routeNames as $name) {
        $route = app('router')->getRoutes()->getByName($name);
        expect($route)->not->toBeNull("Ruta {$name} no encontrada");
        expect($route->middleware())->not->toContain('module-enabled');
    }
});

// CA-AUTH-307
test('CA-AUTH-307: ningún access_token, refresh_token ni id_token de proveedor se persiste', function (): void {
    [$tenant, $user, $providerId] = provisionOidcTenantWithActiveUser('oidc-307', ['email' => 'u307@example.com']);

    loginWithOidcFor($tenant->slug, $providerId, [
        'sub' => 'sub-307', 'email' => 'u307@example.com', 'email_verified' => '1',
    ]);

    app(TenantContext::class)->runFor($tenant->id, function (): void {
        $columns = DB::getSchemaBuilder()->getColumnListing('user_identities');
        expect($columns)->not->toContain('access_token')
            ->not->toContain('refresh_token')
            ->not->toContain('id_token');
    });
});

// CA-AUTH-308
test('CA-AUTH-308: ninguna importación de Laravel\\Socialite fuera de las implementaciones de ExternalIdentityProvider', function (): void {
    $moduleFiles = collect(File::allFiles(app_path('Modules/Auth')))
        ->filter(fn ($f) => $f->getExtension() === 'php');

    $allowed = [
        'SocialiteGoogleIdentityProvider.php',
    ];

    foreach ($moduleFiles as $file) {
        if (in_array($file->getFilename(), $allowed, true)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        expect($contents)->not->toContain('Laravel\\Socialite', "Socialite importado fuera de sitio en {$file->getFilename()}");
    }
});

// CA-AUTH-309
test('CA-AUTH-309: las traducciones de sso existen en los cuatro idiomas', function (): void {
    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        app()->setLocale($locale);

        expect(__('auth.sso.identity_provider_issuer_already_catalogued'))->not->toBe('auth.sso.identity_provider_issuer_already_catalogued')
            ->and(__('auth.sso.discovery.esquema_no_admitido'))->not->toBe('auth.sso.discovery.esquema_no_admitido')
            ->and(__('auth.sso.discovery.destino_no_publico'))->not->toBe('auth.sso.discovery.destino_no_publico')
            ->and(__('auth.mail.identity_matched.subject', ['tenant' => 'x']))->not->toBe('auth.mail.identity_matched.subject');
    }

    app()->setLocale('es');
});

// CA-AUTH-310
test('CA-AUTH-310: un arranque con APP_ENV=production sin tocar ninguna variable nueva no lanza excepción', function (): void {
    config(['auth-local.sso.allow_insecure_discovery' => false]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(SsoEnvironmentGuard::class)->verify())->not->toThrow(Throwable::class);

    app()->detectEnvironment(fn () => 'testing');
    config(['auth-local.sso.allow_insecure_discovery' => true]);
});
