<?php

use App\Http\Controllers\HealthController;
use App\Modules\Auth\Http\Controllers\SamlAcsController;
use App\Modules\Auth\Infrastructure\FakeOidcIssuerController;
use App\Modules\Auth\Infrastructure\FakeSamlIdentityProviderController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// REQ-AUTH-004 (1.4b), operacion.md §F.10. El emisor OIDC simulado: fuera
// del grupo de tenant a propósito (es un emisor de plataforma, no de un
// centro concreto — el catálogo de cada tenant apunta a él por su propia
// discovery_url) y solo registrado en local/testing (§F.10.3, segunda
// barrera junto a FakeOidcIssuerController::guardEnvironment()).
if (app()->environment(['local', 'testing'])) {
    Route::prefix('_sso-simulator')->group(function (): void {
        Route::get('/.well-known/openid-configuration', [FakeOidcIssuerController::class, 'discovery'])
            ->name('sso-simulator.discovery');
        Route::get('/authorize', [FakeOidcIssuerController::class, 'authorize'])
            ->name('sso-simulator.authorize');
        Route::post('/token', [FakeOidcIssuerController::class, 'token'])
            ->name('sso-simulator.token');
        Route::get('/userinfo', [FakeOidcIssuerController::class, 'userinfo'])
            ->name('sso-simulator.userinfo');
    });

    // REQ-AUTH-004 (1.4c), operacion.md §G.10.3, CA-AUTH-366: la ruta del
    // IdP SAML simulado, primera de las dos barreras contra que llegue a
    // producción (la segunda es la guarda de arranque en el propio
    // controlador). Bajo el mismo prefijo de plataforma que el emisor
    // OIDC, con su propio sub-prefijo para no colisionar de nombres.
    Route::prefix('_sso-simulator/saml')->group(function (): void {
        Route::get('/metadata', [FakeSamlIdentityProviderController::class, 'metadata'])
            ->name('sso-simulator.saml.metadata');
        Route::get('/sso', [FakeSamlIdentityProviderController::class, 'sso'])
            ->name('sso-simulator.saml.sso');
    });
}

// ADR-014/ADR-033 §2: toda ruta de negocio, sin excepción, resuelve tenant
// antes de tocar datos. /health queda fuera a propósito (healthcheck del
// contenedor, sin subdominio de tenant).
//
// REQ-AUTH/api.md §8: orden fijado, un intercambio de dos posiciones aquí
// es un fallo de seguridad silencioso.
//   1. AssignRequestId       — global, prependToGroup('api') en bootstrap/app.php
//   2. resolve-tenant        — antes de sesión y de cualquier acceso a datos
//   3. encrypt-cookies       — antes de leer/escribir cualquier cookie cifrada
//   4. add-queued-cookies    — framework
//   5. start-session         — la sesión pertenece a un tenant ya resuelto
//   6. csrf                  — después de la sesión, de donde sale el token
//   7. verify-session-tenant — RN-AUTH-31: tenant_id del payload vs. host
//   8. session-idle-timeout  — después de la reverificación
//   9. resolve-locale        — movido: ahora corre con $request->user() ya resuelto
//  10. require-mfa-enrollment — REQ-AUTH-003 (1.3), funcional.md §C.4.9: el
//      muro de alta. Después de resolve-locale para que su propio 403 ya
//      salga traducido; sin efecto sobre peticiones sin sesión.
Route::prefix('v1')->middleware([
    'resolve-tenant',
    'encrypt-cookies',
    'add-queued-cookies',
    'start-session',
    'csrf',
    'verify-session-tenant',
    'session-idle-timeout',
    'resolve-locale',
    'require-mfa-enrollment',
])->group(function (): void {
    require base_path('routes/api-v1.php');
});

// REQ-AUTH-004 (1.4c), api.md §G.7.1, funcional.md §G.3.2, RN-AUTH-124.
// El ACS: la ÚNICA ruta de la aplicación entera sin `csrf`, y por eso NO
// vive dentro del grupo de arriba con una exención — es un grupo propio,
// con su propia pila declarada explícitamente, para que el alcance de la
// excepción se lea de un vistazo y no dependa de una lista global de
// exenciones (que, deliberadamente, no existe en ningún sitio de esta
// aplicación — CA-AUTH-346).
//
// La pila es la de arriba MENOS `csrf`, `session-idle-timeout`,
// `resolve-locale` y `require-mfa-enrollment`: ninguno de los cuatro
// tiene sentido sobre una petición que por diseño llega sin sesión
// (`ADR-043 §2.1`). `verify-session-tenant` se mantiene: sobre sesión
// vacía no hace nada, y si por lo que fuera llegara una sesión,
// `RN-AUTH-31` debe seguir aplicando — quitarlo "porque no hace falta"
// sería el intercambio de posiciones que el bloque de arriba advierte
// que es un fallo de seguridad silencioso.
Route::prefix('v1')->middleware([
    'resolve-tenant',
    'encrypt-cookies',
    'add-queued-cookies',
    'start-session',
    'verify-session-tenant',
])->group(function (): void {
    Route::post('/auth/saml/{publicId}/acs', SamlAcsController::class)
        ->name('auth.saml.acs');
});

// REQ-BO (1.6), ADR-046 §4.1, api.md §0/§1.1. Grupo hermano de
// `/api/v1`, sin `resolve-tenant`: no lleva ninguno de los tres
// middleware de tenant (`resolve-tenant`, `verify-session-tenant`,
// `require-mfa-enrollment` — el backoffice tiene los suyos propios en
// los puestos 1, 6 y 8), verificado por `RunAsPlatformArchitectureTest`
// (CA-BO-011, CA-BO-013 a CA-BO-015). Pila completa y en orden,
// api.md §1.1:
//   1. require-platform-host             — 404 antes de sesión y credenciales
//   2. enforce-platform-ip-allowlist     — 403 antes de tocar credenciales
//   3. encrypt-cookies
//   4. add-queued-cookies
//   5. configure-platform-session        — fija el almacén ANTES de start-session
//   6. start-session
//   7. csrf
//   8. require-platform-session-idle-timeout — caducidad de RN-BO-09
//   9. resolve-platform-locale
//  10. require-platform-mfa              — por ruta, sin gracia y sin
//                                           lista de excepciones (issue
//                                           #174, OPEN-BO-13): :exento o
//                                           enforcing por defecto
//  11. require-platform-capability       — por ruta, con :identity o :<capacidad>
//
// Los puestos 10 y 11 se declaran EN CADA RUTA de
// `Modules/Backoffice/Http/routes.php`, no en este array: los dos
// conocen su propia excepción por parámetro de ruta, mismo patrón para
// los dos — ninguna lista de nombres de ruta que mantener aparte.
Route::prefix('platform/v1')->middleware([
    'require-platform-host',
    'enforce-platform-ip-allowlist',
    'encrypt-cookies',
    'add-queued-cookies',
    'configure-platform-session',
    'start-session',
    'csrf',
    'require-platform-session-idle-timeout',
    'resolve-platform-locale',
])->group(function (): void {
    require app_path('Modules/Backoffice/Http/routes.php');
});
