<?php

use App\Http\Controllers\HealthController;
use App\Modules\Auth\Infrastructure\FakeOidcIssuerController;
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
