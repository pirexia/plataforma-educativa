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
use App\Modules\Auth\Http\Controllers\PasswordChangesController;
use App\Modules\Auth\Http\Controllers\PasswordResetRequestsController;
use App\Modules\Auth\Http\Controllers\PasswordResetsController;
use App\Modules\Auth\Http\Controllers\SessionController;
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
