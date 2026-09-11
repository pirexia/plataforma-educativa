<?php

use App\Modules\Backoffice\Http\Controllers\AdminActionLogsController;
use App\Modules\Backoffice\Http\Controllers\DualAuthorizationsController;
use App\Modules\Backoffice\Http\Controllers\PlatformAdminInvitationRedemptionsController;
use App\Modules\Backoffice\Http\Controllers\PlatformAdminsController;
use App\Modules\Backoffice\Http\Controllers\PlatformIpAllowlistController;
use App\Modules\Backoffice\Http\Controllers\PlatformMeController;
use App\Modules\Backoffice\Http\Controllers\PlatformMfaFactorsController;
use App\Modules\Backoffice\Http\Controllers\PlatformMfaRecoveryCodesController;
use App\Modules\Backoffice\Http\Controllers\PlatformReauthenticationController;
use App\Modules\Backoffice\Http\Controllers\PlatformSessionController;
use App\Modules\Backoffice\Http\Controllers\TenantsController;
use Illuminate\Support\Facades\Route;

/**
 * api.md §2. Incluido desde `routes/api.php` bajo el grupo `/api/platform/
 * v1`, que ya lleva la pila completa de §1.1 — aquí solo se declaran las
 * rutas y, ruta a ruta, la capacidad de `require-platform-capability` (o
 * `identity` cuando se autoriza por identidad del portador, §1.1.1) y
 * `require-platform-reauthentication` en las operaciones sensibles de
 * §4.
 *
 * Issue #174 (`OPEN-BO-13`): `require-platform-mfa` se declara también
 * ruta a ruta, igual que `require-platform-capability` — sin `:exento`
 * exige factor confirmado (puesto 8 de `api.md §1.1`); con `:exento` es
 * una de las rutas que crean/resuelven la sesión o dan de alta el propio
 * factor. Ninguna lista de nombres de ruta que mantener aparte.
 */

// §2.1. Únicas rutas alcanzables sin factor confirmado (RN-BO-05).
Route::get('/csrf-cookie', [PlatformSessionController::class, 'csrfCookie'])
    ->middleware(['require-platform-mfa:exento', 'require-platform-capability:identity'])->name('platform.csrf-cookie');
Route::post('/auth/session', [PlatformSessionController::class, 'store'])
    ->middleware(['require-platform-mfa:exento', 'require-platform-capability:identity'])->name('platform.auth.session.store');
Route::post('/auth/session/mfa', [PlatformSessionController::class, 'storeMfa'])
    ->middleware(['require-platform-mfa:exento', 'require-platform-capability:identity'])->name('platform.auth.session.mfa.store');
Route::delete('/auth/session', [PlatformSessionController::class, 'destroy'])
    ->middleware(['require-platform-mfa:exento', 'require-platform-capability:identity'])->name('platform.auth.session.destroy');
// Issue #173. Canje de la invitación de un platform_admin: anónimo,
// autorizado por posesión del token, mismo criterio que las cuatro rutas
// de arriba — no hay sujeto todavía.
Route::post('/admin-invitation-redemptions', [PlatformAdminInvitationRedemptionsController::class, 'store'])
    ->middleware(['require-platform-mfa:exento', 'require-platform-capability:identity'])->name('platform.admin-invitation-redemptions.store');
// Ya autenticada y ya con MFA superado (es su propósito): NO exenta de
// MFA, a diferencia de las cinco de arriba (issue #174, verificado
// explícitamente contra el discrepancia encontrada en la lista anterior).
Route::post('/auth/reauthenticate', [PlatformReauthenticationController::class, 'store'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:identity'])->name('platform.auth.reauthenticate.store');
Route::get('/me', [PlatformMeController::class, 'show'])
    ->middleware(['require-platform-mfa:exento', 'require-platform-capability:identity'])->name('platform.me.show');

Route::post('/mfa/factors', [PlatformMfaFactorsController::class, 'store'])
    ->middleware(['require-platform-mfa:exento', 'require-platform-capability:identity'])->name('platform.mfa.factors.store');
Route::post('/mfa/factors/{public_id}/confirm', [PlatformMfaFactorsController::class, 'confirm'])
    ->middleware(['require-platform-mfa:exento', 'require-platform-capability:identity'])->name('platform.mfa.factors.confirm');
Route::get('/mfa/recovery-codes', [PlatformMfaRecoveryCodesController::class, 'index'])
    ->middleware(['require-platform-mfa:exento', 'require-platform-capability:identity'])->name('platform.mfa.recovery-codes.index');

// §2.2. Sólo `superadministrador` (permisos.md §4.1).
Route::get('/admins', [PlatformAdminsController::class, 'index'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:admin.leer'])->name('platform.admins.index');
Route::post('/admins', [PlatformAdminsController::class, 'store'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:admin.crear', 'require-platform-reauthentication'])
    ->name('platform.admins.store');
Route::get('/admins/{public_id}', [PlatformAdminsController::class, 'show'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:admin.leer'])->name('platform.admins.show');
Route::patch('/admins/{public_id}', [PlatformAdminsController::class, 'update'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:admin.actualizar'])->name('platform.admins.update');
Route::put('/admins/{public_id}/roles', [PlatformAdminsController::class, 'updateRoles'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:admin.rol.gestionar'])->name('platform.admins.roles.update');
Route::post('/admins/{public_id}/status', [PlatformAdminsController::class, 'updateStatus'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:admin.actualizar'])->name('platform.admins.status.update');
Route::delete('/admins/{public_id}', [PlatformAdminsController::class, 'destroy'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:admin.eliminar', 'require-platform-reauthentication'])
    ->name('platform.admins.destroy');
Route::delete('/admins/{public_id}/mfa', [PlatformAdminsController::class, 'destroyMfa'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:admin.mfa.restablecer', 'require-platform-reauthentication'])
    ->name('platform.admins.mfa.destroy');

// §2.3.
Route::get('/ip-allowlist', [PlatformIpAllowlistController::class, 'index'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:ip_allowlist.leer'])->name('platform.ip-allowlist.index');
Route::post('/ip-allowlist', [PlatformIpAllowlistController::class, 'store'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:ip_allowlist.gestionar', 'require-platform-reauthentication'])
    ->name('platform.ip-allowlist.store');
Route::delete('/ip-allowlist/{public_id}', [PlatformIpAllowlistController::class, 'destroy'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:ip_allowlist.gestionar', 'require-platform-reauthentication'])
    ->name('platform.ip-allowlist.destroy');

// §2.9.
Route::get('/admin-action-logs', [AdminActionLogsController::class, 'index'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:auditoria_plataforma.leer'])->name('platform.admin-action-logs.index');
Route::get('/tenants/{public_id}/admin-action-logs', [AdminActionLogsController::class, 'forTenant'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.leer'])->name('platform.tenants.admin-action-logs.index');

// §2.4, §2.4.1 a §2.4.3, §2.5 (1.6b, REQ-BO-001). `tenant.leer` es la
// línea de base de `transitions`: la capacidad exacta depende de
// `to_status` y la resuelve `TenantTransitionCapability` dentro del
// servicio (permisos.md §4.3) — no hay un único valor estático que
// describa las cinco aristas.
Route::get('/tenants', [TenantsController::class, 'index'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.leer'])->name('platform.tenants.index');
Route::post('/tenants', [TenantsController::class, 'store'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.crear', 'require-platform-reauthentication'])
    ->name('platform.tenants.store');
Route::get('/tenants/{public_id}', [TenantsController::class, 'show'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.leer'])->name('platform.tenants.show');
Route::patch('/tenants/{public_id}', [TenantsController::class, 'update'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.actualizar'])->name('platform.tenants.update');
Route::post('/tenants/{public_id}/transitions', [TenantsController::class, 'transitions'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.leer'])->name('platform.tenants.transitions.store');
Route::get('/tenants/{public_id}/lifecycle-events', [TenantsController::class, 'lifecycleEvents'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.leer'])->name('platform.tenants.lifecycle-events.index');
Route::post('/tenants/{public_id}/clone', [TenantsController::class, 'clone'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.crear', 'require-platform-reauthentication'])
    ->name('platform.tenants.clone.store');
Route::post('/tenants/{public_id}/slug', [TenantsController::class, 'updateSlug'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.actualizar', 'require-platform-reauthentication'])
    ->name('platform.tenants.slug.store');

// §2.8 (1.6b, REQ-BO-007). `autorizacion.leer` es la línea de base de
// aprobación/rechazo: la capacidad exacta es la de la acción autorizada
// (permisos.md §5.2), resuelta dentro de `DualAuthorizationService`.
Route::get('/dual-authorizations', [DualAuthorizationsController::class, 'index'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:autorizacion.leer'])->name('platform.dual-authorizations.index');
Route::get('/dual-authorizations/{public_id}', [DualAuthorizationsController::class, 'show'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:autorizacion.leer'])->name('platform.dual-authorizations.show');
Route::post('/dual-authorizations/{public_id}/approval', [DualAuthorizationsController::class, 'approve'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:autorizacion.leer'])->name('platform.dual-authorizations.approval.store');
Route::post('/dual-authorizations/{public_id}/rejection', [DualAuthorizationsController::class, 'reject'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:autorizacion.leer'])->name('platform.dual-authorizations.rejection.store');
