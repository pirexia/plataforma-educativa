<?php

use App\Modules\Backoffice\Http\Controllers\AdminActionLogsController;
use App\Modules\Backoffice\Http\Controllers\DualAuthorizationsController;
use App\Modules\Backoffice\Http\Controllers\FeatureFlagsController;
use App\Modules\Backoffice\Http\Controllers\ModulesController;
use App\Modules\Backoffice\Http\Controllers\PlatformAdminInvitationRedemptionsController;
use App\Modules\Backoffice\Http\Controllers\PlatformAdminsController;
use App\Modules\Backoffice\Http\Controllers\PlatformIpAllowlistController;
use App\Modules\Backoffice\Http\Controllers\PlatformMeController;
use App\Modules\Backoffice\Http\Controllers\PlatformMetricsController;
use App\Modules\Backoffice\Http\Controllers\PlatformMfaFactorsController;
use App\Modules\Backoffice\Http\Controllers\PlatformMfaRecoveryCodesController;
use App\Modules\Backoffice\Http\Controllers\PlatformReauthenticationController;
use App\Modules\Backoffice\Http\Controllers\PlatformSessionController;
use App\Modules\Backoffice\Http\Controllers\TenantHealthController;
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
    ->middleware(['require-platform-mfa', 'require-platform-capability:autorizacion.leer', 'require-platform-reauthentication'])->name('platform.dual-authorizations.approval.store');
Route::post('/dual-authorizations/{public_id}/rejection', [DualAuthorizationsController::class, 'reject'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:autorizacion.leer', 'require-platform-reauthentication'])->name('platform.dual-authorizations.rejection.store');

// §2.6, §2.7 (1.6c, REQ-BO-002). La reautenticación de `PUT …/modules/
// {code}` sólo aplica con `enabled: false` (OPEN-BO-17) y la comprueba
// el controlador, no una declaración estática de middleware — mismo
// criterio que `transitions()` de TenantsController.
Route::get('/modules', [ModulesController::class, 'index'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:modulo.leer'])->name('platform.modules.index');
Route::get('/tenants/{public_id}/modules', [ModulesController::class, 'forTenant'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:modulo.leer'])->name('platform.tenants.modules.index');
Route::post('/tenants/{public_id}/modules/preview', [ModulesController::class, 'preview'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:modulo.leer'])->name('platform.tenants.modules.preview.store');
Route::put('/tenants/{public_id}/modules/{module_code}', [ModulesController::class, 'update'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:modulo.contratar'])->name('platform.tenants.modules.update');
Route::post('/module-rollouts/preview', [ModulesController::class, 'rolloutsPreview'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:modulo.leer'])->name('platform.module-rollouts.preview.store');
Route::post('/module-rollouts', [ModulesController::class, 'rollouts'])
    ->middleware([
        'require-platform-mfa',
        'require-platform-capability:modulo.contratar_masivo',
        'require-platform-reauthentication',
        'idempotent-platform:bo.module-rollouts.store',
    ])->name('platform.module-rollouts.store');

// §2.10, §2.10.1 a §2.10.5 (1.6d, REQ-BO-004/REQ-BO-006 reducidos). Ficha
// de salud, trabajos fallidos del centro (capacidad salud.leer, sólo
// lectura) y su único reintento (job.reintentar, escritura, sensible —
// OPEN-BO-21). Las dos métricas agregadas (metrica.leer) corren dentro
// de runAsPlatform(BackofficeLectura, …) — RN-BO-94 — así que no hace
// falta ningún parámetro de tenant en su ruta.
Route::get('/tenants/{public_id}/health', [TenantHealthController::class, 'show'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:salud.leer'])->name('platform.tenants.health.show');
Route::get('/tenants/{public_id}/failed-jobs', [TenantHealthController::class, 'failedJobs'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:salud.leer'])->name('platform.tenants.failed-jobs.index');
Route::post('/tenants/{public_id}/failed-jobs/{uuid}/retry', [TenantHealthController::class, 'retry'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:job.reintentar', 'require-platform-reauthentication'])
    ->name('platform.tenants.failed-jobs.retry.store');

Route::get('/metrics/platform', [PlatformMetricsController::class, 'platform'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:metrica.leer'])->name('platform.metrics.platform.show');
Route::get('/metrics/module-adoption', [PlatformMetricsController::class, 'moduleAdoption'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:metrica.leer'])->name('platform.metrics.module-adoption.show');

// §2.11-§2.14 (1.6e, REQ-BO-005 puntos 1-2). `{key}` se direcciona por la
// clave del flag y no por `public_id` (`ADR-051`, `OPEN-BO-11`): el
// formato admite puntos (`datos.md §9.1`), así que la ruta necesita su
// propia restricción (`ADR-051 §2` condición C4) o Laravel no encajaría
// `comedor.reserva_v2` como un solo segmento. Registrado en
// FeatureFlagKeyRoutes::REGISTRY (ADR-051 §5.1).
$flagKeyPattern = '[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*';

Route::get('/feature-flags', [FeatureFlagsController::class, 'index'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:flag.leer'])->name('platform.feature-flags.index');
Route::get('/feature-flags/{key}', [FeatureFlagsController::class, 'show'])
    ->where('key', $flagKeyPattern)
    ->middleware(['require-platform-mfa', 'require-platform-capability:flag.leer'])->name('platform.feature-flags.show');
Route::post('/feature-flags/{key}/rules/preview', [FeatureFlagsController::class, 'rulesPreview'])
    ->where('key', $flagKeyPattern)
    ->middleware(['require-platform-mfa', 'require-platform-capability:flag.leer'])->name('platform.feature-flags.rules.preview.store');
// api.md §2.12 punto 2: apagar (`forced_off`) va sin fricción; encender
// (`activo`) exige reautenticación — lo comprueba el controlador, no una
// declaración estática (mismo criterio que `ModulesController::update()`).
Route::put('/feature-flags/{key}/state', [FeatureFlagsController::class, 'updateState'])
    ->where('key', $flagKeyPattern)
    ->middleware(['require-platform-mfa', 'require-platform-capability:flag.gestionar'])->name('platform.feature-flags.state.update');
// Siempre sensible (api.md §2.12 punto 1, §4): la precedencia de
// `funcional.md §5.11.5` es del conjunto completo.
Route::put('/feature-flags/{key}/rules', [FeatureFlagsController::class, 'updateRules'])
    ->where('key', $flagKeyPattern)
    ->middleware(['require-platform-mfa', 'require-platform-capability:flag.gestionar', 'require-platform-reauthentication'])->name('platform.feature-flags.rules.update');

Route::get('/tenants/{public_id}/feature-flags', [FeatureFlagsController::class, 'forTenant'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:flag.leer'])->name('platform.tenants.feature-flags.index');
// api.md §2.11 nota, permisos.md §4.6 decisión 3: `tenant.actualizar`, no
// `flag.gestionar` — es un atributo del centro, no una regla de
// despliegue. No sensible (api.md §4, RN-BO-109).
Route::put('/tenants/{public_id}/early-adopter', [FeatureFlagsController::class, 'updateEarlyAdopter'])
    ->middleware(['require-platform-mfa', 'require-platform-capability:tenant.actualizar'])->name('platform.tenants.early-adopter.update');
