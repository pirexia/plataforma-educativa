<?php

// Rutas de REQ-AUTH (paso 1.2), documentadas en
// apps/api/openapi/paths/auth.yaml. Requerido desde routes/api-v1.php, ya
// dentro del grupo con la cadena de middleware de sesión (api.md §8).
//
// api.md §7: diez endpoints, seis anónimos. Ninguno lleva `module-enabled`
// (REQ-AUTH no es desactivable, RN-AUTH-35, CA-AUTH-078).

use App\Modules\Auth\Http\Controllers\AccountLockoutsController;
use App\Modules\Auth\Http\Controllers\AccountUnlocksController;
use App\Modules\Auth\Http\Controllers\InvitationRedemptionsController;
use App\Modules\Auth\Http\Controllers\MfaChallengesController;
use App\Modules\Auth\Http\Controllers\MfaComplianceController;
use App\Modules\Auth\Http\Controllers\MfaEnrollmentsController;
use App\Modules\Auth\Http\Controllers\MfaFactorsController;
use App\Modules\Auth\Http\Controllers\MfaRecoveryCodesController;
use App\Modules\Auth\Http\Controllers\MfaResetsController;
use App\Modules\Auth\Http\Controllers\MfaStatusController;
use App\Modules\Auth\Http\Controllers\MfaVerificationsController;
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

Route::post('/auth/mfa-verifications', [MfaVerificationsController::class, 'store'])
    ->name('auth.mfa-verifications.store');

// §C.4.10, §C.1.1 punto 9: administración. Permisos propios (mfa.leer,
// mfa.eliminar), declarados en AuthServiceProvider.
Route::get('/mfa-compliance', [MfaComplianceController::class, 'index'])
    ->middleware('permission:mfa.leer')
    ->name('auth.mfa-compliance.index');

Route::post('/mfa-resets', [MfaResetsController::class, 'store'])
    ->middleware('permission:mfa.eliminar')
    ->name('auth.mfa-resets.store');
