<?php

// Rutas de REQ-AUTH (paso 1.2), documentadas en
// apps/api/openapi/paths/auth.yaml. Requerido desde routes/api-v1.php, ya
// dentro del grupo con la cadena de middleware de sesión (api.md §8).
//
// api.md §7: diez endpoints, seis anónimos. Ninguno lleva `module-enabled`
// (REQ-AUTH no es desactivable, RN-AUTH-35, CA-AUTH-078).

use App\Modules\Auth\Http\Controllers\AccountLockoutsController;
use App\Modules\Auth\Http\Controllers\AccountUnlocksController;
use App\Modules\Auth\Http\Controllers\FakeGoogleAuthorizationController;
use App\Modules\Auth\Http\Controllers\IdentitiesController;
use App\Modules\Auth\Http\Controllers\IdentityProviderDiscoveryRefreshesController;
use App\Modules\Auth\Http\Controllers\IdentityProvidersAdminController;
use App\Modules\Auth\Http\Controllers\IdentityProvidersController;
use App\Modules\Auth\Http\Controllers\IdentityProviderSecretsController;
use App\Modules\Auth\Http\Controllers\InvitationRedemptionsController;
use App\Modules\Auth\Http\Controllers\MfaChallengesController;
use App\Modules\Auth\Http\Controllers\MfaComplianceController;
use App\Modules\Auth\Http\Controllers\MfaEnrollmentsController;
use App\Modules\Auth\Http\Controllers\MfaExemptionsController;
use App\Modules\Auth\Http\Controllers\MfaFactorsController;
use App\Modules\Auth\Http\Controllers\MfaRecoveryCodesController;
use App\Modules\Auth\Http\Controllers\MfaResetsController;
use App\Modules\Auth\Http\Controllers\MfaStatusController;
use App\Modules\Auth\Http\Controllers\MfaVerificationsController;
use App\Modules\Auth\Http\Controllers\OAuthAuthorizationsController;
use App\Modules\Auth\Http\Controllers\OAuthCallbackController;
use App\Modules\Auth\Http\Controllers\OidcCallbackController;
use App\Modules\Auth\Http\Controllers\PasswordChangesController;
use App\Modules\Auth\Http\Controllers\PasswordResetRequestsController;
use App\Modules\Auth\Http\Controllers\PasswordResetsController;
use App\Modules\Auth\Http\Controllers\SessionController;
use App\Modules\Auth\Http\Controllers\UserSessionsController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/csrf-cookie', [SessionController::class, 'csrfCookie'])->name('auth.csrf-cookie');
Route::post('/auth/session', [SessionController::class, 'store'])->name('auth.session.store');
Route::delete('/auth/session', [SessionController::class, 'destroy'])->name('auth.session.destroy');

Route::post('/auth/invitation-redemptions', [InvitationRedemptionsController::class, 'store'])
    ->name('auth.invitation-redemptions.store');

Route::post('/auth/password-reset-requests', [PasswordResetRequestsController::class, 'store'])
    ->name('auth.password-reset-requests.store');

Route::post('/auth/password-resets', [PasswordResetsController::class, 'store'])
    ->name('auth.password-resets.store');

Route::post('/auth/account-unlocks', [AccountUnlocksController::class, 'store'])
    ->name('auth.account-unlocks.store');

// funcional.md §4.8, OPEN-AUTH-05 aprobado: por identidad, sin permiso.
Route::post('/auth/password-changes', [PasswordChangesController::class, 'store'])
    ->name('auth.password-changes.store');

Route::get('/account-lockouts', [AccountLockoutsController::class, 'index'])
    ->middleware('permission:bloqueo_cuenta.leer')
    ->name('auth.account-lockouts.index');

Route::delete('/account-lockouts/{publicId}', [AccountLockoutsController::class, 'destroy'])
    ->middleware('permission:bloqueo_cuenta.eliminar')
    ->name('auth.account-lockouts.destroy');

// 1.2b, api.md §B.1-§B.5: REQ-AUTH-005 puntos 2-3. Los tres, por
// identidad, sin permiso — igual que /auth/session y
// /auth/password-changes. Sin `module-enabled` (CA-AUTH-078).
Route::get('/auth/sessions', [UserSessionsController::class, 'index'])
    ->name('auth.sessions.index');

Route::delete('/auth/sessions/{publicId}', [UserSessionsController::class, 'destroy'])
    ->name('auth.sessions.destroy');

Route::delete('/auth/sessions', [UserSessionsController::class, 'destroyAll'])
    ->name('auth.sessions.destroy-all');

// REQ-AUTH-003 (1.3), api.md §C.1. Autoservicio del propio factor: sin
// permiso, por identidad — mismo criterio que /auth/password-changes y
// /auth/sessions. GET /auth/mfa y los dos de alta están en la lista
// blanca de RequireMfaEnrollment (§C.4.9); el resto no hace falta:
// solo se alcanzan ya autenticado y sin restricción de muro.
Route::get('/auth/mfa', [MfaStatusController::class, 'show'])
    ->name('auth.mfa.show');

Route::post('/auth/mfa-enrollments', [MfaEnrollmentsController::class, 'store'])
    ->name('auth.mfa-enrollments.store');

Route::post('/auth/mfa-factors', [MfaFactorsController::class, 'store'])
    ->name('auth.mfa-factors.store');

Route::delete('/auth/mfa-factors/{publicId}', [MfaFactorsController::class, 'destroy'])
    ->name('auth.mfa-factors.destroy');

Route::post('/auth/mfa-recovery-codes', [MfaRecoveryCodesController::class, 'store'])
    ->name('auth.mfa-recovery-codes.store');

// §C.4.4, §C.6: sin sesión autenticada — el titular se resuelve por el
// session_id del desafío (RN-AUTH-53), nunca por Auth::user().
Route::post('/auth/mfa-challenges', [MfaChallengesController::class, 'store'])
    ->name('auth.mfa-challenges.store');

// REQ-AUTH-002 (1.4), api.md §E.5b: GET añadido sobre el mismo recurso de
// 1.3 — estrictamente de lectura, lo necesita /entrar/google (RN-AUTH-93,
// el callback no lleva datos) y también el login local tras recargar
// /entrar a mitad del segundo paso. Mismo mecanismo de autorización que
// el POST de arriba: sin sesión autenticada, por session_id.
Route::get('/auth/mfa-challenges', [MfaChallengesController::class, 'show'])
    ->name('auth.mfa-challenges.show');

Route::post('/auth/mfa-verifications', [MfaVerificationsController::class, 'store'])
    ->name('auth.mfa-verifications.store');

// §C.4.10, §C.1.1 punto 9: administración. Permisos propios (mfa.leer,
// mfa.eliminar), declarados en AuthServiceProvider.
Route::get('/mfa-compliance', [MfaComplianceController::class, 'index'])
    ->middleware('permission:mfa.leer')
    ->name('auth.mfa-compliance.index');

// api.md §C.5. Restaurado en 1.3 el 2026-08-27 (decisión del usuario,
// corrige un recorte no autorizado a `1.3b`). Mismo permiso que el
// agregado (`§C.6.1` de permisos.md): no es `usuario.leer`.
Route::get('/mfa-compliance/users', [MfaComplianceController::class, 'users'])
    ->middleware('permission:mfa.leer')
    ->name('auth.mfa-compliance.users');

Route::post('/mfa-resets', [MfaResetsController::class, 'store'])
    ->middleware('permission:mfa.eliminar')
    ->name('auth.mfa-resets.store');

// REQ-AUTH-003 (1.3b), funcional.md §D.4.6-§D.4.8, api.md §D.4. Las tres
// excepciones temporales nominales, permisos propios (permisos.md §D.3).
Route::post('/mfa-exemptions', [MfaExemptionsController::class, 'store'])
    ->middleware('permission:exencion_mfa.crear')
    ->name('auth.mfa-exemptions.store');

Route::get('/mfa-exemptions', [MfaExemptionsController::class, 'index'])
    ->middleware('permission:exencion_mfa.leer')
    ->name('auth.mfa-exemptions.index');

Route::delete('/mfa-exemptions/{publicId}', [MfaExemptionsController::class, 'destroy'])
    ->middleware('permission:exencion_mfa.eliminar')
    ->name('auth.mfa-exemptions.destroy');

// REQ-AUTH-002 (1.4), api.md §E.2-§E.5. Los cinco endpoints del paso.
// Ninguno declara permiso (permisos.md §E.1) ni lleva `module-enabled`
// (RN-AUTH-35, CA-AUTH-231).

// §E.2: anónimo, tenant por host. Le dice a la pantalla de login si hay
// botón que pintar (RN-AUTH-98).
Route::get('/auth/identity-providers', [IdentityProvidersController::class, 'index'])
    ->name('auth.identity-providers.index');

// §E.3: anónimo con intent=login; por identidad con intent=link (la
// comprobación de sesión es de negocio, en OAuthAuthorizationService).
Route::post('/auth/oauth-authorizations', [OAuthAuthorizationsController::class, 'store'])
    ->name('auth.oauth-authorizations.store');

// §E.4: la única excepción real a ADR-038 del módulo — nunca
// problem+json, siempre 302 con un código de una lista cerrada
// (RN-AUTH-93). Autorizado por posesión de la sesión que arrancó el
// flujo (el `state`), no por CSRF: es una navegación de un tercero.
Route::get('/auth/oauth/google/callback', OAuthCallbackController::class)
    ->name('auth.oauth.google.callback');

// §E.5: autoservicio puro, por identidad. Funcionan con
// AUTH_OAUTH_DRIVER=none (operacion.md §E.1): gestionar un vínculo que
// ya existe no necesita proveedor.
Route::get('/auth/identities', [IdentitiesController::class, 'index'])
    ->name('auth.identities.index');

Route::delete('/auth/identities/{publicId}', [IdentitiesController::class, 'destroy'])
    ->name('auth.identities.destroy');

// REQ-AUTH-002 (1.4), operacion.md §E.10.3, CA-AUTH-230: la ruta del
// proveedor simulado NO se registra fuera de local/testing — la primera
// de las dos barreras contra que llegue a producción (la segunda es
// OAuthEnvironmentGuard, que aborta el arranque si AUTH_OAUTH_DRIVER=fake
// fuera de esos entornos). app()->environment(), no config('app.env'):
// se evalúa en el momento de cargar las rutas, con el entorno real ya
// resuelto por el framework.
if (app()->environment(['local', 'testing'])) {
    Route::get('/auth/oauth/fake/authorize', FakeGoogleAuthorizationController::class)
        ->name('auth.oauth.fake.authorize');
}

// REQ-AUTH-004 (1.4b), api.md §F.2-§F.7. Los nueve endpoints del paso.
// Ninguno lleva `module-enabled` (RN-AUTH-35, CA-AUTH-306) — tampoco los
// ocho de administración (funcional.md §F.10.1).

// §F.2-§F.3: catálogo del centro, autoservicio (ADR-043 §8.3). Permisos
// propios (permisos.md §F.3), los primeros del módulo desde 1.3b.
Route::get('/identity-providers', [IdentityProvidersAdminController::class, 'index'])
    ->middleware('permission:proveedor_identidad.leer')
    ->name('identity-providers.index');

Route::get('/identity-providers/{publicId}', [IdentityProvidersAdminController::class, 'show'])
    ->middleware('permission:proveedor_identidad.leer')
    ->name('identity-providers.show');

Route::post('/identity-providers', [IdentityProvidersAdminController::class, 'store'])
    ->middleware('permission:proveedor_identidad.crear')
    ->name('identity-providers.store');

Route::patch('/identity-providers/{publicId}', [IdentityProvidersAdminController::class, 'update'])
    ->middleware('permission:proveedor_identidad.actualizar')
    ->name('identity-providers.update');

Route::delete('/identity-providers/{publicId}', [IdentityProvidersAdminController::class, 'destroy'])
    ->middleware('permission:proveedor_identidad.eliminar')
    ->name('identity-providers.destroy');

// §F.4: las credenciales no tienen permiso propio (permisos.md §F.4).
Route::post('/identity-providers/{publicId}/secrets', [IdentityProviderSecretsController::class, 'store'])
    ->middleware('permission:proveedor_identidad.actualizar')
    ->name('identity-providers.secrets.store');

Route::delete('/identity-providers/{publicId}/secrets/{secretPublicId}', [IdentityProviderSecretsController::class, 'destroy'])
    ->middleware('permission:proveedor_identidad.actualizar')
    ->name('identity-providers.secrets.destroy');

// §F.5: síncrono, no encolado (INV-012 no lo exige).
Route::post('/identity-providers/{publicId}/discovery-refreshes', [IdentityProviderDiscoveryRefreshesController::class, 'store'])
    ->middleware('permission:proveedor_identidad.actualizar')
    ->name('identity-providers.discovery-refreshes.store');

// §F.7: una sola ruta para todos los proveedores catalogados del tenant
// (funcional.md §F.3.1). Autorizado por posesión de la sesión que
// arrancó el flujo (el `state`), no por permiso ni por CSRF.
Route::get('/auth/oauth/oidc/callback', OidcCallbackController::class)
    ->name('auth.oauth.oidc.callback');
