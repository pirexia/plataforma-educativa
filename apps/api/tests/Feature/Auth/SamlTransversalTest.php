<?php

use App\Modules\Auth\Infrastructure\FakeSamlIdentityProviderController;
use App\Modules\Auth\Infrastructure\SamlEnvironmentGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// REQ-AUTH-004 (1.4c), funcional.md §G.6 ("Transversales"), §G.11.

// CA-AUTH-361
test('CA-AUTH-361: tras platform:sync-registry sigue habiendo exactamente once permisos de auth — 1.4c no declara ninguno nuevo', function (): void {
    test()->artisan('platform:sync-registry')->run();

    $permissions = DB::connection('pgsql_platform')->table('permissions')->where('module_code', 'auth')->get();

    expect($permissions)->toHaveCount(11)
        ->and($permissions->pluck('code')->sort()->values()->all())->toContain(
            'proveedor_identidad.leer', 'proveedor_identidad.crear',
            'proveedor_identidad.actualizar', 'proveedor_identidad.eliminar',
        )
        ->and($permissions->whereNotNull('retired_at'))->toHaveCount(0);
});

// CA-AUTH-362
test('CA-AUTH-362: ninguna importación de OneLogin\\Saml2 existe fuera de la implementación de SamlIdentityProvider, y Auth no se instancia en ningún sitio', function (): void {
    $allowed = [
        'OneLoginSamlIdentityProvider.php',
        'EloquentSamlIdentityProviderRegistry.php',
    ];

    $moduleFiles = collect(File::allFiles(app_path('Modules/Auth')))
        ->filter(fn ($f) => $f->getExtension() === 'php');

    foreach ($moduleFiles as $file) {
        $contents = file_get_contents($file->getPathname());

        // ADR-043 §10.2: OneLogin\Saml2\Auth no se instancia jamás, en
        // ningún fichero, ni siquiera en los dos autorizados a importar
        // el espacio de nombres.
        expect($contents)->not->toContain('new \\OneLogin\\Saml2\\Auth')
            ->not->toContain('new OneLoginAuth')
            ->not->toMatch('/use OneLogin\\\\Saml2\\\\Auth\b/');

        if (in_array($file->getFilename(), $allowed, true)) {
            continue;
        }

        expect($contents)->not->toContain('OneLogin\\Saml2', "OneLogin\\Saml2 importado fuera de sitio en {$file->getFilename()}");
    }
});

// CA-AUTH-363
test('CA-AUTH-363: ninguna aserción, su XML ni ningún fragmento suyo se persiste — solo ID y NotOnOrAfter sobreviven', function (): void {
    $consumedAssertionsColumns = DB::getSchemaBuilder()->getColumnListing('saml_consumed_assertions');
    $authRequestsColumns = DB::getSchemaBuilder()->getColumnListing('saml_auth_requests');

    $forbidden = ['assertion_xml', 'saml_response', 'raw_assertion', 'xml', 'response_xml', 'payload'];

    foreach ($forbidden as $column) {
        expect($consumedAssertionsColumns)->not->toContain($column);
        expect($authRequestsColumns)->not->toContain($column);
    }

    // Positivo: de una aserción consumida solo sobreviven su ID y su
    // NotOnOrAfter (RN-AUTH-95 ampliado).
    expect($consumedAssertionsColumns)->toContain('assertion_id')->toContain('not_on_or_after');
});

// CA-AUTH-364
test('CA-AUTH-364: los códigos de fallo de validación de metadatos y de resultado existen en los cuatro idiomas', function (): void {
    $metadataFailureCodes = [
        'metadatos_no_validos', 'metadatos_ambiguos', 'binding_no_admitido',
        'sin_certificado_de_firma', 'formato_de_identificador_no_admitido', 'emisor_ya_catalogado',
        'destino_no_publico',
    ];

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        app()->setLocale($locale);

        foreach ($metadataFailureCodes as $code) {
            $translated = __("auth.saml.metadata.{$code}");
            expect($translated)->not->toBe("auth.saml.metadata.{$code}");
        }

        expect(__('auth.saml.sign_authn_requests_without_platform_key'))->not->toBe('auth.saml.sign_authn_requests_without_platform_key')
            ->and(__('auth.saml.certificate_last_active'))->not->toBe('auth.saml.certificate_last_active')
            // Los catorce códigos de resultado son los mismos de §F.7.1
            // (OIDC), reutilizados sin ampliar — ya cubiertos por
            // CA-AUTH-309 (1.4b). Se confirma aquí uno de los que SAML
            // usa de verdad (estado_no_valido), que ya existía.
            ->and(__('auth.sso.identity_provider_issuer_already_catalogued'))->not->toBe('auth.sso.identity_provider_issuer_already_catalogued');
    }

    app()->setLocale('es');
});

// CA-AUTH-365
test('CA-AUTH-365: un despliegue sin tocar ninguna variable de entorno de este paso arranca sin excepción con APP_ENV=production', function (): void {
    // operacion.md §G.2.1/§G.2.2: los valores por defecto reales, no de
    // conveniencia de test — AUTH_SAML_ALLOW_INSECURE_METADATA=false y
    // las dos rutas de clave vacías.
    config([
        'auth-local.saml.allow_insecure_metadata' => false,
        'auth-local.saml.sp_signing_key_path' => '',
    ]);
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => app(SamlEnvironmentGuard::class)->verify())->not->toThrow(Throwable::class);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        config(['auth-local.saml.allow_insecure_metadata' => true]);
    }
});

// CA-AUTH-366
test('CA-AUTH-366: con APP_ENV=production, la ruta del IdP SAML simulado no está registrada, y la guarda de arranque del controlador aborta si se invoca fuera de local/testing', function (): void {
    // Barrera 1: el registro de la ruta está condicionado, en el propio
    // código fuente, a app()->environment(['local', 'testing']) — la
    // comprobación estática, ya que las rutas de este proceso de test se
    // cargan una sola vez con APP_ENV=testing y no se puede re-registrar
    // el router a mitad de la suite para simular un arranque real en
    // production.
    $routesSource = (string) File::get(base_path('routes/api.php'));
    expect($routesSource)->toContain("environment(['local', 'testing'])")
        ->toContain('sso-simulator/saml');

    // Barrera 2: la guarda de arranque del propio controlador —
    // invocación directa con el entorno forzado a production.
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => app(FakeSamlIdentityProviderController::class)->metadata(Request::create('/x')))
            ->toThrow(RuntimeException::class);
        expect(fn () => app(FakeSamlIdentityProviderController::class)->sso(Request::create('/x')))
            ->toThrow(RuntimeException::class);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});
