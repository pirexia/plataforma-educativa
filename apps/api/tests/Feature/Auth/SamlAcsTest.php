<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// REQ-AUTH-004 (1.4c), funcional.md §G.6 ("El ACS, su cadena de
// middleware y la excepción de CSRF"), §G.3.2, RN-AUTH-124.

function samlAcsSlug(string $base): string
{
    return $base.'-'.strtolower(Str::random(6));
}

// CA-AUTH-346
test('CA-AUTH-346: el ACS es la única ruta sin csrf, con la pila exacta declarada, y no existe ninguna lista global validateCsrfTokens(except:)', function (): void {
    $route = app('router')->getRoutes()->getByName('auth.saml.acs');
    expect($route)->not->toBeNull();

    // 'api' es el grupo base implícito de Laravel (withRouting(api: ...)),
    // no una pieza de la cadena que funcional.md §G.3.2/api.md §8
    // describen — esas describen la cadena AÑADIDA explícitamente.
    expect($route->middleware())->toBe([
        'api', 'resolve-tenant', 'encrypt-cookies', 'add-queued-cookies', 'start-session', 'verify-session-tenant',
    ])->not->toContain('csrf');

    // Ninguna otra ruta de la aplicación carece de csrf bajo /api/v1
    // salvo esta: las rutas del grupo estándar (routes/api-v1.php) llevan
    // la cadena completa, csrf incluido (api.md §8), y el ACS vive en su
    // propio grupo aparte (routes/api.php) precisamente para no mezclar
    // exenciones. Comprobación negativa sobre bootstrap/app.php: no hay
    // ninguna lista global de exenciones en ningún sitio.
    $bootstrap = (string) File::get(base_path('bootstrap/app.php'));
    expect($bootstrap)->not->toContain('validateCsrfTokens');

    foreach (app('router')->getRoutes() as $otherRoute) {
        if ($otherRoute->getName() === 'auth.saml.acs') {
            continue;
        }

        if (in_array('resolve-tenant', $otherRoute->middleware(), true)) {
            expect($otherRoute->middleware())->toContain('csrf');
        }
    }
});

// CA-AUTH-347
test('CA-AUTH-347: un POST al ACS sin cookie de sesión y sin token CSRF no responde 419', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAcsSlug('saml-347'));

    // Sin withCredentials(), sin cookie, sin cabecera X-XSRF-TOKEN: el
    // caso real de un POST entre sitios que llega desde el IdP (§G.3.2).
    $response = test()->post(coreApiUrl($tenant->slug, "/auth/saml/{$providerId}/acs"), [
        'SAMLResponse' => base64_encode('bogus-no-es-una-aserción-real'),
    ]);

    expect($response->status())->not->toBe(419);
    $response->assertRedirect();
});

// CA-AUTH-348
// Afectado por el hallazgo real documentado en la cabecera de
// tests/Feature/Auth/SamlCertificatesTest.php (antes de CA-AUTH-327):
// necesita un login SAML completado con éxito.
test('CA-AUTH-348: un acceso SAML con éxito redirige a origen propio, sin datos personales en la URL, y la cookie resultante autentica de verdad', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAcsSlug('saml-348'), ['email' => 'u348@example.com']);

    [$authorizationUrl] = beginSamlFlow($tenant->slug, $providerId);
    $callback = completeSamlFlow($authorizationUrl, [
        'sub' => 'sub-348', 'attribute_name' => 'mail', 'attribute_value' => 'u348@example.com',
    ]);

    $callback->assertRedirect('/');

    $location = (string) $callback->headers->get('Location');
    expect($location)->not->toContain('SAMLResponse')
        ->not->toContain('sub-348')
        ->not->toContain('u348@example.com')
        ->not->toContain($providerId);

    // RN-AUTH-32: la sesión se regenera antes de autenticar. Se verifica
    // indirectamente comprobando que la cookie resultante SÍ autentica de
    // verdad — el ACS llega sin cookie en absoluto (§G.3.2), así que no
    // hay "sesión anterior" con la que comparar el identificador.
    $cookie = sessionCookieValue($callback);
    withSessionCookie($cookie)->getJson(coreApiUrl($tenant->slug, '/auth/mfa'))->assertOk();
});

// CA-AUTH-349
test('CA-AUTH-349: un tenant suspendido responde 503 desde ResolveTenant antes de tocar ninguna tabla', function (): void {
    [$tenant, $user, $providerId] = provisionSamlTenantWithActiveUser(samlAcsSlug('saml-349'));

    Tenant::query()->where('id', $tenant->id)->update(['status' => TenantStatus::Suspendido]);

    // provisionSamlTenantWithActiveUser() ya resolvió este tenant como
    // "activo" varias veces durante el alta del proveedor (ADR-033 §9,
    // TTL de 60s) — sin invalidar, ResolveTenant serviría la resolución
    // en caché y este test no ejercitaría el 503 en absoluto. Mismo
    // motivo que Cache::forget() en provisionActiveUser()/
    // provisionCoreTenant() de tests/Pest.php.
    Cache::forget("tenant-resolution:{$tenant->slug}");

    test()->post(coreApiUrl($tenant->slug, "/auth/saml/{$providerId}/acs"), [
        'SAMLResponse' => base64_encode('cualquier-cosa'),
    ])->assertStatus(503);
});

// CA-AUTH-350
test('CA-AUTH-350: ninguna ruta de este paso lleva el middleware module-enabled, tampoco el ACS', function (): void {
    $routeNames = [
        'auth.saml.acs',
        'identity-providers.metadata.show',
        'identity-providers.certificates.store',
        'identity-providers.certificates.destroy',
        'identity-providers.metadata-refreshes.store',
    ];

    foreach ($routeNames as $name) {
        $route = app('router')->getRoutes()->getByName($name);
        expect($route)->not->toBeNull("Ruta {$name} no encontrada");
        expect($route->middleware())->not->toContain('module-enabled');
    }
});
