<?php

use App\Modules\Backoffice\Http\Controllers\AdminActionLogsController;
use App\Modules\Backoffice\Http\Controllers\PlatformAdminsController;
use App\Modules\Backoffice\Http\Controllers\PlatformIpAllowlistController;
use App\Modules\Backoffice\Http\Controllers\PlatformMeController;
use App\Modules\Backoffice\Http\Controllers\PlatformMfaFactorsController;
use App\Modules\Backoffice\Http\Controllers\PlatformMfaRecoveryCodesController;
use App\Modules\Backoffice\Http\Controllers\PlatformReauthenticationController;
use App\Modules\Backoffice\Http\Controllers\PlatformSessionController;
use Illuminate\Support\Facades\Route;

/**
 * api.md §2. Incluido desde `routes/api.php` bajo el grupo `/api/platform/
 * v1`, que ya lleva la pila completa de §1.1 — aquí solo se declaran las
 * rutas y, ruta a ruta, la capacidad de `require-platform-capability` (o
 * `identity` cuando se autoriza por identidad del portador, §1.1.1) y
 * `require-platform-reauthentication` en las operaciones sensibles de
 * §4.
 */

// §2.1. Únicas rutas alcanzables sin factor confirmado (RN-BO-05).
Route::get('/csrf-cookie', [PlatformSessionController::class, 'csrfCookie'])
    ->middleware('require-platform-capability:identity')->name('platform.csrf-cookie');
Route::post('/auth/session', [PlatformSessionController::class, 'store'])
    ->middleware('require-platform-capability:identity')->name('platform.auth.session.store');
Route::post('/auth/session/mfa', [PlatformSessionController::class, 'storeMfa'])
    ->middleware('require-platform-capability:identity')->name('platform.auth.session.mfa.store');
Route::delete('/auth/session', [PlatformSessionController::class, 'destroy'])
    ->middleware('require-platform-capability:identity')->name('platform.auth.session.destroy');
Route::post('/auth/reauthenticate', [PlatformReauthenticationController::class, 'store'])
    ->middleware('require-platform-capability:identity')->name('platform.auth.reauthenticate.store');
Route::get('/me', [PlatformMeController::class, 'show'])
    ->middleware('require-platform-capability:identity')->name('platform.me.show');

Route::post('/mfa/factors', [PlatformMfaFactorsController::class, 'store'])
    ->middleware('require-platform-capability:identity')->name('platform.mfa.factors.store');
Route::post('/mfa/factors/{public_id}/confirm', [PlatformMfaFactorsController::class, 'confirm'])
    ->middleware('require-platform-capability:identity')->name('platform.mfa.factors.confirm');
Route::get('/mfa/recovery-codes', [PlatformMfaRecoveryCodesController::class, 'index'])
    ->middleware('require-platform-capability:identity')->name('platform.mfa.recovery-codes.index');

// §2.2. Sólo `superadministrador` (permisos.md §4.1).
Route::get('/admins', [PlatformAdminsController::class, 'index'])
    ->middleware('require-platform-capability:admin.leer')->name('platform.admins.index');
Route::post('/admins', [PlatformAdminsController::class, 'store'])
    ->middleware(['require-platform-capability:admin.crear', 'require-platform-reauthentication'])
    ->name('platform.admins.store');
Route::get('/admins/{public_id}', [PlatformAdminsController::class, 'show'])
    ->middleware('require-platform-capability:admin.leer')->name('platform.admins.show');
Route::patch('/admins/{public_id}', [PlatformAdminsController::class, 'update'])
    ->middleware('require-platform-capability:admin.actualizar')->name('platform.admins.update');
Route::put('/admins/{public_id}/roles', [PlatformAdminsController::class, 'updateRoles'])
    ->middleware('require-platform-capability:admin.rol.gestionar')->name('platform.admins.roles.update');
Route::post('/admins/{public_id}/status', [PlatformAdminsController::class, 'updateStatus'])
    ->middleware('require-platform-capability:admin.actualizar')->name('platform.admins.status.update');
Route::delete('/admins/{public_id}', [PlatformAdminsController::class, 'destroy'])
    ->middleware(['require-platform-capability:admin.eliminar', 'require-platform-reauthentication'])
    ->name('platform.admins.destroy');
Route::delete('/admins/{public_id}/mfa', [PlatformAdminsController::class, 'destroyMfa'])
    ->middleware(['require-platform-capability:admin.mfa.restablecer', 'require-platform-reauthentication'])
    ->name('platform.admins.mfa.destroy');

// §2.3.
Route::get('/ip-allowlist', [PlatformIpAllowlistController::class, 'index'])
    ->middleware('require-platform-capability:ip_allowlist.leer')->name('platform.ip-allowlist.index');
Route::post('/ip-allowlist', [PlatformIpAllowlistController::class, 'store'])
    ->middleware(['require-platform-capability:ip_allowlist.gestionar', 'require-platform-reauthentication'])
    ->name('platform.ip-allowlist.store');
Route::delete('/ip-allowlist/{public_id}', [PlatformIpAllowlistController::class, 'destroy'])
    ->middleware(['require-platform-capability:ip_allowlist.gestionar', 'require-platform-reauthentication'])
    ->name('platform.ip-allowlist.destroy');

// §2.9.
Route::get('/admin-action-logs', [AdminActionLogsController::class, 'index'])
    ->middleware('require-platform-capability:auditoria_plataforma.leer')->name('platform.admin-action-logs.index');
Route::get('/tenants/{public_id}/admin-action-logs', [AdminActionLogsController::class, 'forTenant'])
    ->middleware('require-platform-capability:tenant.leer')->name('platform.tenants.admin-action-logs.index');
